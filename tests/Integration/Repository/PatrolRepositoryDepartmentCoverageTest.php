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
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * PL·03 SLICED BY DEPARTMENT, against real PostGIS.
 *
 * The area-wide figure ({@see PatrolRepository::coverageFractionWithin()}) answers "how much of
 * this place was walked this month". This one answers "how much of it did THESE PEOPLE walk" —
 * the same set operation over a strictly smaller set of tracks, chosen the one way this module
 * ever chooses them: patrol → lead → position → department.
 *
 * The fixture square is the same ~11.1 km × 11.1 km ≈ 123 km² square the area-wide test uses, so
 * a track straight across it sweeps a 4 km band ≈ 44 km² — about a third. Two departments each
 * walking one such band, far enough apart that their buffers do not touch, must therefore each
 * read about a third and the area must read about two thirds. Bounds are loose around that
 * hand-computable figure; the ORDER between the three numbers is the assertion that matters.
 */
final class PatrolRepositoryDepartmentCoverageTest extends IntegrationTestCase
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

    public function testEachDepartmentReadsOnlyTheGroundItsOwnPeopleWalked(): void
    {
        $area = $this->makeArea();
        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');

        // Two bands 0.06° (~6.7 km) apart: each 2 km buffer reaches 2 km either side, so the
        // bands are disjoint and the area's union is exactly the two of them.
        $this->makeTrackedPatrol($area, $this->member('Grace', $ecology), '{"type":"LineString","coordinates":[[35.0,-2.98],[35.1,-2.98]]}');
        $this->makeTrackedPatrol($area, $this->member('Juma', $protection), '{"type":"LineString","coordinates":[[35.0,-2.92],[35.1,-2.92]]}');

        $ecologyShare = $this->departmentCoverage($area, $ecology);
        $protectionShare = $this->departmentCoverage($area, $protection);
        $areaWide = $this->areaCoverage($area);

        self::assertNotNull($ecologyShare);
        self::assertNotNull($protectionShare);
        self::assertNotNull($areaWide);

        // Each department walked one band of an eleven-kilometre square.
        self::assertGreaterThan(0.25, $ecologyShare);
        self::assertLessThan(0.50, $ecologyShare);
        self::assertGreaterThan(0.25, $protectionShare);
        self::assertLessThan(0.50, $protectionShare);

        // THE POINT: neither department may be handed the whole area's coverage. Ground covered
        // entirely by the other department's patrol is not theirs, so both figures are STRICTLY
        // smaller than the area's.
        self::assertLessThan($areaWide, $ecologyShare);
        self::assertLessThan($areaWide, $protectionShare);

        // And because the two bands do not touch, the area is exactly their sum — which is proof
        // the department filter removes tracks from the union rather than shrinking the boundary.
        self::assertEqualsWithDelta($areaWide, $ecologyShare + $protectionShare, 0.02);
    }

    public function testADepartmentWhosePeopleRecordedNoTrackHasNothingToMeasure(): void
    {
        $area = $this->makeArea();
        $ecology = $this->department('Ecology');
        $tourism = $this->department('Tourism');

        $this->makeTrackedPatrol($area, $this->member('Grace', $ecology), '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        // Unknown, never 0.0 — Tourism did not walk none of the park, it walked nothing that was
        // recorded, and the plate says so with a dash.
        self::assertNull($this->departmentCoverage($area, $tourism));
        self::assertNotNull($this->areaCoverage($area));
    }

    public function testAPatrolTheOrgChartCannotPlaceBelongsToNoDepartmentsCoverage(): void
    {
        $area = $this->makeArea();
        $ecology = $this->department('Ecology');

        $this->makeTrackedPatrol($area, $this->member('Grace', $ecology), '{"type":"LineString","coordinates":[[35.0,-2.98],[35.1,-2.98]]}');
        // A patrol nobody led, and one led by somebody whose position sits under no department.
        $this->makeTrackedPatrol($area, null, '{"type":"LineString","coordinates":[[35.0,-2.94],[35.1,-2.94]]}');
        $this->makeTrackedPatrol($area, $this->member('Unfiled', null), '{"type":"LineString","coordinates":[[35.0,-2.92],[35.1,-2.92]]}');

        $ecologyShare = $this->departmentCoverage($area, $ecology);
        $areaWide = $this->areaCoverage($area);

        self::assertNotNull($ecologyShare);
        self::assertNotNull($areaWide);
        // Real ground, really walked: it counts for the AREA and for no department's column. It is
        // shared out among nobody rather than among everybody.
        self::assertLessThan($areaWide, $ecologyShare);
        self::assertLessThan(0.50, $ecologyShare);
        self::assertGreaterThan(0.80, $areaWide);
    }

    public function testAManualPatrolWithNoTrackContributesNothing(): void
    {
        $area = $this->makeArea();
        $ecology = $this->department('Ecology');
        $this->makeTrackedPatrol($area, $this->member('Grace', $ecology), null);

        self::assertNull($this->departmentCoverage($area, $ecology));
    }

    public function testATrackFromAnotherMonthIsOutsideTheWindow(): void
    {
        $area = $this->makeArea();
        $ecology = $this->department('Ecology');
        $grace = $this->member('Grace', $ecology);

        $this->makeTrackedPatrol($area, $grace, '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}', '2026-02-27T06:00:00Z');
        $this->makeTrackedPatrol($area, $grace, '{"type":"LineString","coordinates":[[35.0,-2.96],[35.1,-2.96]]}', '2026-04-02T06:00:00Z');

        self::assertNull($this->departmentCoverage($area, $ecology));
    }

    public function testAnotherAreasTracksAreNotCountedWhenAnAreaIsNamed(): void
    {
        $area = $this->makeArea();
        $other = $this->makeArea();
        $ecology = $this->department('Ecology');

        $this->makeTrackedPatrol($other, $this->member('Grace', $ecology), '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        self::assertNull($this->departmentCoverage($area, $ecology));
    }

    public function testAnAreaWithNoBoundaryHasNothingToMeasureAgainst(): void
    {
        $area = $this->makeArea(withBoundary: false);
        $ecology = $this->department('Ecology');
        $this->makeTrackedPatrol($area, $this->member('Grace', $ecology), '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        self::assertNull($this->departmentCoverage($area, $ecology));
    }

    public function testNoAreaMeasuresEveryAreaTheDepartmentWalkedAtOnce(): void
    {
        $thin = $this->makeArea();
        $blanketed = $this->makeArea(lonWest: 36.0);
        $ecology = $this->department('Ecology');
        $grace = $this->member('Grace', $ecology);

        // One band in the first square; a blanket of three overlapping bands in the second.
        $this->makeTrackedPatrol($thin, $grace, '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');
        foreach (['-2.98', '-2.95', '-2.92'] as $index => $lat) {
            $this->makeTrackedPatrol($blanketed, $grace, \sprintf('{"type":"LineString","coordinates":[[35.8,%1$s],[36.3,%1$s]]}', $lat), \sprintf('2026-03-1%dT06:00:00Z', $index));
        }

        $here = $this->departmentCoverage($thin, $ecology);
        $there = $this->departmentCoverage($blanketed, $ecology);
        $everywhere = $this->departmentCoverage(null, $ecology);

        self::assertNotNull($here);
        self::assertNotNull($there);
        self::assertNotNull($everywhere);
        // Not the sum of two coverages and not their mean: one ratio over both squares' surfaces,
        // so it lies strictly between the thin one and the blanketed one.
        self::assertGreaterThan($here, $everywhere);
        self::assertLessThan($there, $everywhere);
    }

    public function testTheAreaWideFigureIsUnchangedByTheDepartmentSlice(): void
    {
        $area = $this->makeArea();
        $ecology = $this->department('Ecology');

        $this->makeTrackedPatrol($area, $this->member('Grace', $ecology), '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');
        // An identical track by somebody with no department at all: invisible to every
        // department's figure, and part of the area's, exactly as before this method existed.
        $this->makeTrackedPatrol($area, null, '{"type":"LineString","coordinates":[[35.0,-2.95],[35.1,-2.95]]}');

        $areaWide = $this->areaCoverage($area);

        self::assertNotNull($areaWide);
        self::assertGreaterThan(0.25, $areaWide);
        self::assertLessThan(0.50, $areaWide);
    }

    private function repository(): PatrolRepository
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return $repository;
    }

    private function departmentCoverage(?AreaOfInterest $area, Department $department): ?float
    {
        return $this->repository()->coverageFractionForDepartment($area, $department, self::BUFFER_M, $this->monthStart, $this->nextMonth);
    }

    private function areaCoverage(AreaOfInterest $area): ?float
    {
        return $this->repository()->coverageFractionWithin($area, self::BUFFER_M, $this->monthStart, $this->nextMonth);
    }

    /** A ~11.1 km square: 0.1° wide from $lonWest, lat −3.0 to −2.9. */
    private function makeArea(bool $withBoundary = true, float $lonWest = 35.0): AreaOfInterest
    {
        $area = new AreaOfInterest()->setName(\sprintf('Square at %.1f', $lonWest));
        if ($withBoundary) {
            $area->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[%1$.1f,-3.0],[%2$.1f,-3.0],[%2$.1f,-2.9],[%1$.1f,-2.9],[%1$.1f,-3.0]]]]}',
                $lonWest,
                $lonWest + 0.1,
            ));
        }
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function makeTrackedPatrol(AreaOfInterest $area, ?User $lead, ?string $track, string $startedAt = '2026-03-10T06:00:00Z'): Patrol
    {
        $patrol = new Patrol($area, 'walk')
            ->setSource(null === $track ? PatrolSourceEnum::Manual : PatrolSourceEnum::Gpx)
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setTrack($track);
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    private function department(string $name): Department
    {
        $department = new Department()->setName($name);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /** Somebody who records patrols, filed under $department through their position. */
    private function member(string $firstName, ?Department $department): User
    {
        $position = new Position()->setName('Ranger')->setDepartment($department);
        $this->em->persist($position);

        $user = new User()
            ->setEmail(strtolower($firstName).'@example.test')
            ->setFirstName($firstName)
            ->setLastName('Fixture')
            ->setPosition($position);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
