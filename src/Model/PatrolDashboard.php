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

namespace Uhifadhi\Patrol\Model;

use Uhifadhi\Patrol\Entity\Patrol;

/**
 * Everything the dashboard screen binds, in one immutable bag — computed by
 * {@see \Uhifadhi\Patrol\Service\PatrolDashboardService}, consumed by
 * the dashboard and widget-library templates (both render the same widgets).
 */
final readonly class PatrolDashboard
{
    /**
     * @param list<Patrol>                                                                             $patrols          latest first — the log/feed rows
     * @param int                                                                                      $monthCount       patrols started this month
     * @param float                                                                                    $monthDistanceKm  distance sum this month
     * @param array<string, int>                                                                       $monthTypeCounts  this month, keyed by type
     * @param float|null                                                                               $coverageFraction PL·03 — the share of the area within {@see \Uhifadhi\Patrol\Service\PatrolDashboardService::COVERAGE_BUFFER_M} of a track recorded this month, as a fraction of 1; null where there is nothing to measure (no recorded track this month, or an area with no boundary) — the KPI then shows the design's em dash rather than a false 0 %
     * @param array<string, int>                                                                       $typeCounts       all listed patrols, every configured type present (filter chips)
     * @param list<array{label: string, counts: array<string, int>}>                                   $weeklySeries     five weeks, oldest first
     * @param list<array{station: string, count: int}>                                                 $stationSeries    this month, ranked
     * @param list<string>                                                                             $stations         distinct stations, ranked (filter menu)
     * @param list<array{date: \DateTimeImmutable, patrols: list<Patrol>, today: bool, outside: bool}> $calendar         42 Monday-start cells for the current month
     */
    public function __construct(
        public array $patrols,
        public int $monthCount,
        public float $monthDistanceKm,
        public array $monthTypeCounts,
        public ?float $coverageFraction,
        public array $typeCounts,
        public int $totalCount,
        public ?Patrol $lastPatrol,
        public array $weeklySeries,
        public array $stationSeries,
        public array $stations,
        public array $calendar,
    ) {
    }
}
