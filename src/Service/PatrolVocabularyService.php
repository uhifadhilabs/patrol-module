<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Exception\VocabularyConflictException;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;

/**
 * THE TWO WORD-LISTS AN AREA OWNS — its patrol types (SET·01) and its stations
 * (SET·03) — and every write either of them takes.
 *
 * ONE SERVICE FOR BOTH, and the reason is that they are one thing twice. Both
 * are a per-area list of {key, label, active, position}; both take exactly add,
 * rename, retire and reactivate; both enforce the same two rules (a label is
 * unique within the area, a key is born once and frozen); both are edited from
 * the SAME screen, in one section each, and both are resolved from a wire
 * string by the same handset sync. Two services would be this file twice with
 * one noun changed, and a rule tightened in one of them would silently not hold
 * in the other. The precedent is beside it: {@see TaxonomyAdminService} already
 * carries two levels of one vocabulary for the same reason.
 *
 * NOTHING IS EVER DELETED. Patrols are filed against a type and a station, and
 * a field record must never lose the words that describe it. Retirement flips a
 * flag; the row, its key and every patrol under it stay, and one click brings it
 * back.
 *
 * THE KEY IS BORN ONCE AND FROZEN. It is derived from the first label, made
 * unique within the area, and never touched again — which is what lets a saved
 * filter, an export column and an offline handset hold it across a rename.
 *
 * A WORD FROM THE FIELD THAT NOBODY CONFIGURED IS CREATED RETIRED, never
 * refused ({@see self::resolveStation()}). The sync contract names no error code
 * for an unknown station, and the same reasoning
 * {@see Api\PatrolUpsertService} states for an unknown
 * TYPE applies unchanged: refusing would throw away a real patrol because a
 * settings screen and an app build disagreed about a word, and a discarded
 * patrol is gone. Retired-on-arrival makes the disagreement VISIBLE on SET·03 —
 * dimmed, with its count — where an administrator either renames it into an
 * existing post or reactivates it.
 *
 * It owns the flush: each call is one discrete admin action behind an HTTP POST,
 * so "did it save?" is the whole question.
 */
final class PatrolVocabularyService
{
    /**
     * @param array<string, array{label: string}> $configuredTypes the installation's
     *                                                             patrol.types — the SEED a new area starts from, and nothing else
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolTypeRepository $types,
        private readonly StationRepository $stations,
        private readonly array $configuredTypes,
    ) {
    }

    // ── patrol types ──────────────────────────────────────────────────────────

    /** @throws VocabularyConflictException on a blank or duplicate label */
    public function addType(AreaOfInterest $area, string $label, string $key = ''): PatrolType
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw VocabularyConflictException::label('A patrol type needs a name.');
        }
        if ($this->types->labelExistsInArea($area, $label)) {
            throw VocabularyConflictException::label(\sprintf('This area already has a patrol type called "%s".', $label));
        }

        $type = new PatrolType($area, $this->freeTypeKey($area, '' !== $key ? $key : $label), $label);
        $type->setPosition($this->types->maxPositionByArea($area) + 1);

        $this->entityManager->persist($type);
        $this->entityManager->flush();

        return $type;
    }

    /** @throws VocabularyConflictException on a blank or duplicate label */
    public function renameType(PatrolType $type, string $label): PatrolType
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw VocabularyConflictException::label('A patrol type needs a name.');
        }
        if ($this->types->labelExistsInArea($type->getArea(), $label, $type)) {
            throw VocabularyConflictException::label(\sprintf('This area already has a patrol type called "%s".', $label));
        }

        $type->setLabel($label); // the key is deliberately untouched
        $this->entityManager->flush();

        return $type;
    }

    public function retireType(PatrolType $type): PatrolType
    {
        $type->deactivate();
        $this->entityManager->flush();

        return $type;
    }

    public function reactivateType(PatrolType $type): PatrolType
    {
        $type->reactivate();
        $this->entityManager->flush();

        return $type;
    }

    /**
     * The record behind a wire string — the key first, then the label, and
     * failing both a new RETIRED record. Never null: a patrol always has a type.
     */
    public function resolveType(AreaOfInterest $area, string $value): PatrolType
    {
        $value = $this->cleanLabel($value);
        if ('' === $value) {
            $value = 'unspecified';
        }

        $found = $this->types->findOneByAreaAndKey($area, $value) ?? $this->findTypeByLabel($area, $value);
        if (null !== $found) {
            return $found;
        }

        $type = new PatrolType($area, $this->freeTypeKey($area, $value), $value);
        $type->setPosition($this->types->maxPositionByArea($area) + 1)->deactivate();

        $this->entityManager->persist($type);
        $this->entityManager->flush();

        return $type;
    }

    /**
     * Give a NEW area the installation's configured types, and only a new one.
     *
     * This is the whole of what `patrol.types` is for now: the words an area
     * starts with, copied in once so it can then rename and retire them without
     * asking every other area's permission. An area that already has any type
     * is left exactly as it is, so a config change never reaches back into an
     * area somebody has curated.
     *
     * @return bool whether anything was written
     */
    public function seedTypes(AreaOfInterest $area): bool
    {
        if ([] !== $this->types->findByArea($area)) {
            return false;
        }

        $position = 0;
        foreach ($this->configuredTypes as $key => $type) {
            $seeded = new PatrolType($area, (string) $key, $type['label']);
            $seeded->setPosition($position++);
            $this->entityManager->persist($seeded);
        }

        if (0 === $position) {
            return false;
        }

        $this->entityManager->flush();

        return true;
    }

    // ── stations ──────────────────────────────────────────────────────────────

    /** @throws VocabularyConflictException on a blank or duplicate label */
    public function addStation(AreaOfInterest $area, string $label, string $key = ''): Station
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw VocabularyConflictException::label('A station needs a name.');
        }
        if ($this->stations->labelExistsInArea($area, $label)) {
            throw VocabularyConflictException::label(\sprintf('This area already has a station called "%s".', $label));
        }

        $station = new Station($area, $this->freeStationKey($area, '' !== $key ? $key : $label), $label);
        $station->setPosition($this->stations->maxPositionByArea($area) + 1);

        $this->entityManager->persist($station);
        $this->entityManager->flush();

        return $station;
    }

    /** @throws VocabularyConflictException on a blank or duplicate label */
    public function renameStation(Station $station, string $label): Station
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw VocabularyConflictException::label('A station needs a name.');
        }
        if ($this->stations->labelExistsInArea($station->getArea(), $label, $station)) {
            throw VocabularyConflictException::label(\sprintf('This area already has a station called "%s".', $label));
        }

        $station->setLabel($label);
        $this->entityManager->flush();

        return $station;
    }

    public function retireStation(Station $station): Station
    {
        $station->deactivate();
        $this->entityManager->flush();

        return $station;
    }

    public function reactivateStation(Station $station): Station
    {
        $station->reactivate();
        $this->entityManager->flush();

        return $station;
    }

    /**
     * The record behind a wire string — see the class docblock for why an
     * unknown one is CREATED RETIRED rather than refused. Null only where the
     * caller named no station at all, which is a real state: plenty of patrols
     * set off from nowhere in particular.
     */
    public function resolveStation(AreaOfInterest $area, ?string $value): ?Station
    {
        $value = $this->cleanLabel($value ?? '');
        if ('' === $value) {
            return null;
        }

        $found = $this->stations->findOneByAreaAndKey($area, $value) ?? $this->findStationByLabel($area, $value);
        if (null !== $found) {
            return $found;
        }

        $station = new Station($area, $this->freeStationKey($area, $value), $value);
        $station->setPosition($this->stations->maxPositionByArea($area) + 1)->deactivate();

        $this->entityManager->persist($station);
        $this->entityManager->flush();

        return $station;
    }

    /**
     * Give an area a starting set of stations, skipping any it already has.
     *
     * Unlike {@see self::resolveStation()} these arrive ACTIVE: they are words
     * somebody chose for this area, not words that turned up on a handset.
     *
     * @param list<string> $labels
     *
     * @return list<Station> the records, in the order the labels were given
     */
    public function seedStations(AreaOfInterest $area, array $labels): array
    {
        $seeded = [];
        foreach ($labels as $label) {
            $existing = $this->findStationByLabel($area, $this->cleanLabel($label));
            $seeded[] = $existing ?? $this->addStation($area, $label);
        }

        return $seeded;
    }

    // ── keys ──────────────────────────────────────────────────────────────────

    private function findTypeByLabel(AreaOfInterest $area, string $label): ?PatrolType
    {
        foreach ($this->types->findByArea($area) as $type) {
            if (mb_strtolower($type->getLabel()) === mb_strtolower($label)) {
                return $type;
            }
        }

        return null;
    }

    private function findStationByLabel(AreaOfInterest $area, string $label): ?Station
    {
        foreach ($this->stations->findByArea($area) as $station) {
            if (mb_strtolower($station->getLabel()) === mb_strtolower($label)) {
                return $station;
            }
        }

        return null;
    }

    private function freeTypeKey(AreaOfInterest $area, string $source): string
    {
        $base = $this->slug($source, 40, 'type');
        $key = $base;
        $n = 2;
        while (null !== $this->types->findOneByAreaAndKey($area, $key)) {
            $key = $this->slug($base.'-'.$n, 40, 'type');
            ++$n;
        }

        return $key;
    }

    private function freeStationKey(AreaOfInterest $area, string $source): string
    {
        $base = $this->slug($source, 60, 'station');
        $key = $base;
        $n = 2;
        while (null !== $this->stations->findOneByAreaAndKey($area, $key)) {
            $key = $this->slug($base.'-'.$n, 60, 'station');
            ++$n;
        }

        return $key;
    }

    /** A wire key from a label: lowercase, hyphen-joined, ascii-safe. */
    private function slug(string $value, int $limit, string $fallback): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        $value = mb_substr($value, 0, $limit);
        $value = trim($value, '-');

        return '' !== $value ? $value : $fallback;
    }

    private function cleanLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $label) ?? '');
    }
}
