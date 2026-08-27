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

namespace UhifadhiLabs\Patrol\Tests\Integration\Module;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Module\DepartmentKpi;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Module\PatrolDepartmentKpiProvider;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE test this whole feature turns on: TWO DEPARTMENTS SHARING THE PATROLS MODULE.
 *
 * Both read the same rows. Neither is fenced out of the other's. And yet they must report
 * different numbers, because a patrol belongs to a department through THE PERSON WHO RECORDED IT
 * — patrol → lead → position → department. Get this wrong in the obvious way (count the area's
 * patrols) and both departments report the same figure and the board becomes meaningless.
 */
final class PatrolDepartmentKpiProviderTest extends IntegrationTestCase
{
    private const string NOW = '2026-08-20 09:00:00';

    public function testTwoDepartmentsSharingTheModuleReadTheSameRowsAndGetDifferentNumbers(): void
    {
        $world = $this->world();

        $ecology = self::figures($this->provider()->kpisFor($world['ecology'], self::now()));
        $protection = self::figures($this->provider()->kpisFor($world['protection'], self::now()));

        // 5 patrols exist this month in one area. Ecology's people led 2 of them, Protection's 3.
        // Neither department "sees" fewer rows — the SPLIT is by recording position.
        self::assertSame(2.0, $ecology['patrols']);
        self::assertSame(3.0, $protection['patrols']);
        self::assertSame(5.0, $ecology['patrols'] + $protection['patrols']);

        // Distance follows the same rows: 10 + 12 against 20 + 30 + 40.
        self::assertSame(22.0, $ecology['distance']);
        self::assertSame(90.0, $protection['distance']);
    }

    public function testAnObservationCountsForItsOwnRecordersDepartmentNotTheLeadsOne(): void
    {
        $world = $this->world();

        $ecology = self::figures($this->provider()->kpisFor($world['ecology'], self::now()));
        $protection = self::figures($this->provider()->kpisFor($world['protection'], self::now()));

        // Three observations exist. Two were logged by Ecology's analyst — one of them DURING a
        // patrol Protection led. An observation carries its own recorder, so it counts for the
        // person's department and not for whoever led the patrol.
        self::assertSame(2.0, $ecology['observations']);
        self::assertSame(1.0, $protection['observations']);
    }

    /**
     * A DISCARDED patrol belongs to no department's figures — and neither do the
     * observations logged on it.
     *
     * The observations are the part worth pinning: they are otherwise counted
     * independently of the patrol (an observation carries its own recorder), and
     * crediting them while dropping the patrol's kilometres would produce a
     * department that observed things on no patrols.
     */
    public function testADiscardedPatrolAndItsObservationsCountForNobody(): void
    {
        $world = $this->world();

        $thrownAway = $this->patrol($world['area'], $world['ranger'], 500.0)->discard('Started by mistake');
        $this->em->persist(new Observation($thrownAway, 'sighting')->setRecordedBy($world['analyst']));
        $this->em->flush();

        $ecology = self::figures($this->provider()->kpisFor($world['ecology'], self::now()));
        $protection = self::figures($this->provider()->kpisFor($world['protection'], self::now()));

        // Unchanged from the baseline the other tests assert.
        self::assertSame(3.0, $protection['patrols'], 'The discarded patrol is not a fourth.');
        self::assertSame(90.0, $protection['distance'], 'Nor are its 500 km.');
        self::assertSame(2.0, $ecology['observations'], 'Nor is the observation logged on it.');
    }

    /** Coverage is sliced the same way, in PostGIS: a discarded track is not the department's ground. */
    public function testADiscardedTrackIsNotADepartmentsCoverage(): void
    {
        $world = $this->world();

        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[35.4,-3.2],[35.6,-3.2]]}');
        $this->em->flush();
        $withRealTrackOnly = $this->departmentCoverage($world['protection']);
        self::assertNotNull($withRealTrackOnly);

        // A second, perpendicular track by the same department — discarded. If it
        // counted, the union would be a cross and the share would grow.
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[35.5,-3.3],[35.5,-3.1]]}')
            ->discard('Testing');
        $this->em->flush();

        self::assertEqualsWithDelta($withRealTrackOnly, $this->departmentCoverage($world['protection']), 0.0001);
    }

    public function testAPatrolWithNoRecordableDepartmentBelongsToNobodysFigures(): void
    {
        $world = $this->world();

        // An unled patrol, and one led by somebody whose position is filed under no department.
        $this->patrol($world['area'], null, 500.0);
        $unfiled = $this->user('Unfiled', 'Person', $this->position('Contractor', null));
        $this->patrol($world['area'], $unfiled, 700.0);
        $this->em->flush();

        $ecology = self::figures($this->provider()->kpisFor($world['ecology'], self::now()));
        $protection = self::figures($this->provider()->kpisFor($world['protection'], self::now()));

        // Real work the org chart cannot place. It is shared out among NOBODY rather than
        // among everybody — 500 and 700 km appear in neither column.
        self::assertSame(22.0, $ecology['distance']);
        self::assertSame(90.0, $protection['distance']);
    }

    public function testTheMonthOverMonthComparisonIsLastMonthsSameSlice(): void
    {
        $world = $this->world();

        // Two more Ecology patrols, last month.
        $this->patrol($world['area'], $world['analyst'], 5.0, '2026-07-04 07:00:00');
        $this->patrol($world['area'], $world['analyst'], 6.0, '2026-07-19 07:00:00');
        $this->em->flush();

        $kpis = $this->provider()->kpisFor($world['ecology'], self::now());
        $patrols = self::kpi($kpis, 'patrols');

        self::assertSame(2.0, $patrols->value);
        self::assertSame(2.0, $patrols->previous);
        // Same count, so no move — and a flat delta is '' rather than a fabricated direction.
        self::assertSame(0.0, $patrols->delta());
        self::assertSame('', $patrols->direction());
    }

    public function testADepartmentWhosePeopleRecordedNothingReportsNothingRatherThanZeros(): void
    {
        $world = $this->world();
        $tourism = $this->department('Tourism');
        $this->em->flush();

        // The module IS attached (the host only calls this provider when it is), but nobody in
        // Tourism has recorded a row. Three zeros would read as "they did nothing"; an empty list
        // makes the host draw dashed labelled slots, which is the true statement.
        self::assertSame([], $this->provider()->kpisFor($tourism, self::now()));
    }

    public function testTheFiguresCarrySixMonthsOfTheirOwnSliceForTheSparkline(): void
    {
        $world = $this->world();

        $patrols = self::kpi($this->provider()->kpisFor($world['ecology'], self::now()), 'patrols');

        self::assertCount(6, $patrols->spark);
        // Oldest first, current month last — the month with Ecology's two patrols.
        self::assertSame(2.0, $patrols->spark[5]);
        self::assertNotSame('', $patrols->sparkPoints());
    }

    public function testCoverageIsReportedAndIsThisDepartmentsGroundAlone(): void
    {
        $world = $this->world();

        // The world's patrols carry distances but no routes. Give each department a recorded
        // track: Ecology one band across the area, Protection two — so the two figures cannot
        // come out equal by symmetry, and neither may come out as the area's.
        $this->tracked($world['area'], $world['analyst'], '{"type":"LineString","coordinates":[[35.4,-3.25],[35.6,-3.25]]}');
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[35.4,-3.20],[35.6,-3.20]]}');
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[35.4,-3.15],[35.6,-3.15]]}');
        $this->em->flush();

        $ecology = self::kpi($this->provider()->kpisFor($world['ecology'], self::now()), 'coverage');
        $protection = self::kpi($this->provider()->kpisFor($world['protection'], self::now()), 'coverage');

        // A share, so the host prints it with a '%' and moves it in POINTS, not percent.
        self::assertSame(DepartmentKpi::SHARE, $ecology->unit);
        self::assertTrue($ecology->isShare());
        self::assertNotNull($ecology->value);
        self::assertNotNull($protection->value);

        // Reported in points, the way every plate on the department page prints a share.
        self::assertGreaterThan(0.0, $ecology->value);
        self::assertLessThan(100.0, $protection->value);

        // Protection walked twice the ground, so the two figures differ — the whole reason this
        // KPI could not be the area's number handed to everybody.
        self::assertGreaterThan($ecology->value, $protection->value);

        $areaWide = $this->areaWideCoverage($world['area']);
        self::assertNotNull($areaWide);
        self::assertLessThan($areaWide * 100.0, $ecology->value);
        self::assertLessThan($areaWide * 100.0, $protection->value);
    }

    public function testADepartmentThatRecordedNoTrackReportsCoverageAsUnknownRatherThanZero(): void
    {
        $world = $this->world();

        // The world's patrols are hand-logged: real work, no route. "We did not measure" is not
        // "we covered none of it", and the plate must show the design's dash.
        $coverage = self::kpi($this->provider()->kpisFor($world['ecology'], self::now()), 'coverage');

        self::assertNull($coverage->value);
        self::assertFalse($coverage->isKnown());
        self::assertSame("\u{2014}", $coverage->display());
    }

    public function testCoverageIsNeverSplitByArea(): void
    {
        $world = $this->world();

        // A second area with its own Ecology patrol, so the per-area figures are emitted at all.
        $second = new AreaOfInterest()
            ->setName('Serengeti')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[34.4,-2.3],[34.6,-2.3],[34.6,-2.1],[34.4,-2.1],[34.4,-2.3]]]]}');
        $this->em->persist($second);
        $this->tracked($second, $world['analyst'], '{"type":"LineString","coordinates":[[34.4,-2.2],[34.6,-2.2]]}');
        $this->em->flush();

        $kpis = $this->provider()->kpisFor($world['ecology'], self::now());
        $coverages = array_values(array_filter($kpis, static fn (DepartmentKpi $k): bool => 'coverage' === $k->key));

        // Coverage over two areas is not the sum, the mean, or a per-area row of two coverages.
        // The department reads ONE ratio over the ground it walked; the per-area table dashes it.
        self::assertCount(1, $coverages);
        self::assertTrue($coverages[0]->isTotal());
        self::assertNotNull($coverages[0]->value);
    }

    public function testCoverageIsTheLastFigureReported(): void
    {
        $world = $this->world();

        $keys = array_map(static fn (DepartmentKpi $k): string => $k->key, array_filter(
            $this->provider()->kpisFor($world['ecology'], self::now()),
            static fn (DepartmentKpi $k): bool => $k->isTotal(),
        ));

        self::assertSame(['patrols', 'distance', 'observations', 'coverage'], array_values($keys));
    }

    public function testEveryFigureNamesTheModuleTheHostAskedFor(): void
    {
        $world = $this->world();

        foreach ($this->provider()->kpisFor($world['ecology'], self::now()) as $kpi) {
            // The host only asks a provider whose slug the department attaches, so a figure
            // captioned with another module's name would be untraceable on the page.
            self::assertSame('patrols', $kpi->moduleSlug);
            self::assertSame('Patrols', $kpi->moduleName);
        }
        self::assertSame('patrols', $this->provider()->moduleSlug());
    }

    /**
     * One area, two departments, five patrols this month and three observations.
     *
     * @return array{area: AreaOfInterest, ecology: Department, protection: Department, analyst: User, ranger: User}
     */
    private function world(): array
    {
        $area = new AreaOfInterest()
            ->setName('Ngorongoro')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.4,-3.3],[35.6,-3.3],[35.6,-3.1],[35.4,-3.1],[35.4,-3.3]]]]}');
        $this->em->persist($area);

        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');

        $analyst = $this->user('Grace', 'Shirima', $this->position('Analyst', $ecology));
        $ranger = $this->user('Juma', 'Kileo', $this->position('Ranger', $protection));

        // Ecology's two.
        $this->patrol($area, $analyst, 10.0);
        $ecologySecond = $this->patrol($area, $analyst, 12.0);
        // Protection's three.
        $this->patrol($area, $ranger, 20.0);
        $protectionSecond = $this->patrol($area, $ranger, 30.0);
        $this->patrol($area, $ranger, 40.0);

        // Two of Ecology's analyst's — one of them logged during a Protection-led patrol.
        $this->em->persist(new Observation($ecologySecond, 'sighting')->setRecordedBy($analyst));
        $this->em->persist(new Observation($protectionSecond, 'sighting')->setRecordedBy($analyst));
        $this->em->persist(new Observation($protectionSecond, 'sighting')->setRecordedBy($ranger));

        $this->em->flush();

        return ['area' => $area, 'ecology' => $ecology, 'protection' => $protection, 'analyst' => $analyst, 'ranger' => $ranger];
    }

    /** One department's PL·03 over the test month, straight from the repository. */
    private function departmentCoverage(Department $department): ?float
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return $repository->coverageFractionForDepartment(
            null,
            $department,
            PatrolDashboardService::COVERAGE_BUFFER_M,
            ...PatrolDashboardService::monthRange(self::now()),
        );
    }

    /** A patrol that actually recorded a route — the only kind coverage can be measured from. */
    private function tracked(AreaOfInterest $area, User $lead, string $track): Patrol
    {
        return $this->patrol($area, $lead, 0.0)->setTrack($track);
    }

    /** The area's own PL·03, for comparing a department's share against the whole. */
    private function areaWideCoverage(AreaOfInterest $area): ?float
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return $repository->coverageFractionWithin(
            $area,
            PatrolDashboardService::COVERAGE_BUFFER_M,
            ...PatrolDashboardService::monthRange(self::now()),
        );
    }

    private function provider(): PatrolDepartmentKpiProvider
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return new PatrolDepartmentKpiProvider($repository, $this->em, 'patrols', 'Patrols');
    }

    private function department(string $name): Department
    {
        $department = new Department()->setName($name);
        $this->em->persist($department);

        return $department;
    }

    private function position(string $name, ?Department $department): Position
    {
        $position = new Position()->setName($name)->setDepartment($department);
        $this->em->persist($position);

        return $position;
    }

    private function user(string $first, string $last, Position $position): User
    {
        $user = new User()
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last)
            ->setPosition($position);
        $this->em->persist($user);

        return $user;
    }

    private function patrol(AreaOfInterest $area, ?User $lead, float $km, string $startedAt = '2026-08-05 07:00:00'): Patrol
    {
        $patrol = new Patrol($area, 'walk')
            ->setLead($lead)
            ->setDistanceKm($km)
            ->setStartedAt(new \DateTimeImmutable($startedAt));
        $this->em->persist($patrol);

        return $patrol;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * The department TOTALS as key => value. Per-area figures are deliberately excluded — summing
     * the raw list would double-count.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return array<string, float>
     */
    private static function figures(array $kpis): array
    {
        $figures = [];
        foreach ($kpis as $kpi) {
            if ($kpi->isTotal()) {
                $figures[$kpi->key] = (float) $kpi->value;
            }
        }

        return $figures;
    }

    /** @param list<DepartmentKpi> $kpis */
    private static function kpi(array $kpis, string $key): DepartmentKpi
    {
        foreach ($kpis as $kpi) {
            if ($kpi->key === $key && $kpi->isTotal()) {
                return $kpi;
            }
        }

        self::fail(\sprintf('No "%s" figure was reported.', $key));
    }
}
