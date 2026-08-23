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

namespace UhifadhiLabs\Patrol\Service;

use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Model\PatrolDashboard;

/**
 * Computes the dashboard's data contract from plain entities. Pure — "now" is
 * always injected (never the clock), so every number is unit-tested.
 */
final class PatrolDashboardService
{
    /**
     * Track colours are FIXED hexes, not theme tokens: tracks are drawn over
     * satellite imagery, and a type must read identically on the map, in the
     * legend, in the charts and on the calendar. The design's three, cycled when
     * a deployment configures more than three types.
     *
     * @var list<string>
     */
    public const array TRACK_COLORS = ['#3ED9A8', '#5FA8E0', '#E0954F'];

    private const int WEEKS = 5;
    private const int CALENDAR_CELLS = 42;

    /**
     * The colour every screen draws a patrol type in. Computed once per request
     * and handed to the templates, so the dashboard and the widget library can
     * never colour the same type differently.
     *
     * @param array<string, array{label: string}> $types the deployment's patrol.types map
     *
     * @return array<string, string>
     */
    public static function typeColors(array $types): array
    {
        $colors = [];
        foreach (array_keys($types) as $index => $key) {
            $colors[$key] = self::TRACK_COLORS[$index % \count(self::TRACK_COLORS)];
        }

        return $colors;
    }

    /**
     * @param list<Patrol>                        $patrols latest first
     * @param array<string, array{label: string}> $types   the deployment's patrol.types map
     */
    public function build(array $patrols, array $types, \DateTimeImmutable $now): PatrolDashboard
    {
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $monthStart->modify('+1 month');

        $monthCount = 0;
        $monthDistanceKm = 0.0;
        /** @var array<string, int> $monthTypeCounts */
        $monthTypeCounts = [];
        /** @var array<string, int> $typeCounts */
        $typeCounts = array_fill_keys(array_keys($types), 0);
        /** @var array<string, int> $stationCounts */
        $stationCounts = [];
        $lastPatrol = null;

        foreach ($patrols as $patrol) {
            $typeCounts[$patrol->getType()] = ($typeCounts[$patrol->getType()] ?? 0) + 1;

            $started = $patrol->getStartedAt();
            if (null === $started) {
                continue;
            }
            if (null === $lastPatrol || $started > $lastPatrol->getStartedAt()) {
                $lastPatrol = $patrol;
            }
            if ($started >= $monthStart && $started < $nextMonth) {
                ++$monthCount;
                $monthDistanceKm += $patrol->getDistanceKm() ?? 0.0;
                $monthTypeCounts[$patrol->getType()] = ($monthTypeCounts[$patrol->getType()] ?? 0) + 1;
                $station = $patrol->getStation();
                if (null !== $station && '' !== $station) {
                    $stationCounts[$station] = ($stationCounts[$station] ?? 0) + 1;
                }
            }
        }

        arsort($stationCounts);
        $stationSeries = [];
        foreach ($stationCounts as $station => $count) {
            $stationSeries[] = ['station' => $station, 'count' => $count];
        }

        return new PatrolDashboard(
            patrols: $patrols,
            monthCount: $monthCount,
            monthDistanceKm: $monthDistanceKm,
            monthTypeCounts: $monthTypeCounts,
            typeCounts: $typeCounts,
            totalCount: \count($patrols),
            lastPatrol: $lastPatrol,
            weeklySeries: $this->weeklySeries($patrols, $types, $now),
            stationSeries: $stationSeries,
            stations: array_column($stationSeries, 'station'),
            calendar: $this->calendar($patrols, $now),
        );
    }

    /**
     * @param list<Patrol>                        $patrols
     * @param array<string, array{label: string}> $types
     *
     * @return list<array{label: string, counts: array<string, int>}>
     */
    private function weeklySeries(array $patrols, array $types, \DateTimeImmutable $now): array
    {
        // Five Monday-start weeks, oldest first, the current week last.
        $thisWeekStart = $now->modify('monday this week')->setTime(0, 0);
        $weeks = [];
        for ($i = self::WEEKS - 1; $i >= 0; --$i) {
            $weeks[] = $thisWeekStart->modify(\sprintf('-%d weeks', $i));
        }

        $series = [];
        foreach ($weeks as $index => $weekStart) {
            $weekEnd = $weekStart->modify('+1 week');
            $counts = array_fill_keys(array_keys($types), 0);
            foreach ($patrols as $patrol) {
                $started = $patrol->getStartedAt();
                if (null !== $started && $started >= $weekStart && $started < $weekEnd) {
                    $counts[$patrol->getType()] = ($counts[$patrol->getType()] ?? 0) + 1;
                }
            }
            $series[] = ['label' => 'W'.($index + 1), 'counts' => $counts];
        }

        return $series;
    }

    /**
     * @param list<Patrol> $patrols
     *
     * @return list<array{date: \DateTimeImmutable, patrols: list<Patrol>, today: bool, outside: bool}>
     */
    private function calendar(array $patrols, \DateTimeImmutable $now): array
    {
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $monthStart->modify('+1 month');
        // A Monday-start 6×7 grid always fits a month wherever it begins.
        $gridStart = $monthStart->modify('monday this week');
        $today = $now->format('Y-m-d');

        /** @var array<string, list<Patrol>> $byDay */
        $byDay = [];
        foreach ($patrols as $patrol) {
            $started = $patrol->getStartedAt();
            if (null !== $started) {
                $byDay[$started->format('Y-m-d')][] = $patrol;
            }
        }

        $cells = [];
        for ($i = 0; $i < self::CALENDAR_CELLS; ++$i) {
            $date = $gridStart->modify(\sprintf('+%d days', $i));
            $cells[] = [
                'date' => $date,
                'patrols' => $byDay[$date->format('Y-m-d')] ?? [],
                'today' => $date->format('Y-m-d') === $today,
                'outside' => $date < $monthStart || $date >= $nextMonth,
            ];
        }

        return $cells;
    }
}
