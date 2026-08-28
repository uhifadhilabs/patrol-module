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
 *
 * ## Discarded patrols
 *
 * A discarded patrol reaches this service and leaves it in exactly one place:
 * `PatrolDashboard::$patrols`, the list the log table and the feed render. It is
 * absent from EVERY figure — the month count, the month distance, the month and
 * all-time type counts, the station ranking, the five-week series, the total,
 * the last-patrol line — and from the coverage map's payload, because a track
 * drawn on the coverage map is a claim about ground covered.
 *
 * The split is deliberate, and the two halves say different things. A discard
 * means "this effort did not happen as recorded", so counting it would report
 * kilometres nobody walked. But a ranger who uploaded a patrol and then
 * discarded it must still be able to FIND it — a record that vanishes from
 * every screen is indistinguishable from one the sync lost. So it stays in the
 * lists, subdued and pilled, and nowhere else.
 *
 * {@see \UhifadhiLabs\Patrol\Enum\PatrolStatusEnum::countsTowardsStatistics()}
 * is the one predicate all of that goes through.
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

    /**
     * PL·03's buffer, in metres: the design's KPI is "% of area within 2 km of a
     * track", so the distance is part of the widget's meaning, not a knob — the
     * caption on the plate states it, and a deployment that changed it silently
     * would be printing a different number under the same words.
     */
    public const float COVERAGE_BUFFER_M = 2000.0;

    private const int WEEKS = 5;
    private const int CALENDAR_CELLS = 42;

    /**
     * The half-open window every "this month" figure is scoped to (PL·01's
     * count, PL·02's distance sum, PL·03's coverage and the station ranking).
     *
     * Public because the coverage KPI is the one month figure that CANNOT be
     * computed from the loaded entities — it is a PostGIS set operation
     * ({@see \UhifadhiLabs\Patrol\Repository\PatrolRepository::coverageFractionWithin()}) —
     * so its caller must ask the database for exactly the window this service
     * counts in. Decided here, in the one place that defines "this month", never
     * re-derived at the call site.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, untilExclusive]
     */
    public static function monthRange(\DateTimeImmutable $now): array
    {
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);

        return [$monthStart, $monthStart->modify('+1 month')];
    }

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
     * Everything the coverage map (PL·05) draws, in one JSON-safe bag: the area
     * boundary and one entry per patrol that actually RECORDED a route. Built
     * here rather than in Twig so its shape is unit-tested, and so the dashboard
     * and the widget library can never hand their maps different data.
     *
     * Both geometries travel as the GeoJSON text the geometry columns store
     * (postgis-bundle types) — the Stimulus controller parses them.
     *
     * A hand-logged patrol has no geometry (docs/design-decisions.md §4): it is
     * left out entirely rather than drawn as a guess, and its absence must not
     * leave a hole in the list (the entries are appended, never keyed).
     *
     * Stations are free strings with no coordinates of their own
     * (docs/design-decisions.md §1), so the design's labelled station markers
     * are placed at the best evidence there is: the FIRST recorded point of a
     * patrol that set out from that station. A station whose patrols were all
     * hand-logged therefore gets no marker — an invented position would be worse
     * than none.
     *
     * @param string|null                         $boundary the area's geom as GeoJSON text, null where the area has none
     * @param array<string, array{label: string}> $types    the deployment's patrol.types map
     *
     * @return array{boundary: string|null, patrols: list<array{uuid: string, ref: string, type: string, station: string, color: string, track: string}>, stations: list<array{name: string, lon: float, lat: float}>}
     */
    public function coveragePayload(?string $boundary, PatrolDashboard $dashboard, array $types): array
    {
        $colors = self::typeColors($types);

        $tracks = [];
        /** @var array<string, array{name: string, lon: float, lat: float}> $stations */
        $stations = [];
        foreach ($dashboard->patrols as $patrol) {
            $track = $patrol->getTrack();
            // Only a COMPLETE patrol is drawn here. This payload is what the
            // coverage map (PL·05) and the tracks plate (PL·08) render, and a
            // line on a coverage map is read as ground covered — which is
            // exactly what a discard withdraws, and exactly what a track still
            // arriving has not established yet. A discard stays in the lists
            // beside the map, simply not in the picture of coverage; a
            // recording patrol reaches neither, having never got past
            // isPresentable() in build().
            if (null === $track || '' === $track || !$patrol->getStatus()->countsTowardsStatistics()) {
                continue;
            }
            $station = $patrol->getStation() ?? '';
            $tracks[] = [
                'uuid' => $patrol->getUuid()->toRfc4122(),
                'ref' => $patrol->getRef(),
                'type' => $patrol->getType(),
                'station' => $station,
                'color' => $colors[$patrol->getType()] ?? self::TRACK_COLORS[0],
                'track' => $track,
            ];

            if ('' === $station || isset($stations[$station])) {
                continue;
            }
            $start = self::firstPoint($track);
            if (null !== $start) {
                $stations[$station] = ['name' => $station, 'lon' => $start[0], 'lat' => $start[1]];
            }
        }

        return ['boundary' => $boundary, 'patrols' => $tracks, 'stations' => array_values($stations)];
    }

    /**
     * The first vertex of a GeoJSON (Multi)LineString as [lon, lat]; null for
     * anything else, so a malformed column never becomes a marker.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function firstPoint(string $lineString): ?array
    {
        $decoded = json_decode($lineString, true);
        if (!\is_array($decoded) || !\is_array($decoded['coordinates'] ?? null)) {
            return null;
        }
        $point = $decoded['coordinates'][0] ?? null;
        // A MultiLineString nests one level deeper.
        if (\is_array($point) && \is_array($point[0] ?? null)) {
            $point = $point[0];
        }
        if (!\is_array($point) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
            return null;
        }

        return [(float) $point[0], (float) $point[1]];
    }

    /**
     * @param list<Patrol>                        $patrols          latest first
     * @param array<string, array{label: string}> $types            the deployment's patrol.types map
     * @param float|null                          $coverageFraction PL·03, queried by the caller (see {@see self::monthRange()}); null where it is unknown
     */
    public function build(array $patrols, array $types, \DateTimeImmutable $now, ?float $coverageFraction = null): PatrolDashboard
    {
        [$monthStart, $nextMonth] = self::monthRange($now);

        $monthCount = 0;
        $monthDistanceKm = 0.0;
        /** @var array<string, int> $monthTypeCounts */
        $monthTypeCounts = [];
        /** @var array<string, int> $typeCounts */
        $typeCounts = array_fill_keys(array_keys($types), 0);
        /** @var array<string, int> $stationCounts */
        $stationCounts = [];
        $lastPatrol = null;

        // TWO SETS, and the difference between them is the whole status model.
        //
        // The PRESENTED set is what the log, the feed and the calendar draw:
        // every patrol whose recording has finished, which includes a discarded
        // one (shown subdued — see the discard design) and excludes one that is
        // still arriving. A caller hands us whatever the repository found; the
        // decision about what may be drawn is made here, once.
        $presented = array_values(array_filter(
            $patrols,
            static fn (Patrol $patrol): bool => $patrol->getStatus()->isPresentable(),
        ));

        // The COUNTED set is stricter again: nothing below this line may see a
        // discarded patrol or a half-arrived one. The filter happens ONCE rather
        // than as a condition repeated in six tallies where one could be missed.
        $counted = array_values(array_filter(
            $presented,
            static fn (Patrol $patrol): bool => $patrol->getStatus()->countsTowardsStatistics(),
        ));

        foreach ($counted as $patrol) {
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
            patrols: $presented,
            monthCount: $monthCount,
            monthDistanceKm: $monthDistanceKm,
            monthTypeCounts: $monthTypeCounts,
            coverageFraction: $coverageFraction,
            typeCounts: $typeCounts,
            totalCount: \count($counted),
            lastPatrol: $lastPatrol,
            weeklySeries: $this->weeklySeries($counted, $types, $now),
            stationSeries: $stationSeries,
            stations: array_column($stationSeries, 'station'),
            // The dashboard opens on the CURRENT month; ‹ › then fetches any
            // other month through the same method (PatrolCalendarController).
            calendar: $this->calendarFor($presented, $now, $now),
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
     * The days a month's calendar grid covers: its first cell and the day AFTER
     * its last, so a caller can ask the repository for exactly the patrols the
     * grid can show — including the neighbouring months' dimmed leading and
     * trailing days, which the design still draws pills on.
     *
     * Public because the fragment endpoint (PatrolCalendarController) queries
     * one month at a time: the grid's shape is decided HERE, in the one place
     * that lays the cells out, never re-derived at the call site.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, untilExclusive]
     */
    public static function calendarRange(\DateTimeImmutable $month): array
    {
        // A Monday-start 6×7 grid always fits a month wherever it begins.
        $gridStart = $month->modify('first day of this month')->setTime(0, 0)->modify('monday this week');

        return [$gridStart, $gridStart->modify(\sprintf('+%d days', self::CALENDAR_CELLS))];
    }

    /**
     * The calendar grid for ANY month — what the ‹ › navigation asks for. Pure:
     * the patrols are handed in (queried with {@see self::calendarRange()}), the
     * month is the month on screen and "now" is the clock, which decides only
     * which cell is ringed as today. A month that holds no patrols is a full
     * grid of empty days, never a missing widget.
     *
     * DISCARDED patrols DO get a pill, drawn subdued. The grid is a list of what
     * happened on which day, not a figure about it — and a ranger looking for
     * the patrol they discarded on the 12th should find it on the 12th. It is
     * counted in nothing.
     *
     * @param list<Patrol> $patrols
     *
     * @return list<array{date: \DateTimeImmutable, patrols: list<Patrol>, today: bool, outside: bool}>
     */
    public function calendarFor(array $patrols, \DateTimeImmutable $month, \DateTimeImmutable $now): array
    {
        $monthStart = $month->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $monthStart->modify('+1 month');
        [$gridStart] = self::calendarRange($month);
        $today = $now->format('Y-m-d');

        /** @var array<string, list<Patrol>> $byDay */
        $byDay = [];
        foreach ($patrols as $patrol) {
            // Filtered HERE as well as in build(), because the calendar has a
            // SECOND door: PatrolCalendarController fetches any other month
            // straight from the repository and renders the same grid. A rule
            // enforced only on the dashboard's path would hold in august and
            // quietly fail the moment somebody clicked ‹.
            if (!$patrol->getStatus()->isPresentable()) {
                continue;
            }
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
