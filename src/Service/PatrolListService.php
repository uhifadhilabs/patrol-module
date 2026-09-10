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

namespace Uhifadhi\Patrol\Service;

use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Model\PatrolFilter;
use Uhifadhi\Patrol\Model\PatrolList;

/**
 * THE FULL LOG, ANSWERED SERVER-SIDE — the rows one page shows, the counts
 * beside every option, and where in the answer the reader is.
 *
 * ONE GET DRIVES EVERYTHING. Narrowing in the browser would make the counts
 * describe a set nobody is looking at, lose the choice on reload and leave the
 * pager counting rows it cannot see. So this takes the month's patrols and one
 * {@see PatrolFilter}, and every number on the page comes back out of here.
 *
 * IT IS PURE. No clock, no repository, no request: the caller loads the month
 * and this decides what of it is on screen, which is what makes the whole page
 * unit-testable without a database.
 */
final readonly class PatrolListService
{
    /** How many rows a page of the full log shows, as the design's pager says. */
    public const int PER_PAGE = 20;

    /**
     * @param list<Patrol>                        $patrols     the month's patrols, newest first
     * @param array<string, string>               $patrolZones patrol uuid → the zone its track set out in
     * @param array<string, array{label: string}> $types       the deployment's patrol vocabulary
     */
    public function build(array $patrols, array $patrolZones, array $types, PatrolFilter $filter, int $page): PatrolList
    {
        $matched = array_values(array_filter(
            $patrols,
            fn (Patrol $patrol): bool => $this->matches($patrol, $patrolZones, $filter),
        ));

        $total = \count($matched);
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pageCount));
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = \array_slice($matched, $offset, self::PER_PAGE);

        return new PatrolList(
            patrols: $rows,
            totalCount: $total,
            /*
             * WHAT CLEARING ONE AXIS WOULD SHOW. Each option's count is the
             * answer with its OWN axis replaced and every other axis left
             * standing, so the number beside a chip is a promise about what
             * happens when it is clicked.
             */
            allCount: $this->countWith($patrols, $patrolZones, $filter->withoutType()),
            typeCounts: $this->countsBy(
                array_keys($types),
                static fn (string $key): PatrolFilter => $filter->onlyType($key),
                $patrols,
                $patrolZones,
            ),
            stationCounts: $this->countsBy(
                $stations = $this->stationsOf($patrols),
                static fn (string $station): PatrolFilter => $filter->onlyStation($station),
                $patrols,
                $patrolZones,
            ),
            zoneCounts: $this->countsBy(
                $zones = $this->zonesOf($patrols, $patrolZones),
                static fn (string $zone): PatrolFilter => $filter->onlyZone($zone),
                $patrols,
                $patrolZones,
            ),
            stations: $stations,
            zones: $zones,
            patrolZones: $patrolZones,
            page: $page,
            pageCount: $pageCount,
            firstIndex: 0 === $total ? 0 : $offset + 1,
            lastIndex: $offset + \count($rows),
        );
    }

    /**
     * WHAT A SEARCH READS OF A PATROL — its id, its lead, the station it set
     * off from, its own note and the notes its observations carry. The design's
     * caption names exactly these, and this is the one place they are named.
     *
     * @return list<string|null>
     */
    public static function searchableFields(Patrol $patrol): array
    {
        $fields = [
            $patrol->getRef(),
            $patrol->getDisplayName(),
            self::leadName($patrol),
            $patrol->getStation(),
            $patrol->getNote(),
        ];

        foreach ($patrol->getObservations() as $observation) {
            $fields[] = $observation->getNote();
            $fields[] = $observation->getCategory();
        }

        return $fields;
    }

    /**
     * @param array<string, string> $patrolZones
     */
    private function matches(Patrol $patrol, array $patrolZones, PatrolFilter $filter): bool
    {
        return $filter->matches(
            $patrol->getType(),
            $patrol->getStation() ?? '',
            $patrolZones[$patrol->getUuid()->toRfc4122()] ?? '',
        ) && $filter->matchesSearch(self::searchableFields($patrol));
    }

    /**
     * @param list<Patrol>          $patrols
     * @param array<string, string> $patrolZones
     */
    private function countWith(array $patrols, array $patrolZones, PatrolFilter $filter): int
    {
        $count = 0;
        foreach ($patrols as $patrol) {
            if ($this->matches($patrol, $patrolZones, $filter)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<string>                   $values
     * @param \Closure(string): PatrolFilter $narrow
     * @param list<Patrol>                   $patrols
     * @param array<string, string>          $patrolZones
     *
     * @return array<string, int>
     */
    private function countsBy(array $values, \Closure $narrow, array $patrols, array $patrolZones): array
    {
        $counts = [];
        foreach ($values as $value) {
            $counts[$value] = $this->countWith($patrols, $patrolZones, $narrow($value));
        }

        return $counts;
    }

    /**
     * @param list<Patrol> $patrols
     *
     * @return list<string>
     */
    private function stationsOf(array $patrols): array
    {
        $stations = [];
        foreach ($patrols as $patrol) {
            $station = $patrol->getStation();
            if (null !== $station && '' !== $station) {
                $stations[$station] = true;
            }
        }

        ksort($stations);

        return array_keys($stations);
    }

    /**
     * @param list<Patrol>          $patrols
     * @param array<string, string> $patrolZones
     *
     * @return list<string>
     */
    private function zonesOf(array $patrols, array $patrolZones): array
    {
        $zones = [];
        foreach ($patrols as $patrol) {
            $zone = $patrolZones[$patrol->getUuid()->toRfc4122()] ?? '';
            if ('' !== $zone) {
                $zones[$zone] = true;
            }
        }

        ksort($zones);

        return array_keys($zones);
    }

    private static function leadName(Patrol $patrol): ?string
    {
        $lead = $patrol->getLead();

        return $lead?->getFullName();
    }
}
