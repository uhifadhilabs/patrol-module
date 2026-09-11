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

namespace Uhifadhi\Patrol\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Entity\TaxonomySubcategory;

/**
 * THE TWO WORDS EVERY PATROL FIXTURE NEEDS — a type and, sometimes, a station.
 *
 * A patrol points at records now rather than carrying strings, so a fixture that
 * wants "a walking round" has to have one in the area first. This finds it or
 * makes it, once per area, so a suite that builds twenty patrols does not build
 * twenty types and trip the unique index.
 *
 * The entity manager is optional: a UNIT test has none and only needs an object
 * with the right key on it, while an integration or functional test needs the
 * row persisted before the patrol referencing it is flushed.
 *
 * KEYED BY THE AREA IN A WeakMap, so nothing here outlives the test that made
 * it. A plain static array would hold every area — and through it every patrol,
 * observation and photograph the suite ever built — until the process ended.
 */
final class Vocabulary
{
    /**
     * The labels the suite's installation configures (see TestKernel's `patrol`
     * block). A fixture that made up its own would have the pages print words no
     * installation set, and the overview's sentences assert on these.
     *
     * @var array<string, string>
     */
    private const array LABELS = ['walk' => 'Walking round', 'boat' => 'Boat'];

    /** @var \WeakMap<AreaOfInterest, array{types: array<string, PatrolType>, stations: array<string, Station>}> */
    private static \WeakMap $known;

    public static function type(?EntityManagerInterface $em, AreaOfInterest $area, string $key, ?string $label = null): PatrolType
    {
        $known = self::forArea($area);
        $found = $known['types'][$key] ?? null;
        if ($found instanceof PatrolType) {
            return $found;
        }

        $type = new PatrolType($area, $key, $label ?? self::LABELS[$key] ?? ucfirst($key));
        $em?->persist($type);
        self::remember($area, $type);

        return $type;
    }

    public static function station(?EntityManagerInterface $em, AreaOfInterest $area, ?string $label, ?string $key = null): ?Station
    {
        if (null === $label || '' === $label) {
            return null;
        }

        $key ??= trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label)), '-');

        $known = self::forArea($area);
        $found = $known['stations'][$key] ?? null;
        if ($found instanceof Station) {
            return $found;
        }

        $station = new Station($area, $key, $label);
        $em?->persist($station);
        self::remember($area, $station);

        return $station;
    }

    /**
     * An observation kind with its sub-categories — the words PL·03's two chip
     * rows are drawn from.
     *
     * Codes are derived from the labels the way the taxonomy admin derives them,
     * so a fixture and the screen agree on what a chip submits.
     *
     * @param list<string> $subcategories
     */
    public static function kind(
        ?EntityManagerInterface $em,
        AreaOfInterest $area,
        string $label,
        array $subcategories = [],
    ): TaxonomyKind {
        $kind = new TaxonomyKind($area, self::codeOf($label), $label);
        $em?->persist($kind);

        foreach ($subcategories as $sub) {
            $em?->persist(new TaxonomySubcategory($kind, self::codeOf($sub), $sub));
        }

        return $kind;
    }

    private static function codeOf(string $label): string
    {
        return trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label)), '-');
    }

    /** @return array{types: array<string, PatrolType>, stations: array<string, Station>} */
    private static function forArea(AreaOfInterest $area): array
    {
        self::$known ??= new \WeakMap();

        return self::$known[$area] ?? ['types' => [], 'stations' => []];
    }

    private static function remember(AreaOfInterest $area, PatrolType|Station $record): void
    {
        /** @var array{types: array<string, PatrolType>, stations: array<string, Station>} $known */
        $known = self::forArea($area);
        if ($record instanceof PatrolType) {
            $known['types'][$record->getKey()] = $record;
        } else {
            $known['stations'][$record->getKey()] = $record;
        }
        self::$known[$area] = $known;
    }
}
