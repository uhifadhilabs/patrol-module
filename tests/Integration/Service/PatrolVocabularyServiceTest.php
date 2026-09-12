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

namespace Uhifadhi\Patrol\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Exception\VocabularyConflictException;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE TWO WORD-LISTS THE TYPES AND STATIONS SECTIONS EDIT, PROVEN AGAINST THE REAL DATABASE:
 * add, rename, retire, reactivate — and never delete, because patrols are filed
 * against both.
 */
final class PatrolVocabularyServiceTest extends IntegrationTestCase
{
    private function vocabulary(): PatrolVocabularyService
    {
        /** @var PatrolVocabularyService $vocabulary */
        $vocabulary = $this->service(PatrolVocabularyService::class);

        return $vocabulary;
    }

    private function types(): PatrolTypeRepository
    {
        $repository = $this->em->getRepository(PatrolType::class);
        self::assertInstanceOf(PatrolTypeRepository::class, $repository);

        return $repository;
    }

    private function stations(): StationRepository
    {
        $repository = $this->em->getRepository(Station::class);
        self::assertInstanceOf(StationRepository::class, $repository);

        return $repository;
    }

    private function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name);
        $area->setSource('test fixture');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    // ── area scope ────────────────────────────────────────────────────────────

    public function testEachAreaOwnsItsOwnWords(): void
    {
        $first = $this->anArea('First Area');
        $second = $this->anArea('Second Area');

        $this->vocabulary()->addType($first, 'Foot patrol');
        $this->vocabulary()->addType($second, 'Foot patrol');

        self::assertCount(1, $this->types()->findByArea($first));
        self::assertCount(1, $this->types()->findByArea($second));
    }

    // ── types ─────────────────────────────────────────────────────────────────

    public function testAddingATypeSlugsItsKeyAndAppendsIt(): void
    {
        $area = $this->anArea();

        $first = $this->vocabulary()->addType($area, 'Foot patrol');
        $second = $this->vocabulary()->addType($area, 'Vehicle patrol');

        self::assertSame('foot-patrol', $first->getKey());
        self::assertSame(0, $first->getPosition());
        self::assertSame('vehicle-patrol', $second->getKey());
        self::assertSame(1, $second->getPosition());
        self::assertTrue($second->isActive());
    }

    public function testRenamingATypeLeavesItsKeyAlone(): void
    {
        $area = $this->anArea();
        $type = $this->vocabulary()->addType($area, 'Foot patrol');

        $this->vocabulary()->renameType($type, 'Foot');

        self::assertSame('Foot', $type->getLabel());
        self::assertSame('foot-patrol', $type->getKey());
    }

    public function testATypeIsRetiredAndBroughtBack(): void
    {
        $area = $this->anArea();
        $type = $this->vocabulary()->addType($area, 'Foot patrol');

        $this->vocabulary()->retireType($type);
        self::assertFalse($type->isActive());
        self::assertCount(1, $this->types()->findByArea($area), 'Retiring must never delete.');
        self::assertCount(0, $this->types()->findByAreaActive($area));

        $this->vocabulary()->reactivateType($type);
        self::assertTrue($type->isActive());
    }

    public function testASecondTypeWithTheSameNameIsRefused(): void
    {
        $area = $this->anArea();
        $this->vocabulary()->addType($area, 'Foot patrol');

        $this->expectException(VocabularyConflictException::class);
        $this->vocabulary()->addType($area, 'foot PATROL');
    }

    public function testATypeNeedsAName(): void
    {
        $area = $this->anArea();

        $this->expectException(VocabularyConflictException::class);
        $this->vocabulary()->addType($area, '   ');
    }

    // ── stations ──────────────────────────────────────────────────────────────

    public function testAddingAStationSlugsItsKey(): void
    {
        $area = $this->anArea();

        $station = $this->vocabulary()->addStation($area, 'River Post');

        self::assertSame('river-post', $station->getKey());
        self::assertSame('River Post', $station->getLabel());
        self::assertTrue($station->isActive());
    }

    public function testAStationIsRenamedRetiredAndBroughtBack(): void
    {
        $area = $this->anArea();
        $station = $this->vocabulary()->addStation($area, 'River Post');

        $this->vocabulary()->renameStation($station, 'Ridge Camp');
        self::assertSame('Ridge Camp', $station->getLabel());
        self::assertSame('river-post', $station->getKey(), 'The wire value survives a rename.');

        $this->vocabulary()->retireStation($station);
        self::assertFalse($station->isActive());
        self::assertCount(1, $this->stations()->findByArea($area));

        $this->vocabulary()->reactivateStation($station);
        self::assertTrue($station->isActive());
    }

    public function testASecondStationWithTheSameNameIsRefused(): void
    {
        $area = $this->anArea();
        $this->vocabulary()->addStation($area, 'River Post');

        $this->expectException(VocabularyConflictException::class);
        $this->vocabulary()->addStation($area, 'river post');
    }

    // ── resolving a wire string ───────────────────────────────────────────────

    /**
     * WHAT THE HANDSET'S STRING BECOMES. It still sends a station as a string,
     * and a string this area has never heard of becomes a RETIRED record rather
     * than a refusal — see the service's own reasoning.
     */
    public function testAnUnknownStationFromTheFieldIsCreatedRetired(): void
    {
        $area = $this->anArea();

        $station = $this->vocabulary()->resolveStation($area, 'North Gate');

        self::assertInstanceOf(Station::class, $station);
        self::assertSame('north-gate', $station->getKey());
        self::assertFalse($station->isActive(), 'A word nobody configured arrives retired, so it is seen and settled.');
    }

    public function testAKnownStationIsResolvedByKeyAndByLabel(): void
    {
        $area = $this->anArea();
        $configured = $this->vocabulary()->addStation($area, 'River Post');

        self::assertSame($configured, $this->vocabulary()->resolveStation($area, 'river-post'));
        self::assertSame($configured, $this->vocabulary()->resolveStation($area, 'River Post'));
        self::assertCount(1, $this->stations()->findByArea($area));
    }

    public function testABlankStationResolvesToNothing(): void
    {
        self::assertNull($this->vocabulary()->resolveStation($this->anArea(), null));
    }

    public function testAnUnknownTypeFromTheFieldIsCreatedRetired(): void
    {
        $area = $this->anArea();

        $type = $this->vocabulary()->resolveType($area, 'horseback');

        self::assertSame('horseback', $type->getKey());
        self::assertFalse($type->isActive());
    }

    // ── the seed ──────────────────────────────────────────────────────────────

    /**
     * The installation's `patrol.types` is the seed a NEW area starts from, and
     * nothing more: an area that already has words is left exactly as it is.
     */
    public function testTheInstallationsTypesSeedAnAreaOnceOnly(): void
    {
        $area = $this->anArea();

        self::assertTrue($this->vocabulary()->seedTypes($area));
        $seeded = $this->types()->findByArea($area);
        // The installation this suite plays configures two (see TestKernel).
        self::assertSame(['walk', 'boat'], array_map(static fn (PatrolType $t): string => $t->getKey(), $seeded));
        self::assertSame(['Walking round', 'Boat'], array_map(static fn (PatrolType $t): string => $t->getLabel(), $seeded));

        $this->vocabulary()->renameType($seeded[0], 'On foot');
        self::assertFalse($this->vocabulary()->seedTypes($area));
        self::assertSame('On foot', $this->types()->findByArea($area)[0]->getLabel());
    }
}
