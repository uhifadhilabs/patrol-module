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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\DepartmentRef;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;

/**
 * What THIS department did with the Patrols module, this month, ANYWHERE THE REF ALLOWS.
 *
 * The host asks for these only when a department attaches Patrols, so there is no "is it
 * installed" question here — only the slice and the scope. The scope is the ref's `areaUuid`: one
 * area for a department confined to it, every area for an organisation-wide one, and one set of
 * four figures either way — see {@see self::kpisFor()}.
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

    /** @var array<int, int>|null user id → department id, read once per request */
    private ?array $departmentByUser = null;

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
     * THE SCOPE IS THE REF'S, NOT THE INSTALLATION'S. A ref carrying an `areaUuid` is a
     * department confined to that area, and its figures stop at that boundary — another area's
     * patrols are somebody else's work, not a smaller view of the same work. A ref without one is
     * organisation-wide and reads the roll-up across every area: counts and kilometres summed,
     * the sparklines summed the same way month by month.
     *
     * COVERAGE ROLLS UP AS ONE SHARE OF ONE SURFACE — the ground covered across the areas the
     * department walked, over those areas' boundaries added together. Not the sum of two shares,
     * which can exceed 1, and not their unweighted mean, which would let a pond outvote a
     * province; a big area and a small one contribute in proportion to their size, which is what
     * {@see PatrolRepository::coverageFractionForDepartment()} already answers when asked without
     * an area, and what the plate's caption says out loud. Areas the department never set foot in
     * are in neither sum rather than dragging the figure towards zero.
     *
     * @return list<DepartmentKpi>
     */
    public function kpisFor(DepartmentRef $department, \DateTimeImmutable $now): array
    {
        $departmentId = $department->id;

        [$monthStart, $nextMonth] = PatrolDashboardService::monthRange($now);
        $previousStart = $monthStart->modify('-1 month');

        $areas = $this->areasWithPatrols($department->areaUuid);
        if ([] === $areas) {
            return [];
        }

        // The area coverage is measured over: the one the ref confines the department to, or
        // every area it walked, folded into a single ratio by the repository.
        $within = null === $department->areaUuid ? null : $areas[0];

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

        return [
            new DepartmentKpi('patrols', 'Patrols logged', $this->slug, $this->name, (float) $month->patrols, '', (float) $previous->patrols, $spark['patrols'], $caption),
            new DepartmentKpi('distance', 'Distance patrolled', $this->slug, $this->name, $month->distanceKm, 'km', $previous->distanceKm, $spark['distance'], $caption),
            new DepartmentKpi('observations', 'Observations', $this->slug, $this->name, (float) $month->observations, '', (float) $previous->observations, $spark['observations'], $caption),
            new DepartmentKpi(
                'coverage',
                'Coverage',
                $this->slug,
                $this->name,
                $this->coverage($within, $departmentId, $monthStart, $nextMonth),
                DepartmentKpi::SHARE,
                $this->coverage($within, $departmentId, $previousStart, $monthStart),
                $this->coverageSpark($within, $departmentId, $monthStart),
                // Its own provenance line: the buffer is part of what the number MEANS, not a
                // setting, and a share printed without the distance it was measured at is
                // unreadable. Rolled up, it says which surface the share is OF, because a
                // reader who is not told will assume a mean of the areas listed beside it.
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

                if ($this->departmentOf($patrol->getLead()) === $departmentId) {
                    ++$patrols;
                    $distanceKm += $patrol->getDistanceKm() ?? 0.0;
                }

                // Counted independently of the patrol: an observation carries its OWN recorder,
                // and a member of another department logging one during this department's patrol
                // is that department's observation.
                foreach ($patrol->getObservations() as $observation) {
                    if ($this->departmentOf($observation->getRecordedBy()) === $departmentId) {
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
     * contract carries a share as the number a plate prints, because {@see DepartmentKpi::display()}
     * formats the value it is given and {@see DepartmentKpi::delta()} moves a share in POINTS.
     * The conversion belongs here, once, rather than in every surface that reads the figure.
     *
     * `$within` null asks across every area the department walked, as ONE ratio — see
     * {@see self::kpisFor()} for why that is the only honest roll-up of a share.
     *
     * Null stays null the whole way: no track recorded by these people in this window is not
     * zero coverage, and the host draws it as a dash.
     */
    private function coverage(?AreaOfInterest $within, int $departmentId, \DateTimeImmutable $from, \DateTimeImmutable $until): ?float
    {
        $fraction = $this->patrols->coverageFractionForDepartment(
            $within,
            $departmentId,
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
    private function coverageSpark(?AreaOfInterest $within, int $departmentId, \DateTimeImmutable $monthStart): array
    {
        $series = [];

        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $from = $monthStart->modify(\sprintf('-%d month', $back));
            $reading = $this->coverage($within, $departmentId, $from, $from->modify('+1 month'));
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
     * The areas in scope that this module has any patrol in — one, when the ref named it, and
     * otherwise every one of them. Asked of the data rather than of the host's area × module
     * table, because a KPI is about rows that exist: an area the module was switched on in
     * yesterday contributes nothing to this month and needs no row.
     *
     * DISCARDED patrols do not make an area countable. An area whose only patrols were thrown
     * away has nothing to report, and letting it in would earn the department four zeros — which
     * read as "they worked here and achieved nothing" rather than "nothing counted here".
     *
     * An `$areaUuid` that is not a uuid, or names no area, leaves the list EMPTY and the
     * department reports nothing. That is the same answer as an area with no patrols, and it is
     * the right one: a scope nobody can resolve must not silently widen to the whole
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
            // The same rule tally() applies in PHP, expressed in DQL: only a
            // COMPLETE patrol may put an area on this list. An area whose only
            // patrols were discarded or are still arriving has nothing to
            // report, and listing it would produce exactly the row of zeros
            // this method exists to avoid.
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

    /**
     * The department a recorded row belongs to: the recorder's position's department, or null.
     *
     * Null at any link means the row belongs to NO department's figures — not to all of them and
     * not to a default. An unattributed patrol is real work that the org chart cannot yet place,
     * and inventing a placement for it would put someone else's kilometres in this column.
     *
     * THE WALK IS DONE IN DQL, NOT THROUGH THE OBJECT, and it has to be. The module points at a
     * person through {@see UserInterface}, which describes who a
     * record is about and deliberately says nothing about the org chart — there is no published
     * contract for "the position somebody holds" or "the department it is filed under". So this
     * reads the chart the same way {@see PatrolRepository::coverageFractionForDepartment()} reads
     * it: off the mapping, through the class the installation resolved the contract to. Asking the
     * object for a `getPosition()` the contract does not promise would be a module that only works
     * against one account class, which is the thing the contract exists to prevent.
     */
    private function departmentOf(?UserInterface $recorder): ?int
    {
        $id = $recorder?->getId();

        return null !== $id ? ($this->departmentByUser()[$id] ?? null) : null;
    }

    /**
     * user id → department id, for everybody who holds a position filed under one.
     *
     * ONE QUERY, memoized: the figures walk every patrol and every observation in a month and ask
     * this of each recorder, and a lazy association per row would be a page of round-trips. Anyone
     * absent from the map holds no position, or a position under no department, and is therefore
     * in nobody's figures.
     *
     * @return array<int, int>
     */
    private function departmentByUser(): array
    {
        if (null !== $this->departmentByUser) {
            return $this->departmentByUser;
        }

        $accountClass = $this->entityManager->getClassMetadata(Patrol::class)->getAssociationTargetClass('lead');

        /** @var list<array{id: int, department: int|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('u.id AS id', 'IDENTITY(p.department) AS department')
            ->from($accountClass, 'u')
            ->innerJoin('u.position', 'p')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            if (null !== $row['department']) {
                $map[$row['id']] = $row['department'];
            }
        }

        return $this->departmentByUser = $map;
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
