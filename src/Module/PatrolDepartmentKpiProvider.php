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
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\User;
use Uhifadhi\Module\DepartmentKpi;
use Uhifadhi\Module\DepartmentKpiProviderInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;

/**
 * What THIS department did with the Patrols module, this month.
 *
 * The host asks for these only when a department attaches Patrols, so there is no "is it
 * installed" question here — only the slice.
 *
 * THE SLICE, which is the whole point of the class. A patrol is not a department's because of
 * where it happened or who may read it; it is a department's because THE PERSON WHO RECORDED IT
 * holds a position filed under that department. So:
 *
 *   patrol → lead → position → department
 *   observation → recordedBy → position → department
 *
 * Two departments sharing this module therefore read the SAME ROWS and get DIFFERENT NUMBERS,
 * and neither is fenced out of the other's — the split is reporting, never permission. A patrol
 * whose lead is unset, or whose lead holds no position, or whose position is filed under no
 * department, belongs to no department's figures and is silently absent from all of them rather
 * than being shared out among them.
 *
 * `Patrol::$lead` is the recording person for a patrol; the module has no other person-column on
 * it. Observations carry their own `recordedBy`, so an observation logged by a member of another
 * department during a patrol led by this one counts for THEIR department — which is the correct
 * reading of "sliced by the recording person" and not an inconsistency.
 *
 * Coverage (PL·03) is sliced the SAME way, in PostGIS rather than in PHP, because it is a set
 * operation over geometry — see {@see self::coverage()}.
 */
final class PatrolDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    /** How many months of history the sparklines carry, including the current one. */
    private const int SPARK_MONTHS = 6;

    public function __construct(
        private readonly PatrolRepository $patrols,
        private readonly EntityManagerInterface $entityManager,
        /** The slug this module is registered under in the host's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * Patrols logged, distance patrolled, observations recorded and ground covered — as
     * department totals, and, for the three this module can honestly split, once more per area.
     *
     * COVERAGE IS A TOTAL AND ONLY A TOTAL. PL·03 is a share of a surface, and the share of two
     * areas is not the sum, the mean, or a row-per-area of two shares — it is one ratio over one
     * larger surface, which is exactly what {@see PatrolRepository::coverageFractionForDepartment()}
     * answers when asked without an area. The per-area table draws a dash in that column rather
     * than a split nobody can define, which is the honest reading and the one the host's widget
     * already documents.
     *
     * @return list<DepartmentKpi>
     */
    public function kpisFor(Department $department, \DateTimeImmutable $now): array
    {
        $departmentId = $department->getId();
        if (null === $departmentId) {
            return [];
        }

        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);
        $previousStart = $monthStart->modify('-1 month');

        $areas = $this->areasWithPatrols();
        if ([] === $areas) {
            return [];
        }

        $month = $this->tally($areas, $departmentId, $monthStart, $nextMonth);
        $previous = $this->tally($areas, $departmentId, $previousStart, $monthStart);

        // Nothing recorded by this department's people, ever, in any area: report NOTHING rather
        // than three zeros. "This department logged no patrols" and "no patrol here was recorded
        // by this department's people" are the same sentence, and a zero is the wrong way to say
        // either — the host draws a dashed labelled slot instead.
        if (0 === $month->patrols && 0 === $previous->patrols) {
            return [];
        }

        $spark = $this->spark($areas, $departmentId, $monthStart);
        $caption = \sprintf('%s module · %s', $this->name, implode(' · ', array_map(
            static fn (AreaOfInterest $area): string => (string) $area->getName(),
            $areas,
        )));

        $kpis = [
            new DepartmentKpi('patrols', 'Patrols logged', $this->slug, $this->name, (float) $month->patrols, '', (float) $previous->patrols, $spark['patrols'], $caption),
            new DepartmentKpi('distance', 'Distance patrolled', $this->slug, $this->name, $month->distanceKm, 'km', $previous->distanceKm, $spark['distance'], $caption),
            new DepartmentKpi('observations', 'Observations', $this->slug, $this->name, (float) $month->observations, '', (float) $previous->observations, $spark['observations'], $caption),
            new DepartmentKpi(
                'coverage',
                'Coverage',
                $this->slug,
                $this->name,
                $this->coverage($department, $monthStart, $nextMonth),
                DepartmentKpi::SHARE,
                $this->coverage($department, $previousStart, $monthStart),
                $this->coverageSpark($department, $monthStart),
                // Its own provenance line: the buffer is part of what the number MEANS, not a
                // setting, and a share printed without the distance it was measured at is
                // unreadable. The label stays short because a table header wears it too.
                \sprintf('%s · within %s km of a track', $caption, rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1, '.', ''), '0'), '.')),
            ),
        ];

        // The same month again, split by area — the per-area widget's business and nobody else's.
        // Only worth stating when there is more than one area to compare. THREE figures, not
        // four: counts and kilometres add up across areas and a share does not, so coverage is
        // absent here and the table dashes its column rather than inventing a split.
        if (\count($areas) > 1) {
            foreach ($areas as $area) {
                $here = $this->tally([$area], $departmentId, $monthStart, $nextMonth);
                $areaName = (string) $area->getName();
                $kpis[] = new DepartmentKpi('patrols', 'Patrols logged', $this->slug, $this->name, (float) $here->patrols, '', null, [], $caption, $areaName);
                $kpis[] = new DepartmentKpi('distance', 'Distance patrolled', $this->slug, $this->name, $here->distanceKm, 'km', null, [], $caption, $areaName);
                $kpis[] = new DepartmentKpi('observations', 'Observations', $this->slug, $this->name, (float) $here->observations, '', null, [], $caption, $areaName);
            }
        }

        return $kpis;
    }

    /**
     * One window's figures for one department, over the given areas.
     *
     * The window is HALF-OPEN [$from, $until) — the same convention
     * {@see PatrolDashboardService::monthRange()} hands out, so a patrol logged at midnight on
     * the first belongs to exactly one month.
     *
     * @param list<AreaOfInterest> $areas
     */
    private function tally(array $areas, int $departmentId, \DateTimeImmutable $from, \DateTimeImmutable $until): PatrolDepartmentTally
    {
        $patrols = 0;
        $distanceKm = 0.0;
        $observations = 0;

        foreach ($areas as $area) {
            foreach ($this->patrols->findByAreaStartedBetween($area, $from, $until) as $patrol) {
                /*
                 * A DISCARDED patrol contributes nothing here — not its count,
                 * not its kilometres, and not the observations logged on it.
                 *
                 * The observations go too, which is the one part worth saying
                 * out loud, because they are otherwise counted independently of
                 * the patrol (see below). A discard withdraws the whole outing:
                 * the sightings recorded on a patrol that did not happen as
                 * recorded are not this department's evidence either, and
                 * crediting them while dropping the kilometres would produce a
                 * department that observed things on no patrols.
                 *
                 * The repository is asked for the month's patrols unfiltered on
                 * purpose — the calendar reads through the same method and DOES
                 * show discards — so the exclusion is stated here, where the
                 * figures are made.
                 */
                if (!$patrol->getStatus()->countsTowardsStatistics()) {
                    continue;
                }

                if (self::departmentOf($patrol->getLead()) === $departmentId) {
                    ++$patrols;
                    $distanceKm += $patrol->getDistanceKm() ?? 0.0;
                }

                // Counted independently of the patrol: an observation carries its OWN recorder,
                // and a member of another department logging one during this department's patrol
                // is that department's observation.
                foreach ($patrol->getObservations() as $observation) {
                    if (self::departmentOf($observation->getRecordedBy()) === $departmentId) {
                        ++$observations;
                    }
                }
            }
        }

        return new PatrolDepartmentTally($patrols, $distanceKm, $observations);
    }

    /**
     * PL·03 for this department over one window, IN POINTS — 54.0 for 54 %.
     *
     * The repository answers a fraction of 1, because that is what a ratio of two areas is; the
     * seam carries a share as the number a plate prints, because {@see DepartmentKpi::display()}
     * formats the value it is given and {@see DepartmentKpi::delta()} moves a share in POINTS.
     * The conversion belongs here, once, rather than in every surface that reads the figure.
     *
     * Asked WITHOUT an area on purpose — see {@see self::kpisFor()} for why coverage is one
     * ratio over every area the department walked and never a per-area row.
     *
     * Null stays null the whole way: no track recorded by these people in this window is not
     * zero coverage, and the host draws it as a dash.
     */
    private function coverage(Department $department, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        $fraction = $this->patrols->coverageFractionForDepartment(
            null,
            $department,
            PatrolDashboardService::COVERAGE_BUFFER_M,
            $from,
            $until,
        );

        return null === $fraction ? null : $fraction * 100.0;
    }

    /**
     * Six months of coverage for the sparkline — or NOTHING, if any of the six is unknown.
     *
     * A sparkline is a list of floats and has no way to say "we did not measure this month".
     * Substituting 0.0 would draw a plunge to the floor for a month somebody simply logged their
     * patrols by hand, which is a lie in the shape of a chart. So the line is drawn only when
     * every reading in it is real, and otherwise the plate carries its figure without one.
     *
     * @return list<float>
     */
    private function coverageSpark(Department $department, \DateTimeImmutable $monthStart): array
    {
        $series = [];

        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $from = $monthStart->modify(\sprintf('-%d month', $back));
            $reading = $this->coverage($department, $from, $from->modify('+1 month'));
            if (null === $reading) {
                return [];
            }

            $series[] = $reading;
        }

        return $series;
    }

    /**
     * Six months of history, oldest first, for the sparklines — the current month last.
     *
     * @param list<AreaOfInterest> $areas
     *
     * @return array{patrols: list<float>, distance: list<float>, observations: list<float>}
     */
    private function spark(array $areas, int $departmentId, \DateTimeImmutable $monthStart): array
    {
        $series = ['patrols' => [], 'distance' => [], 'observations' => []];

        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $from = $monthStart->modify(\sprintf('-%d month', $back));
            $tally = $this->tally($areas, $departmentId, $from, $from->modify('+1 month'));

            $series['patrols'][] = (float) $tally->patrols;
            $series['distance'][] = $tally->distanceKm;
            $series['observations'][] = (float) $tally->observations;
        }

        return $series;
    }

    /**
     * The areas this module has any patrol in. Asked of the data rather than of the host's
     * area × module table, because a KPI is about rows that exist: an area the module was
     * switched on in yesterday contributes nothing to this month and needs no row.
     *
     * DISCARDED patrols do not make an area countable. An area whose only patrols were
     * thrown away has nothing to report, and letting it in would earn it a per-area row of
     * zeros in the comparison table — a row that reads as "they worked here and achieved
     * nothing" rather than "nothing counted here".
     *
     * @return list<AreaOfInterest>
     */
    private function areasWithPatrols(): array
    {
        /** @var list<AreaOfInterest> $areas */
        $areas = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT a')
            ->from(AreaOfInterest::class, 'a')
            // The same rule tally() applies in PHP, expressed in DQL: only a
            // COMPLETE patrol may put an area on this list. An area whose only
            // patrols were discarded or are still arriving has nothing to
            // report, and listing it would produce exactly the row of zeros
            // this method exists to avoid.
            ->innerJoin(Patrol::class, 'p', 'WITH', 'p.area = a AND p.status = :counted')
            ->setParameter('counted', PatrolStatusEnum::Complete)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $areas;
    }

    /**
     * The department a recorded row belongs to: the recorder's position's department, or null.
     *
     * Null at any link means the row belongs to NO department's figures — not to all of them and
     * not to a default. An unattributed patrol is real work that the org chart cannot yet place,
     * and inventing a placement for it would put someone else's kilometres in this column.
     */
    private static function departmentOf(?User $recorder): ?int
    {
        return $recorder?->getPosition()?->getDepartment()?->getId();
    }
}

/**
 * One window's three figures. A tiny value object rather than an array because it is passed
 * between four methods here and a mistyped key would be a silent wrong number.
 */
final readonly class PatrolDepartmentTally
{
    public function __construct(
        public int $patrols,
        public float $distanceKm,
        public int $observations,
    ) {
    }
}
