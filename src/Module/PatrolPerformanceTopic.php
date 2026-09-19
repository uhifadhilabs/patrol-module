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

namespace Uhifadhi\Patrol\Module;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartKind;
use Uhifadhi\Contracts\Performance\ChartSeries;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicChart;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Contracts\Performance\TopicMatrix;
use Uhifadhi\Patrol\Model\PatrolTopicGround;
use Uhifadhi\Patrol\Model\PatrolTopicSlice;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolFigureService;

/**
 * THE PATROLS TOPIC ON THE PERFORMANCE PAGE — five headline figures, two
 * charts, and a matrix of only the departments that read this module.
 *
 * THE HISTORY IS COMPUTED FROM THIS MODULE'S OWN RECORDS, not read back out of
 * the core's period ledger. The core's own topics have to read theirs — how
 * many seats were filled in July cannot be recomputed from people who have
 * since moved — but a patrol carries the instant it started and the kilometres
 * it ran, so every past period is still here and re-measuring it gives the
 * same answer today as it did then. Nothing here writes a figure down, and
 * nothing here can go stale.
 *
 * A DEPARTMENT IS A LENS OVER GROUND, NEVER A FILTER ON RECORDS. Who led a
 * patrol, whether they hold a position and which department that position is
 * filed under change no figure on this page — exactly as
 * {@see PatrolDepartmentKpiProvider} states for the KPI plates. All a
 * department contributes to a row is HOW MUCH GROUND it reads, which
 * {@see PatrolTopicSlice} resolves as the intersection of its scope with the
 * page's.
 *
 * THE THREE ABSENCES ARE KEPT APART, and each has exactly one cause here:
 *
 * - a NULL VALUE is a figure this module cannot measure for that ground in
 *   that period — coverage where no track was recorded, and every figure of a
 *   scope where no area runs this module at all;
 * - a HOLE IN A HISTORY is a period this module was not yet installed over
 *   that ground, so nobody was recording: a nought there would draw a collapse
 *   where there was simply no module;
 * - {@see MatrixCell::notMine()} is a department that attaches Patrols in the
 *   register while no area it reads actually runs it. The columns are not its
 *   to answer, and no amount of publishing by this module will make them so.
 *
 * SCOPE IS OBEYED, NOT ASSUMED. Every figure is the intersection of the page's
 * scope with the row's, so an area's page never draws the organisation's
 * numbers.
 */
final readonly class PatrolPerformanceTopic implements PerformanceTopicProviderInterface
{
    /** What a sparkline and a matrix cell's run are drawn over. */
    public const int PERIODS = 6;

    /** What the charts are drawn over — a full year of the page's period. */
    public const int CHART_PERIODS = 12;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatrolFigureService $figures,
        /** The slug this module is registered under in the registry's catalogue. */
        private string $slug,
        private string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function key(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->name;
    }

    /**
     * THE FOUR COLUMNS THIS MODULE PUBLISHES, AND WHICH WAY EACH IS GOOD.
     *
     * Static because a column's polarity is a property of the figure and not
     * of a request: more patrols, more kilometres, more ground covered and more
     * observations recorded are all more of the work this module exists to
     * measure, so all four judge upwards. A column that made no claim would be
     * drawn untinted and its movement uncoloured, which is a different page —
     * so none of them is {@see ColumnPolarity::None} by omission.
     *
     * @return list<MatrixColumn>
     */
    public static function columns(): array
    {
        return [
            new MatrixColumn('patrols.patrols', 'Patrols', '', ColumnPolarity::Up,
                'Patrols that went out and were recorded in the period.'),
            new MatrixColumn('patrols.distance', 'Distance', 'km', ColumnPolarity::Up,
                'The kilometres those patrols ran.'),
            new MatrixColumn('patrols.coverage', 'Coverage', '%', ColumnPolarity::Up,
                'The share of the ground lying within reach of a recorded track.'),
            new MatrixColumn('patrols.observations', 'Observations', '', ColumnPolarity::Up,
                'What was logged en route.'),
        ];
    }

    public function kpis(PerformanceScope $scope, FigurePeriod $period): array
    {
        $ground = $this->groundOf($scope->areaUuid);
        if ($ground->isUnrun()) {
            return $this->nothingRunsHere($scope);
        }

        $run = self::run($period, self::PERIODS);
        $previous = $period->previous();

        $patrols = [];
        $distance = [];
        $observations = [];
        $coverage = [];
        $out = [];
        foreach ($run as $past) {
            $tally = $ground->measured($past) ? $this->figures->tally($ground->areas, $past->from, $past->until) : null;

            $patrols[] = null === $tally ? null : (float) $tally->patrols;
            $distance[] = $tally?->distanceKm;
            $observations[] = null === $tally ? null : (float) $tally->observations;
            $coverage[] = $ground->measured($past) ? $this->figures->coverage($ground->within, $past->from, $past->until) : null;
            $out[] = $ground->measured($past) ? (float) $this->figures->outAt($ground->areas, $past->until) : null;
        }

        $now = $this->figures->tally($ground->areas, $period->from, $period->until);
        $was = $this->figures->tally($ground->areas, $previous->from, $previous->until);
        $share = $this->figures->coverage($ground->within, $period->from, $period->until);
        $wasShare = $this->figures->coverage($ground->within, $previous->from, $previous->until);
        $outNow = $this->figures->outAt($ground->areas, $period->until);
        $wasOut = $this->figures->outAt($ground->areas, $previous->until);
        $rows = \count($this->rowsIn($scope));

        return [
            new TopicKpi(
                key: 'patrols.patrols',
                label: 'Patrols',
                value: (float) $now->patrols,
                delta: (float) ($now->patrols - $was->patrols),
                history: $patrols,
                caption: \sprintf('across %d department%s that read %s', $rows, 1 === $rows ? '' : 's', $this->name),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'patrols.distance',
                label: 'Distance',
                value: $now->distanceKm,
                unit: 'km',
                delta: $now->distanceKm - $was->distanceKm,
                history: $distance,
                caption: 0 === $now->patrols ? '' : \sprintf('%s km a patrol', self::plainly($now->distanceKm / $now->patrols, 1)),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'patrols.coverage',
                label: 'Coverage',
                value: $share,
                unit: '%',
                delta: null === $share || null === $wasShare ? null : $share - $wasShare,
                history: $coverage,
                caption: $this->coverageCaption($ground),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'patrols.observations',
                label: 'Observations',
                value: (float) $now->observations,
                delta: (float) ($now->observations - $was->observations),
                history: $observations,
                caption: 0 === $now->patrols ? '' : \sprintf('%s a patrol', self::plainly($now->observations / $now->patrols, 1)),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'patrols.out_now',
                label: 'Out right now',
                value: (float) $outNow,
                delta: (float) ($outNow - $wasOut),
                history: $out,
                caption: $this->outCaption($ground, $period),
                polarity: ColumnPolarity::Up,
            ),
        ];
    }

    public function charts(PerformanceScope $scope, FigurePeriod $period): array
    {
        $run = self::run($period, self::CHART_PERIODS);
        $labels = array_map(static fn (FigurePeriod $past): string => mb_strtolower($past->from->format('M')), $run);

        return [
            $this->distanceByDepartment($scope, $run, $labels),
            $this->coverageOverTime($scope, $run, $labels),
        ];
    }

    public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix
    {
        $run = self::run($period, self::PERIODS);

        $rows = [];
        foreach ($this->rowsIn($scope) as $department) {
            $slice = PatrolTopicSlice::of($scope->areaUuid, $department->getArea()?->getUuidString());
            \assert(null !== $slice);

            $rows[] = new MatrixRow(
                departmentUuid: (string) $department->getUuidString(),
                departmentName: (string) $department->getName(),
                cells: $this->cellsFor($this->groundOf($slice->areaUuid), $period, $run),
                band: null === $department->getArea() ? 'Org-wide' : (string) $department->getArea()->getName(),
            );
        }

        return new TopicMatrix(
            self::columns(),
            $rows,
            \sprintf('Only the departments that read the %s module are rows, and each reads the ground its own scope covers.', $this->name),
        );
    }

    /**
     * ONE DEPARTMENT'S FOUR CELLS.
     *
     * Ground no running area falls on is four `notMine` cells, not four
     * dashes: the department attached this module in the register, but nothing
     * it reads is running it, so the columns are not its to answer.
     *
     * @param list<FigurePeriod> $run
     *
     * @return array<string, MatrixCell>
     */
    private function cellsFor(PatrolTopicGround $ground, FigurePeriod $period, array $run): array
    {
        if ($ground->isUnrun()) {
            $cells = [];
            foreach (self::columns() as $column) {
                $cells[$column->key] = MatrixCell::notMine();
            }

            return $cells;
        }

        $patrols = [];
        $distance = [];
        $observations = [];
        $coverage = [];
        foreach ($run as $past) {
            $tally = $ground->measured($past) ? $this->figures->tally($ground->areas, $past->from, $past->until) : null;

            $patrols[] = null === $tally ? null : (float) $tally->patrols;
            $distance[] = $tally?->distanceKm;
            $observations[] = null === $tally ? null : (float) $tally->observations;
            $coverage[] = $ground->measured($past) ? $this->figures->coverage($ground->within, $past->from, $past->until) : null;
        }

        $previous = $period->previous();
        $now = $this->figures->tally($ground->areas, $period->from, $period->until);
        $was = $this->figures->tally($ground->areas, $previous->from, $previous->until);
        $share = $this->figures->coverage($ground->within, $period->from, $period->until);
        $wasShare = $this->figures->coverage($ground->within, $previous->from, $previous->until);

        return [
            'patrols.patrols' => new MatrixCell((float) $now->patrols, (float) ($now->patrols - $was->patrols), $patrols),
            'patrols.distance' => new MatrixCell($now->distanceKm, $now->distanceKm - $was->distanceKm, $distance),
            'patrols.coverage' => new MatrixCell(
                $share,
                null === $share || null === $wasShare ? null : $share - $wasShare,
                $coverage,
            ),
            'patrols.observations' => new MatrixCell((float) $now->observations, (float) ($now->observations - $was->observations), $observations),
        ];
    }

    /**
     * ONE LINE PER DEPARTMENT, over the chart's run — the comparison the design
     * asks for, and part of why a topic owns its own charts: only this module
     * knows that its departments differ by how much ground each reads.
     *
     * @param list<FigurePeriod> $run
     * @param list<string>       $labels
     */
    private function distanceByDepartment(PerformanceScope $scope, array $run, array $labels): TopicChart
    {
        $series = [];
        $reads = [];

        foreach ($this->rowsIn($scope) as $department) {
            $slice = PatrolTopicSlice::of($scope->areaUuid, $department->getArea()?->getUuidString());
            \assert(null !== $slice);

            $ground = $this->groundOf($slice->areaUuid);
            if ($ground->isUnrun()) {
                continue;
            }

            $points = [];
            foreach ($run as $past) {
                $points[] = $ground->measured($past)
                    ? $this->figures->tally($ground->areas, $past->from, $past->until)->distanceKm
                    : null;
            }

            $name = (string) $department->getName();
            $series[] = new ChartSeries($name, $points);
            $reads[] = \sprintf('%s reads %d area%s', $name, \count($ground->areas), 1 === \count($ground->areas) ? '' : 's');
        }

        return new TopicChart(
            key: 'patrols.distance_by_department',
            title: 'Distance, by department, per period',
            kind: ChartKind::Line,
            labels: $labels,
            series: $series,
            unit: 'km',
            caption: implode(' · ', $reads),
        );
    }

    /**
     * THE SHARE OF THE GROUND WALKED, PERIOD BY PERIOD.
     *
     * NO TARGET LINE IS DRAWN, because nothing declares one. A target is what
     * somebody committed to; this module records no commitment, and inventing a
     * round number to draw a line against would be the chart asserting a
     * promise nobody made.
     *
     * @param list<FigurePeriod> $run
     * @param list<string>       $labels
     */
    private function coverageOverTime(PerformanceScope $scope, array $run, array $labels): TopicChart
    {
        $ground = $this->groundOf($scope->areaUuid);

        $points = [];
        foreach ($run as $past) {
            $points[] = !$ground->isUnrun() && $ground->measured($past)
                ? $this->figures->coverage($ground->within, $past->from, $past->until)
                : null;
        }

        return new TopicChart(
            key: 'patrols.coverage',
            title: 'Coverage over time',
            kind: ChartKind::Line,
            labels: $labels,
            series: [new ChartSeries('Coverage', $points)],
            unit: '%',
            caption: 'No target is declared for coverage, so none is drawn.',
        );
    }

    /**
     * THE DEPARTMENTS THIS PAGE HOLDS — the ones that attach this module and
     * whose scope the page's scope reaches.
     *
     * A department that attaches nothing of this module's is not a row of
     * empties here, it is not a row: that is the whole difference between a
     * topic and the board of everybody's columns it replaces.
     *
     * @return list<Department>
     */
    private function rowsIn(PerformanceScope $scope): array
    {
        /** @var list<Department> $attaching */
        $attaching = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Department::class, 'd')
            ->innerJoin('d.modules', 'm')
            ->andWhere('m.slug = :slug')
            ->andWhere('d.active = true')
            ->setParameter('slug', $this->slug)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $attaching,
            static fn (Department $department): bool => null !== PatrolTopicSlice::of($scope->areaUuid, $department->getArea()?->getUuidString()),
        ));
    }

    /**
     * THE GROUND OF ONE SLICE — the areas of it that actually run this module,
     * by name, and the instant recording began over them.
     *
     * Read from the registry's area × module ledger rather than from the
     * patrols table, and deliberately not the question
     * {@see PatrolDepartmentKpiProvider} asks: a KPI plate is about rows that
     * exist, while a matrix row has to tell "this ground runs Patrols and
     * recorded nothing" from "this ground does not run Patrols at all", and
     * only the ledger knows the second.
     */
    private function groundOf(?string $areaUuid): PatrolTopicGround
    {
        /** @var list<AreaModule> $installed */
        $installed = $this->entityManager->createQueryBuilder()
            ->select('am')
            ->from(AreaModule::class, 'am')
            ->innerJoin('am.module', 'm')
            ->andWhere('m.slug = :slug')
            ->andWhere('am.active = true')
            ->setParameter('slug', $this->slug)
            ->getQuery()
            ->getResult();

        $areas = [];
        $within = null;
        $measuredFrom = null;
        $unbounded = false;

        foreach ($installed as $row) {
            $area = $row->getArea();
            if (!$area instanceof AreaOfInterest) {
                continue;
            }

            if (null !== $areaUuid && $areaUuid !== $area->getUuidString()) {
                continue;
            }

            $areas[] = $area;
            if (null !== $areaUuid) {
                $within = $area;
            }

            $installedAt = $row->getInstalledAt();
            if (null === $installedAt) {
                $unbounded = true;
                continue;
            }

            $measuredFrom = null === $measuredFrom || $installedAt < $measuredFrom ? $installedAt : $measuredFrom;
        }

        usort($areas, static fn (AreaOfInterest $a, AreaOfInterest $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

        return new PatrolTopicGround($areas, $within, $unbounded ? null : $measuredFrom);
    }

    /**
     * FIVE FIGURES THAT SAY THEY HAVE NOTHING, for a scope where no area runs
     * this module. A row of none where the page draws five is a different page,
     * and a reader cannot tell a missing topic from a quiet month.
     *
     * @return list<TopicKpi>
     */
    private function nothingRunsHere(PerformanceScope $scope): array
    {
        $why = \sprintf('no area of %s runs the %s module', mb_strtolower($scope->label), $this->name);

        return [
            new TopicKpi('patrols.patrols', 'Patrols', null, caption: $why, polarity: ColumnPolarity::Up),
            new TopicKpi('patrols.distance', 'Distance', null, 'km', caption: $why, polarity: ColumnPolarity::Up),
            new TopicKpi('patrols.coverage', 'Coverage', null, '%', caption: $why, polarity: ColumnPolarity::Up),
            new TopicKpi('patrols.observations', 'Observations', null, caption: $why, polarity: ColumnPolarity::Up),
            new TopicKpi('patrols.out_now', 'Out right now', null, caption: $why, polarity: ColumnPolarity::Up),
        ];
    }

    /**
     * What the coverage share is OF — the buffer is part of what it MEANS, so
     * it is printed with it, and a roll-up says which surface it is a share of,
     * because a reader who is not told will assume a mean of the areas.
     */
    private function coverageCaption(PatrolTopicGround $ground): string
    {
        $buffer = rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1, '.', ''), '0'), '.');

        return \sprintf(
            'within %s km of a track%s',
            $buffer,
            null === $ground->within && \count($ground->areas) > 1 ? ', as one share of those boundaries combined' : '',
        );
    }

    /**
     * WHERE THE OPEN PATROLS ARE, named area by area — and WHEN it was asked,
     * because "right now" on a page about a period that has closed means the
     * instant that period ended.
     */
    private function outCaption(PatrolTopicGround $ground, FigurePeriod $period): string
    {
        $where = [];
        foreach ($ground->areas as $area) {
            $out = $this->figures->outAt([$area], $period->until);
            if (0 !== $out) {
                $where[] = \sprintf('%d %s', $out, (string) $area->getName());
            }
        }

        return [] === $where ? \sprintf('nobody out at the close of %s', $period->label) : implode(' · ', $where);
    }

    /**
     * THE RUN A FIGURE IS DRAWN OVER — so many periods ending at this one,
     * oldest first, each the same length as the one the page asked for.
     *
     * Stepped back through {@see FigurePeriod::previous()} rather than assumed
     * to be months, so a page reading quarters gets quarters.
     *
     * @return list<FigurePeriod>
     */
    private static function run(FigurePeriod $period, int $count): array
    {
        $run = [$period];
        for ($step = 1; $step < $count; ++$step) {
            array_unshift($run, $run[0]->previous());
        }

        return $run;
    }

    private static function plainly(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, '.', ',');
    }
}
