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

namespace Uhifadhi\Patrol\Tests\Integration\Repository;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE ZONE SPATIAL-JOIN and the month-windowed log query against real PostGIS —
 * the two reads that make the dashboard's ZONE filter and its month re-scope
 * real rather than a dead indicator.
 *
 * A patrol carries a free-text station and NO zone (docs/design-decisions.md §1),
 * so "which zone did this patrol set out in" is a spatial question, answered by
 * {@see PatrolRepository::zonesForPatrols()} against the host's zone polygons —
 * never guessed from the station name.
 *
 * The fixture is the same ~0.1° square PatrolRepositoryCoverageTest uses (lon
 * −30.0 to −29.9, lat −3.0 to −2.9), split into a NORTH half (lat −2.95 to −2.9) and a
 * SOUTH half (lat −3.0 to −2.95), so a track can set out in one and not the other.
 */
final class PatrolRepositoryZoneJoinTest extends IntegrationTestCase
{
    private \DateTimeImmutable $monthStart;
    private \DateTimeImmutable $nextMonth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monthStart = new \DateTimeImmutable('2026-03-01T00:00:00Z');
        $this->nextMonth = new \DateTimeImmutable('2026-04-01T00:00:00Z');
    }

    private function repository(): PatrolRepository
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return $repository;
    }

    private function makeArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('Example square');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function makeZone(AreaOfInterest $area, string $name, float $southLat, float $northLat): Zone
    {
        $zone = new Zone()
            ->setName($name)
            ->setArea($area)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[-30.0,%1$s],[-29.9,%1$s],[-29.9,%2$s],[-30.0,%2$s],[-30.0,%1$s]]]]}',
                $southLat,
                $northLat,
            ));
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    private function makePatrol(AreaOfInterest $area, string $startedAt, ?string $track): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    /** @return array<string, string> patrol uuid → zone name */
    private function zones(AreaOfInterest $area): array
    {
        return $this->repository()->zonesForPatrols($area, $this->monthStart, $this->nextMonth);
    }

    public function testEachPatrolIsFiledUnderTheZoneItsTrackSetOutIn(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makeZone($area, 'South', -3.0, -2.95);

        // Sets out in the north half.
        $north = $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-2.92],[-29.94,-2.93]]}');
        // Sets out in the south half.
        $south = $this->makePatrol($area, '2026-03-11T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-2.98],[-29.94,-2.97]]}');

        $zones = $this->zones($area);

        // Keyed by uuid (the public address), never the sequential id.
        self::assertSame('North', $zones[$north->getUuid()->toRfc4122()] ?? null);
        self::assertSame('South', $zones[$south->getUuid()->toRfc4122()] ?? null);
    }

    /**
     * ONE ZONE PER PATROL, by where it SET OUT: a track that begins in the north
     * and crosses into the south is filed under the north, so a patrol appears in
     * exactly one zone and the by-zone grouping this unblocks stays a partition.
     */
    public function testATrackCrossingAZoneBoundaryIsFiledUnderWhereItBegan(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makeZone($area, 'South', -3.0, -2.95);

        $crossing = $this->makePatrol($area, '2026-03-12T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-2.91],[-29.95,-2.99]]}');

        self::assertSame('North', $this->zones($area)[$crossing->getUuid()->toRfc4122()] ?? null);
    }

    public function testAHandLoggedPatrolHasNoTrackAndSoNoZone(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);

        $sketch = $this->makePatrol($area, '2026-03-10T06:00:00Z', null);

        self::assertArrayNotHasKey($sketch->getUuid()->toRfc4122(), $this->zones($area));
    }

    public function testAPatrolSettingOutInNoZoneIsAbsent(): void
    {
        $area = $this->makeArea();
        // A single small zone in the north-east corner; the patrol sets out well
        // clear of it, still inside the area but in no zone.
        $this->makeZone($area, 'Corner', -2.92, -2.9);

        $outside = $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-2.98],[-29.94,-2.97]]}');

        self::assertArrayNotHasKey($outside->getUuid()->toRfc4122(), $this->zones($area));
    }

    public function testTheJoinIsScopedToTheWindow(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);

        $lastMonth = $this->makePatrol($area, '2026-02-27T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-2.92],[-29.94,-2.93]]}');
        $thisMonth = $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-2.92],[-29.94,-2.93]]}');

        $zones = $this->zones($area);
        self::assertArrayHasKey($thisMonth->getUuid()->toRfc4122(), $zones);
        self::assertArrayNotHasKey($lastMonth->getUuid()->toRfc4122(), $zones);
    }

    public function testAnotherAreasZonesAreNotJoined(): void
    {
        $area = $this->makeArea();
        $other = $this->makeArea();
        // The other area has the zone; ours does not.
        $this->makeZone($other, 'North', -2.95, -2.9);

        $patrol = $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[-29.95,-2.92],[-29.94,-2.93]]}');

        self::assertArrayNotHasKey($patrol->getUuid()->toRfc4122(), $this->zones($area));
    }

    public function testTheLogQueryReturnsTheWindowLatestFirst(): void
    {
        $area = $this->makeArea();
        $earliest = $this->makePatrol($area, '2026-03-02T06:00:00Z', null);
        $latest = $this->makePatrol($area, '2026-03-20T06:00:00Z', null);
        $middle = $this->makePatrol($area, '2026-03-10T06:00:00Z', null);
        // Outside the window entirely.
        $this->makePatrol($area, '2026-02-27T06:00:00Z', null);

        $rows = $this->repository()->findByAreaStartedBetweenLatestFirst($area, $this->monthStart, $this->nextMonth);

        self::assertSame(
            [$latest->getId(), $middle->getId(), $earliest->getId()],
            array_map(static fn (Patrol $p): ?int => $p->getId(), $rows),
        );
    }
}
