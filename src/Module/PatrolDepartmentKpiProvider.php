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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\DepartmentRef;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;

/**
 * What the Patrols module recorded this month IN THE SCOPE A DEPARTMENT READS.
 *
 * The host asks for these only when a department attaches Patrols, so the department itself is
 * no filter: the figures are attributed by SCOPE, never by who recorded them. A ref carrying an
 * `areaUuid` reads every patrol recorded in that area; a ref without one reads the organisation's
 * roll-up across every area. Who led a patrol, whether they hold a position, and which department
 * that position is filed under change nothing — two departments reading the same scope read the
 * same figures.
 */
final class PatrolDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    /** How many months of history the sparklines carry, including the current one. */
    private const int SPARK_MONTHS = 6;

    public function __construct(
        private readonly PatrolRepository $patrols,
        private readonly EntityManagerInterface $entityManager,
        /** The slug this module is registered under in the registry's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * FOUR FIGURES, ONCE: patrols logged, distance patrolled, observations recorded and ground
     * covered, for the scope the ref names and for nothing else.
     *
     * A ref with an `areaUuid` stops at that area's boundary. A ref without one rolls up every
     * area: counts and kilometres summed, the sparklines summed month by month.
     *
     * COVERAGE ROLLS UP AS ONE SHARE OF ONE SURFACE — the ground covered across the areas that
     * recorded a track, over those areas' boundaries added together; see
     * {@see PatrolRepository::coverageFractionAcrossAreas()}.
     *
     * No patrol recorded in the scope this month or last month reports NOTHING rather than four
     * zeros: absent is not zero, and the host draws a dashed labelled slot instead.
     *
     * @return list<DepartmentKpi>
     */
    public function kpisFor(DepartmentRef $department, \DateTimeImmutable $now): array
    {
        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);
        $previousStart = $monthStart->modify('-1 month');

        $areas = $this->areasWithPatrols($department->areaUuid);
        if ([] === $areas) {
            return [];
        }

        $within = null === $department->areaUuid ? null : $areas[0];

        $month = $this->tally($areas, $monthStart, $nextMonth);
        $previous = $this->tally($areas, $previousStart, $monthStart);

        if (0 === $month->patrols && 0 === $previous->patrols) {
            return [];
        }

        $spark = $this->spark($areas, $monthStart);
        $areaNames = array_map(static fn (AreaOfInterest $area): string => (string) $area->getName(), $areas);
        $caption = null === $within
            ? \sprintf('%s module · every patrol recorded across the organisation: %s', $this->name, implode(', ', $areaNames))
            : \sprintf('%s module · every patrol recorded in %s', $this->name, $areaNames[0]);

        return [
            new DepartmentKpi('patrols', 'Patrols logged', $this->slug, $this->name, (float) $month->patrols, '', (float) $previous->patrols, $spark['patrols'], $caption),
            new DepartmentKpi('distance', 'Distance patrolled', $this->slug, $this->name, $month->distanceKm, 'km', $previous->distanceKm, $spark['distance'], $caption),
            new DepartmentKpi('observations', 'Observations', $this->slug, $this->name, (float) $month->observations, '', (float) $previous->observations, $spark['observations'], $caption),
            new DepartmentKpi(
                'coverage',
                'Coverage',
                $this->slug,
                $this->name,
                $this->coverage($within, $monthStart, $nextMonth),
                DepartmentKpi::SHARE,
                $this->coverage($within, $previousStart, $monthStart),
                $this->coverageSpark($within, $monthStart),
                // The buffer is part of what the share MEANS, so it is printed with it; rolled up,
                // the caption says which surface the share is OF, because a reader who is not told
                // will assume a mean of the areas listed.
                \sprintf(
                    '%s · within %s km of a track%s',
                    $caption,
                    rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1, '.', ''), '0'), '.'),
                    null === $within && \count($areas) > 1 ? ', as one share of those boundaries combined' : '',
                ),
            ),
        ];
    }

    /**
     * One window's figures over the given areas. The window is HALF-OPEN [$from, $until) — the
     * convention {@see PatrolDashboardService::monthRange()} hands out.
     *
     * @param list<AreaOfInterest> $areas
     */
    private function tally(array $areas, \DateTimeImmutable $from, \DateTimeImmutable $until): PatrolDepartmentTally
    {
        $patrols = 0;
        $distanceKm = 0.0;
        $observations = 0;

        foreach ($areas as $area) {
            foreach ($this->patrols->findByAreaStartedBetween($area, $from, $until) as $patrol) {
                /*
                 * A DISCARDED or still-RECORDING patrol contributes nothing — not its count, not
                 * its kilometres, and not the observations logged on it: a discard withdraws the
                 * whole outing, and a patrol still arriving is not all here yet.
                 *
                 * The repository is asked for the month's patrols unfiltered on purpose — the
                 * calendar reads through the same method and DOES show discards — so the
                 * exclusion is stated here, where the figures are made.
                 */
                if (!$patrol->getStatus()->countsTowardsStatistics()) {
                    continue;
                }

                ++$patrols;
                $distanceKm += $patrol->getDistanceKm() ?? 0.0;
                $observations += $patrol->getObservations()->count();
            }
        }

        return new PatrolDepartmentTally($patrols, $distanceKm, $observations);
    }

    /**
     * PL·03 over one window, IN POINTS — 54.0 for 54 %.
     *
     * The repository answers a fraction of 1; the contract carries a share as the number a plate
     * prints, because {@see DepartmentKpi::display()} formats the value it is given and
     * {@see DepartmentKpi::delta()} moves a share in POINTS.
     *
     * `$within` null asks across every area as ONE ratio. Null stays null the whole way: no track
     * recorded in the window is not zero coverage, and the host draws it as a dash.
     */
    private function coverage(?AreaOfInterest $within, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        $fraction = null === $within
            ? $this->patrols->coverageFractionAcrossAreas(PatrolDashboardService::COVERAGE_BUFFER_M, $from, $until)
            : $this->patrols->coverageFractionWithin($within, PatrolDashboardService::COVERAGE_BUFFER_M, $from, $until);

        return null === $fraction ? null : $fraction * 100.0;
    }

    /**
     * Six months of coverage for the sparkline — or NOTHING, if any of the six is unknown.
     *
     * A sparkline has no way to say "we did not measure this month", and substituting 0.0 would
     * draw a plunge for a month whose patrols were logged by hand. So the line is drawn only when
     * every reading in it is real.
     *
     * @return list<float>
     */
    private function coverageSpark(?AreaOfInterest $within, \DateTimeImmutable $monthStart): array
    {
        $series = [];

        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $from = $monthStart->modify(\sprintf('-%d month', $back));
            $reading = $this->coverage($within, $from, $from->modify('+1 month'));
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
    private function spark(array $areas, \DateTimeImmutable $monthStart): array
    {
        $series = ['patrols' => [], 'distance' => [], 'observations' => []];

        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $from = $monthStart->modify(\sprintf('-%d month', $back));
            $tally = $this->tally($areas, $from, $from->modify('+1 month'));

            $series['patrols'][] = (float) $tally->patrols;
            $series['distance'][] = $tally->distanceKm;
            $series['observations'][] = (float) $tally->observations;
        }

        return $series;
    }

    /**
     * The areas in scope that hold any counted patrol — the one the ref named, or every one.
     * Asked of the data rather than of the host's area × module table, because a KPI is about
     * rows that exist.
     *
     * An `$areaUuid` that is not a uuid, or names no area, leaves the list EMPTY and the
     * department reports nothing: a scope nobody can resolve must not silently widen to the whole
     * organisation.
     *
     * @return list<AreaOfInterest>
     */
    private function areasWithPatrols(?string $areaUuid): array
    {
        if (null !== $areaUuid && !Uuid::isValid($areaUuid)) {
            return [];
        }

        $query = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT a')
            ->from(AreaOfInterest::class, 'a')
            ->innerJoin(Patrol::class, 'p', 'WITH', 'p.area = a AND p.status = :counted')
            ->setParameter('counted', PatrolStatusEnum::Complete)
            ->orderBy('a.name', 'ASC');

        if (null !== $areaUuid) {
            $query->andWhere('a.uuid = :area')->setParameter('area', Uuid::fromString($areaUuid));
        }

        /** @var list<AreaOfInterest> $areas */
        $areas = $query->getQuery()->getResult();

        return $areas;
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
