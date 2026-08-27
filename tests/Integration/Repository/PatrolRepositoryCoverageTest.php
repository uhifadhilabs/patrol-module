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

namespace UhifadhiLabs\Patrol\Tests\Integration\Repository;

use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * PL·03 "Coverage · 2 km buffer" against real PostGIS: the share of the area's
 * surface lying within 2 km of any track recorded this month.
 *
 * The fixture area is a ~0.1° square straddling the equator-ish latitude −3,
 * so its geodesic extent is roughly 11.1 km × 11.1 km ≈ 123 km². A track drawn
 * straight across its middle therefore sweeps a 4 km band ≈ 44 km² — about a
 * third of the square. The assertions are deliberately loose bounds around that
 * hand-computable figure, not a golden number: the point is that the query
 * measures on the spheroid (buffering in geography metres), not in degrees.
 */
final class PatrolRepositoryCoverageTest extends IntegrationTestCase
{
    /** The design's buffer: "% of area within 2 km of a track". */
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

    /** A ~11.1 km square: lon 35.0–35.1, lat −3.0 to −2.9. */
    private function makeArea(bool $withBoundary = true): AreaOfInterest
    {
        $area = new AreaOfInterest()->setName('Example square');
        if ($withBoundary) {
            $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.0],[35.1,-3.0],[35.1,-2.9],[35.0,-2.9],[35.0,-3.0]]]]}');
        }
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function makePatrol(AreaOfInterest $area, string $startedAt, ?string $track): Patrol
    {
        $patrol = new Patrol($area, 'walk')
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    private function coverage(AreaOfInterest $area): ?float
    {
        return $this->repository()->coverageFractionWithin($area, self::BUFFER_M, $this->monthStart, $this->nextMonth);
    }

    public function testATrackAcrossTheSquareCoversAboutAThird(): void
    {
        $area = $this->makeArea();
        // Straight across the middle, west edge to east edge.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        $fraction = $this->coverage($area);

        self::assertNotNull($fraction);
        // ≈ 44 km² of 123 km². Loose bounds — the band is 4 km of an 11 km square.
        self::assertGreaterThan(0.25, $fraction);
        self::assertLessThan(0.50, $fraction);
    }

    public function testTwoTracksAreUnionedRatherThanSummed(): void
    {
        $area = $this->makeArea();
        // Two lines 0.005° (~550 m) apart: their 2 km buffers overlap heavily,
        // so the union must be far less than twice a single track's coverage.
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');
        $this->makePatrol($area, '2026-03-11T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.945],[35.1,-2.945]]}');

        $fraction = $this->coverage($area);

        self::assertNotNull($fraction);
        self::assertGreaterThan(0.25, $fraction);
        self::assertLessThan(0.60, $fraction);
    }

    public function testTracksSweepingTheWholeSquareAreClippedToTheBoundary(): void
    {
        $area = $this->makeArea();
        // Three lines ~3.3 km apart, each running well past both edges: their
        // 4 km bands overlap into one blanket over the square. The buffer spills
        // far outside the boundary, so a fraction above 1 would prove the
        // intersection is not being clipped.
        foreach (['-2.98', '-2.95', '-2.92'] as $index => $lat) {
            $this->makePatrol(
                $area,
                \sprintf('2026-03-1%dT06:00:00Z', $index),
                \sprintf('{"type":"LineString","coordinates":[[34.8,%1$s],[35.3,%1$s]]}', $lat),
            );
        }

        $fraction = $this->coverage($area);

        self::assertNotNull($fraction);
        self::assertGreaterThan(0.90, $fraction);
        self::assertLessThanOrEqual(1.0, $fraction);
    }

    public function testNoPatrolsAtAllIsTheEmptyState(): void
    {
        self::assertNull($this->coverage($this->makeArea()));
    }

    public function testAManualPatrolWithNoTrackContributesNothing(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', null);

        self::assertNull($this->coverage($area));
    }

    public function testATrackFromAnotherMonthIsOutsideTheWindow(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-02-27T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');
        $this->makePatrol($area, '2026-04-02T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.96],[35.1,-2.96]]}');

        self::assertNull($this->coverage($area));
    }

    public function testAnotherAreasTracksAreNotCounted(): void
    {
        $area = $this->makeArea();
        $other = $this->makeArea();
        $this->makePatrol($other, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        self::assertNull($this->coverage($area));
    }

    public function testAnAreaWithNoBoundaryHasNothingToMeasureAgainst(): void
    {
        $area = $this->makeArea(withBoundary: false);
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        self::assertNull($this->coverage($area));
    }

    /**
     * A DISCARDED patrol's track is not coverage. The two patrols here draw the
     * same line across the same square, so the only difference between "a third
     * of the area" and "nothing measured" is the discard itself.
     */
    public function testADiscardedTracksGroundIsNotCounted(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}')
            ->discard('Started by mistake');
        $this->em->flush();

        // Null, not 0.0: with the discard removed there is no track in the
        // window at all, and unmeasured is not the same fact as zero.
        self::assertNull($this->coverage($area));
    }

    /** And it subtracts only itself — a real patrol beside it still measures. */
    public function testADiscardedTrackDoesNotSuppressARealOne(): void
    {
        $area = $this->makeArea();
        $this->makePatrol($area, '2026-03-10T06:00:00Z', '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');
        $this->makePatrol($area, '2026-03-11T06:00:00Z', '{"type":"LineString","coordinates":[[35.05,-3.0],[35.05,-2.9]]}')
            ->discard('Testing');
        $this->em->flush();

        $withDiscardExcluded = $this->coverage($area);
        self::assertNotNull($withDiscardExcluded);

        // The surviving track alone sweeps roughly a third of the square. Were
        // the discarded perpendicular track counted, the union would be a cross
        // and the share markedly larger.
        self::assertGreaterThan(0.25, $withDiscardExcluded);
        self::assertLessThan(0.50, $withDiscardExcluded);
    }
}
