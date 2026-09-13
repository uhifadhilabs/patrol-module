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

namespace Uhifadhi\Patrol\Tests\Integration\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentRef;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolDepartmentKpiProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

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

        $ecology = self::figures($this->provider()->kpisFor(self::ref($world['ecology']), self::now()));
        $protection = self::figures($this->provider()->kpisFor(self::ref($world['protection']), self::now()));

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

        $ecology = self::figures($this->provider()->kpisFor(self::ref($world['ecology']), self::now()));
        $protection = self::figures($this->provider()->kpisFor(self::ref($world['protection']), self::now()));

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

        $ecology = self::figures($this->provider()->kpisFor(self::ref($world['ecology']), self::now()));
        $protection = self::figures($this->provider()->kpisFor(self::ref($world['protection']), self::now()));

        // Unchanged from the baseline the other tests assert.
        self::assertSame(3.0, $protection['patrols'], 'The discarded patrol is not a fourth.');
        self::assertSame(90.0, $protection['distance'], 'Nor are its 500 km.');
        self::assertSame(2.0, $ecology['observations'], 'Nor is the observation logged on it.');
    }

    /**
     * THE SAME RULE FOR A PATROL STILL ARRIVING, and for the sister reason: not
     * that the effort was withdrawn but that it is not all here yet. Crediting a
     * department with a distance that is still growing makes its figures wrong
     * until the phone happens to finish syncing.
     */
    public function testAPatrolStillRecordingCountsForNobodyYet(): void
    {
        $world = $this->world();

        $stillArriving = $this->patrol($world['area'], $world['ranger'], 500.0)
            ->setStatus(PatrolStatusEnum::Recording);
        $this->em->persist(new Observation($stillArriving, 'sighting')->setRecordedBy($world['analyst']));
        $this->em->flush();

        $ecology = self::figures($this->provider()->kpisFor(self::ref($world['ecology']), self::now()));
        $protection = self::figures($this->provider()->kpisFor(self::ref($world['protection']), self::now()));

        // The same baseline the discard test holds to.
        self::assertSame(3.0, $protection['patrols'], 'A patrol still arriving is not a fourth.');
        self::assertSame(90.0, $protection['distance'], 'Nor is the distance it has reached so far.');
        self::assertSame(2.0, $ecology['observations'], 'Nor is the observation logged on it.');
    }

    /** Coverage is sliced the same way, in PostGIS: a discarded track is not the department's ground. */
    public function testADiscardedTrackIsNotADepartmentsCoverage(): void
    {
        $world = $this->world();

        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.6,-3.2],[-29.4,-3.2]]}');
        $this->em->flush();
        $withRealTrackOnly = $this->departmentCoverage($world['protection']);
        self::assertNotNull($withRealTrackOnly);

        // A second, perpendicular track by the same department — discarded. If it
        // counted, the union would be a cross and the share would grow.
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.5,-3.3],[-29.5,-3.1]]}')
            ->discard('Testing');
        $this->em->flush();

        self::assertEqualsWithDelta($withRealTrackOnly, $this->departmentCoverage($world['protection']), 0.0001);
    }

    /** And a track that has not finished arriving is not the department's ground either. */
    public function testATrackStillRecordingIsNotADepartmentsCoverage(): void
    {
        $world = $this->world();

        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.6,-3.2],[-29.4,-3.2]]}');
        $this->em->flush();
        $withCompleteTrackOnly = $this->departmentCoverage($world['protection']);
        self::assertNotNull($withCompleteTrackOnly);

        // Perpendicular again: were it counted, the union would be a cross.
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.5,-3.3],[-29.5,-3.1]]}')
            ->setStatus(PatrolStatusEnum::Recording);
        $this->em->flush();

        self::assertEqualsWithDelta($withCompleteTrackOnly, $this->departmentCoverage($world['protection']), 0.0001);
    }

    public function testAPatrolWithNoRecordableDepartmentBelongsToNobodysFigures(): void
    {
        $world = $this->world();

        // An unled patrol, and one led by somebody whose position is filed under no department.
        $this->patrol($world['area'], null, 500.0);
        $unfiled = $this->user('Unfiled', 'Person', $this->position('Contractor', null));
        $this->patrol($world['area'], $unfiled, 700.0);
        $this->em->flush();

        $ecology = self::figures($this->provider()->kpisFor(self::ref($world['ecology']), self::now()));
        $protection = self::figures($this->provider()->kpisFor(self::ref($world['protection']), self::now()));

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

        $kpis = $this->provider()->kpisFor(self::ref($world['ecology']), self::now());
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
        self::assertSame([], $this->provider()->kpisFor(self::ref($tourism), self::now()));
    }

    public function testTheFiguresCarrySixMonthsOfTheirOwnSliceForTheSparkline(): void
    {
        $world = $this->world();

        $patrols = self::kpi($this->provider()->kpisFor(self::ref($world['ecology']), self::now()), 'patrols');

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
        $this->tracked($world['area'], $world['analyst'], '{"type":"LineString","coordinates":[[-29.6,-3.25],[-29.4,-3.25]]}');
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.6,-3.20],[-29.4,-3.20]]}');
        $this->tracked($world['area'], $world['ranger'], '{"type":"LineString","coordinates":[[-29.6,-3.15],[-29.4,-3.15]]}');
        $this->em->flush();

        $ecology = self::kpi($this->provider()->kpisFor(self::ref($world['ecology']), self::now()), 'coverage');
        $protection = self::kpi($this->provider()->kpisFor(self::ref($world['protection']), self::now()), 'coverage');

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
        $coverage = self::kpi($this->provider()->kpisFor(self::ref($world['ecology']), self::now()), 'coverage');

        self::assertNull($coverage->value);
        self::assertFalse($coverage->isKnown());
        self::assertSame("\u{2014}", $coverage->display());
    }

    /**
     * AN AREA-SCOPED DEPARTMENT READS ITS OWN AREA AND NOTHING ELSE.
     *
     * A department confined to one area is not a small view of the organisation's figures — it
     * IS the figures, and another area's patrols are somebody else's work. So the count, the
     * kilometres and the sparkline all stop at the boundary.
     */
    public function testAnAreaScopedDepartmentReadsThatAreasFiguresAlone(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        // One more Ecology patrol, next door: 7 km, and outside this department's remit.
        $this->patrol($second, $world['analyst'], 7.0);
        $this->em->flush();

        $figures = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now()));

        self::assertSame(2.0, $figures['patrols'], 'The seventh kilometre next door is not this area\'s patrol.');
        self::assertSame(22.0, $figures['distance']);

        $elsewhere = self::figures($this->provider()->kpisFor(self::ref($world['ecology'], $second), self::now()));
        self::assertSame(1.0, $elsewhere['patrols']);
        self::assertSame(7.0, $elsewhere['distance']);
    }

    /**
     * AN ORGANISATION-WIDE DEPARTMENT READS ONE ROLL-UP, NOT ONE SET PER AREA.
     *
     * Counts and kilometres are summed across every area; coverage is ONE share of one larger
     * surface (the ground covered over the boundaries walked), which is the only reading of a
     * ratio over two places that means anything.
     */
    public function testAnOrganisationWideDepartmentRollsUpEveryArea(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->patrol($second, $world['analyst'], 7.0);
        $this->em->persist(new Observation($this->patrol($second, $world['analyst'], 3.0), 'sighting')->setRecordedBy($world['analyst']));
        $this->em->flush();

        $figures = self::figures($this->provider()->kpisFor(self::ref($world['ecology']), self::now()));

        // 2 here + 2 next door, 22 km + 10 km, 2 observations here + 1 next door.
        self::assertSame(4.0, $figures['patrols']);
        self::assertSame(32.0, $figures['distance']);
        self::assertSame(3.0, $figures['observations']);
    }

    /**
     * FOUR FIGURES, ONCE, WHATEVER THE SCOPE.
     *
     * The defect this pins: the provider used to append a second and third set of figures, one
     * per area, and a department page then printed "Patrols logged / Distance patrolled /
     * Observations / Coverage" three times under no heading at all.
     */
    public function testFourFiguresAreReportedOnceWhateverTheScope(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->patrol($second, $world['analyst'], 7.0);
        $this->em->flush();

        self::assertSame(
            ['patrols', 'distance', 'observations', 'coverage'],
            self::keys($this->provider()->kpisFor(self::ref($world['ecology']), self::now())),
            'An organisation-wide department reads one roll-up.',
        );
        self::assertSame(
            ['patrols', 'distance', 'observations', 'coverage'],
            self::keys($this->provider()->kpisFor(self::ref($world['ecology'], $world['area']), self::now())),
            'An area-scoped department reads one set too.',
        );
    }

    /** An area the department's people never worked in has nothing to report, not four zeros. */
    public function testAnAreaScopedDepartmentWithNothingRecordedThereReportsNothing(): void
    {
        $world = $this->world();
        $second = $this->secondArea();
        $this->em->flush();

        self::assertSame([], $this->provider()->kpisFor(self::ref($world['ecology'], $second), self::now()));
    }

    public function testCoverageIsTheLastFigureReported(): void
    {
        $world = $this->world();

        self::assertSame(
            ['patrols', 'distance', 'observations', 'coverage'],
            self::keys($this->provider()->kpisFor(self::ref($world['ecology']), self::now())),
        );
    }

    public function testEveryFigureNamesTheModuleTheHostAskedFor(): void
    {
        $world = $this->world();

        foreach ($this->provider()->kpisFor(self::ref($world['ecology']), self::now()) as $kpi) {
            // The host only asks a provider whose slug the department attaches, so a figure
            // captioned with another module's name would be untraceable on the page.
            self::assertSame('patrols', $kpi->moduleSlug);
            self::assertSame('Patrols', $kpi->moduleName);
        }
        self::assertSame('patrols', $this->provider()->moduleSlug());
    }

    /**
     * A department as the CONTRACT hands it over — id, name and uuid, never the
     * entity.
     *
     * This is the shape the core's KPI contract takes, and the reason it
     * takes it: departments belong to TeamBundle and nothing publishes
     * a contract for one, so a signature typed against team's class would make every
     * module that reports a figure hard-require team. Whoever holds the
     * department resolves it to a ref — here, the test playing the surface that
     * renders a performance page.
     *
     * An area handed in confines the department to it; without one the ref is
     * organisation-wide and the figures roll up across every area.
     */
    private static function ref(Department $department, ?AreaOfInterest $area = null): DepartmentRef
    {
        return new DepartmentRef(
            (int) $department->getId(),
            (string) $department->getName(),
            $department->getUuid()?->toRfc4122(),
            $area?->getUuid()?->toRfc4122(),
        );
    }

    /**
     * One area, two departments, five patrols this month and three observations.
     *
     * @return array{area: AreaOfInterest, ecology: Department, protection: Department, analyst: User, ranger: User}
     */
    private function world(): array
    {
        $area = new AreaOfInterest()->setSource('test fixture')
            ->setName('Example reserve')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-29.6,-3.3],[-29.4,-3.3],[-29.4,-3.1],[-29.6,-3.1],[-29.6,-3.3]]]]}');
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

    /** A second boundary next door, so a scope has something to exclude. */
    private function secondArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')
            ->setName('Second reserve')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.6,-2.3],[-30.4,-2.3],[-30.4,-2.1],[-30.6,-2.1],[-30.6,-2.3]]]]}');
        $this->em->persist($area);

        return $area;
    }

    /** One department's PL·03 over the test month, straight from the repository. */
    private function departmentCoverage(Department $department): ?float
    {
        $repository = $this->em->getRepository(Patrol::class);
        \assert($repository instanceof PatrolRepository);

        return $repository->coverageFractionForDepartment(
            null,
            (int) $department->getId(),
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
        $user = new User()->setPassword('x')
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last)
            ->setPosition($position);
        $this->em->persist($user);

        return $user;
    }

    private function patrol(AreaOfInterest $area, ?User $lead, float $km, string $startedAt = '2026-08-05 07:00:00'): Patrol
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
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
     * The department's figures as key => value.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return array<string, float>
     */
    private static function figures(array $kpis): array
    {
        $figures = [];
        foreach ($kpis as $kpi) {
            $figures[$kpi->key] = (float) $kpi->value;
        }

        return $figures;
    }

    /**
     * Every key reported, in order — the assertion that catches a repeated set.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return list<string>
     */
    private static function keys(array $kpis): array
    {
        return array_map(static fn (DepartmentKpi $kpi): string => $kpi->key, $kpis);
    }

    /** @param list<DepartmentKpi> $kpis */
    private static function kpi(array $kpis, string $key): DepartmentKpi
    {
        foreach ($kpis as $kpi) {
            if ($kpi->key === $key) {
                return $kpi;
            }
        }

        self::fail(\sprintf('No "%s" figure was reported.', $key));
    }
}
