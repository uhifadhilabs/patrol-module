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

use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Area\Entity\Zone;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * The three readings the AREA OVERVIEW asks of patrols and the module's own
 * screens never did: who is out, what closed today, and — the one that needed
 * new geometry — where nobody has been.
 *
 * PL·A3 IS ABOUT ABSENCE, and absence is measured from the last track that
 * ENTERED a zone, not from the last patrol that named one: a patrol carries a
 * free-text station and no zone at all, so the only honest answer comes from
 * ST_Intersects against the host's zone polygons.
 *
 * The fixture area is the same ~0.1° square PatrolRepositoryCoverageTest uses
 * (lon 35.0–35.1, lat −3.0 to −2.9, ≈ 123 km²), split into a NORTH and a SOUTH
 * half so a track can enter one and miss the other.
 */
final class PatrolRepositoryOverviewTest extends IntegrationTestCase
{
    private const float BUFFER_M = 2000.0;

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
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.0],[35.1,-3.0],[35.1,-2.9],[35.0,-2.9],[35.0,-3.0]]]]}');
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
                '{"type":"MultiPolygon","coordinates":[[[[35.0,%1$s],[35.1,%1$s],[35.1,%2$s],[35.0,%2$s],[35.0,%1$s]]]]}',
                $southLat,
                $northLat,
            ));
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    private function makePatrol(AreaOfInterest $area, string $startedAt, ?string $track, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $patrol = new Patrol($area, 'walk')
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setStatus($status)
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    // ---- who is out -------------------------------------------------------

    public function testOnlyRecordingPatrolsAreOutRightNow(): void
    {
        $area = $this->makeArea();
        $out = $this->makePatrol($area, '2026-03-22T06:00:00Z', null, PatrolStatusEnum::Recording);
        $this->makePatrol($area, '2026-03-22T05:00:00Z', null, PatrolStatusEnum::Complete);
        $this->makePatrol($area, '2026-03-22T04:00:00Z', null, PatrolStatusEnum::Discarded);

        $recording = $this->repository()->findByAreaRecording($area);

        self::assertSame([$out->getId()], array_map(static fn (Patrol $p): ?int => $p->getId(), $recording));
    }

    public function testAPatrolOutInAnotherAreaIsNotOutInThisOne(): void
    {
        $area = $this->makeArea();
        $elsewhere = $this->makeArea();
        $this->makePatrol($elsewhere, '2026-03-22T06:00:00Z', null, PatrolStatusEnum::Recording);

        self::assertSame([], $this->repository()->findByAreaRecording($area));
    }

    // ---- what closed today ------------------------------------------------

    public function testClosedTodayIsMeasuredByWhenAPatrolENDED(): void
    {
        $area = $this->makeArea();
        // Opened before midnight, closed this morning: the day's work, by the
        // only column that says when it was closed.
        $overnight = $this->makePatrol($area, '2026-03-21T20:00:00Z', null);
        $overnight->setEndedAt(new \DateTimeImmutable('2026-03-22T04:00:00Z'));
        // Opened today and still open: not closed today.
        $stillOut = $this->makePatrol($area, '2026-03-22T06:00:00Z', null, PatrolStatusEnum::Recording);
        $stillOut->setEndedAt(null);
        // Closed yesterday.
        $yesterday = $this->makePatrol($area, '2026-03-21T06:00:00Z', null);
        $yesterday->setEndedAt(new \DateTimeImmutable('2026-03-21T15:00:00Z'));
        $this->em->flush();

        $closed = $this->repository()->findByAreaEndedBetween(
            $area,
            new \DateTimeImmutable('2026-03-22T00:00:00Z'),
            new \DateTimeImmutable('2026-03-23T00:00:00Z'),
        );

        self::assertSame([$overnight->getId()], array_map(static fn (Patrol $p): ?int => $p->getId(), $closed));
    }

    // ---- where nobody has been -------------------------------------------

    public function testAZoneNoTrackEverEnteredHasNoLastEntry(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makeZone($area, 'South', -3.0, -2.95);
        // A track along the far north edge: it enters North and misses South.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.92],[35.1,-2.92]]}');

        $rows = $this->repository()->zoneAbsenceForArea($area, self::BUFFER_M, $this->monthStart, $this->nextMonth);

        self::assertCount(2, $rows);
        // Worst first: the zone nobody has entered leads.
        self::assertSame('South', $rows[0]['zone']);
        self::assertNull($rows[0]['lastEnteredAt']);
        self::assertNull($rows[0]['lastPatrolId']);

        self::assertSame('North', $rows[1]['zone']);
        self::assertNotNull($rows[1]['lastEnteredAt']);
        self::assertSame('2026-03-10', $rows[1]['lastEnteredAt']->format('Y-m-d'));
    }

    public function testTheMostRECENTTrackToEnterAZoneIsTheOneReported(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makePatrol($area, '2026-03-05T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.92],[35.1,-2.92]]}');
        $latest = $this->makePatrol($area, '2026-03-18T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.93],[35.1,-2.93]]}');

        $rows = $this->repository()->zoneAbsenceForArea($area, self::BUFFER_M, $this->monthStart, $this->nextMonth);

        self::assertSame($latest->getId(), $rows[0]['lastPatrolId']);
        self::assertSame('2026-03-18', $rows[0]['lastEnteredAt']?->format('Y-m-d'));
    }

    public function testADiscardedTrackNeverEnteredAnything(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.92],[35.1,-2.92]]}', PatrolStatusEnum::Discarded);

        $rows = $this->repository()->zoneAbsenceForArea($area, self::BUFFER_M, $this->monthStart, $this->nextMonth);

        // A discard says the effort did not happen as recorded, so it cannot be
        // the evidence that somebody was there.
        self::assertNull($rows[0]['lastEnteredAt']);
        self::assertNull($rows[0]['coverageFraction']);
    }

    public function testEachZoneIsCoveredAgainstItsOwnSurface(): void
    {
        $area = $this->makeArea();
        $this->makeZone($area, 'North', -2.95, -2.9);
        $this->makeZone($area, 'South', -3.0, -2.95);
        // Straight across the middle of the NORTH half only (≈ 5.5 km tall), so
        // its 4 km band covers most of North and only clips into South.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.925],[35.1,-2.925]]}');

        $rows = $this->repository()->zoneAbsenceForArea($area, self::BUFFER_M, $this->monthStart, $this->nextMonth);
        $byZone = array_column($rows, null, 'zone');

        $north = $byZone['North']['coverageFraction'];
        $south = $byZone['South']['coverageFraction'];
        self::assertNotNull($north);
        self::assertNotNull($south);
        // The band sits over North and reaches only a little way into South.
        self::assertGreaterThan(0.6, $north);
        self::assertLessThan($north, $south);
    }

    public function testAnAreaWithNoZonesMeasuresNothing(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        self::assertSame([], $this->repository()->zoneAbsenceForArea($area, self::BUFFER_M, $this->monthStart, $this->nextMonth));
    }

    public function testAnotherAreasZonesAreNotThisAreasGaps(): void
    {
        $area = $this->makeArea();
        $elsewhere = $this->makeArea();
        $this->makeZone($elsewhere, 'Somewhere else', -3.0, -2.9);

        self::assertSame([], $this->repository()->zoneAbsenceForArea($area, self::BUFFER_M, $this->monthStart, $this->nextMonth));
    }

    // ---- the coverage buffer, as geometry ---------------------------------

    public function testTheCoverageBufferIsReturnedAsGeoJsonClippedToTheBoundary(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[34.8,-2.95],[35.3,-2.95]]}');

        $geoJson = $this->repository()->coverageBufferGeoJson($area, self::BUFFER_M, $this->monthStart, $this->nextMonth);

        self::assertNotNull($geoJson);
        /** @var array{type?: string, coordinates?: array<mixed>} $decoded */
        $decoded = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        self::assertContains($decoded['type'] ?? null, ['Polygon', 'MultiPolygon']);
        // The track runs well past both edges; the buffer must be clipped to the
        // boundary rather than spilling outside the area it describes.
        self::assertStringNotContainsString('34.8', json_encode($decoded['coordinates'] ?? [], \JSON_THROW_ON_ERROR));
    }

    public function testAMonthWithNoTracksBuffersNothingRatherThanEmptyGeometry(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', null);

        self::assertNull($this->repository()->coverageBufferGeoJson($area, self::BUFFER_M, $this->monthStart, $this->nextMonth));
    }
}
