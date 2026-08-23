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

namespace UhifadhiLabs\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;

/**
 * The dashboard's data contract — everything the widget screen binds, computed
 * from plain entities so it is testable without a container. "Now" is always
 * passed in: the service must never call the clock itself.
 */
final class PatrolDashboardServiceTest extends TestCase
{
    /** @var array<string, array{label: string}> */
    private const array TYPES = ['walk' => ['label' => 'Walking round'], 'boat' => ['label' => 'Boat']];

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-03-21T12:00:00Z');
    }

    private function patrol(string $type, string $startedAt, float $km, ?string $station = null, int $observations = 0): Patrol
    {
        $patrol = new Patrol(new AreaOfInterest(), $type)
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStation($station)
            ->setStartedAt(new \DateTimeImmutable($startedAt))
            ->setEndedAt(new \DateTimeImmutable($startedAt)->modify('+2 hours'))
            ->setDistanceKm($km);
        for ($i = 0; $i < $observations; ++$i) {
            new Observation($patrol, 'maintenance');
        }

        return $patrol;
    }

    public function testKpisSummariseTheMonth(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post', observations: 2),
            $this->patrol('walk', '2026-03-05T06:00:00Z', 4.5),
            $this->patrol('boat', '2026-03-19T08:00:00Z', 20.5),
            // Previous month — excluded from month KPIs, still in the log.
            $this->patrol('walk', '2026-02-27T06:00:00Z', 7.0),
        ], self::TYPES, $this->now);

        self::assertSame(3, $dashboard->monthCount);
        self::assertEqualsWithDelta(35.0, $dashboard->monthDistanceKm, 0.001);
        self::assertSame(['walk' => 2, 'boat' => 1], $dashboard->monthTypeCounts);
        // Type counts over ALL listed patrols drive the filter chips.
        self::assertSame(['walk' => 3, 'boat' => 1], $dashboard->typeCounts);
        self::assertSame(4, $dashboard->totalCount);
        // Last patrol: the latest start.
        self::assertNotNull($dashboard->lastPatrol);
        self::assertSame('North post', $dashboard->lastPatrol->getStation());
    }

    public function testTypesWithoutPatrolsStillGetAChipCount(): void
    {
        $dashboard = new PatrolDashboardService()->build(
            [$this->patrol('walk', '2026-03-20T06:00:00Z', 1.0)],
            self::TYPES,
            $this->now,
        );

        self::assertSame(['walk' => 1, 'boat' => 0], $dashboard->typeCounts);
    }

    public function testWeeklySeriesCoversTheLastFiveWeeksByType(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 1.0), // this week (W5)
            $this->patrol('boat', '2026-03-16T06:00:00Z', 1.0), // this week (W5)
            $this->patrol('walk', '2026-03-10T06:00:00Z', 1.0), // last week (W4)
            $this->patrol('walk', '2026-02-16T06:00:00Z', 1.0), // W1
            $this->patrol('walk', '2026-01-01T06:00:00Z', 1.0), // older — outside the window
        ], self::TYPES, $this->now);

        self::assertCount(5, $dashboard->weeklySeries);
        $labels = array_column($dashboard->weeklySeries, 'label');
        self::assertSame(['W1', 'W2', 'W3', 'W4', 'W5'], $labels);
        self::assertSame(['walk' => 1, 'boat' => 1], $dashboard->weeklySeries[4]['counts']);
        self::assertSame(['walk' => 1, 'boat' => 0], $dashboard->weeklySeries[3]['counts']);
        self::assertSame(['walk' => 1, 'boat' => 0], $dashboard->weeklySeries[0]['counts']);
    }

    public function testStationSeriesRanksThisMonthsStations(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 1.0, 'North post'),
            $this->patrol('walk', '2026-03-18T06:00:00Z', 1.0, 'North post'),
            $this->patrol('boat', '2026-03-17T06:00:00Z', 1.0, 'Jetty'),
            $this->patrol('walk', '2026-03-16T06:00:00Z', 1.0, null), // no station — grouped as unassigned
        ], self::TYPES, $this->now);

        self::assertSame([['station' => 'North post', 'count' => 2], ['station' => 'Jetty', 'count' => 1]], $dashboard->stationSeries);
        self::assertSame(['North post', 'Jetty'], $dashboard->stations);
    }

    public function testCalendarPlacesPatrolsOnTheirDays(): void
    {
        $dashboard = new PatrolDashboardService()->build([
            $this->patrol('walk', '2026-03-20T06:00:00Z', 1.0),
            $this->patrol('boat', '2026-03-20T09:00:00Z', 1.0),
            $this->patrol('walk', '2026-03-02T06:00:00Z', 1.0),
        ], self::TYPES, $this->now);

        // March 2026 starts on a Sunday; a Monday-start grid opens on Feb 23.
        $calendar = $dashboard->calendar;
        self::assertSame('2026-02-23', $calendar[0]['date']->format('Y-m-d'));
        self::assertCount(42, $calendar);

        $byDate = [];
        foreach ($calendar as $cell) {
            $byDate[$cell['date']->format('Y-m-d')] = $cell;
        }
        self::assertCount(2, $byDate['2026-03-20']['patrols']);
        self::assertCount(1, $byDate['2026-03-02']['patrols']);
        self::assertTrue($byDate['2026-03-21']['today']);
        self::assertFalse($byDate['2026-03-20']['today']);
        self::assertTrue($byDate['2026-02-23']['outside']);
        self::assertFalse($byDate['2026-03-02']['outside']);
    }

    /* ── the coverage map's payload ──────────────────────────────────────── */

    public function testCoveragePayloadCarriesTheBoundaryAndEveryRecordedTrack(): void
    {
        $service = new PatrolDashboardService();
        $walk = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $walk->setTrack('{"type":"LineString","coordinates":[[35.5,-3.2],[35.6,-3.3]]}');
        $boat = $this->patrol('boat', '2026-03-19T06:00:00Z', 4.0);
        $boat->setTrack('{"type":"LineString","coordinates":[[35.1,-3.1],[35.2,-3.15]]}');

        $boundary = '{"type":"MultiPolygon","coordinates":[[[[35.0,-3.0],[35.9,-3.0],[35.9,-3.6],[35.0,-3.6],[35.0,-3.0]]]]}';
        $payload = $service->coveragePayload(
            $boundary,
            $service->build([$walk, $boat], self::TYPES, $this->now),
            self::TYPES,
        );

        self::assertSame($boundary, $payload['boundary']);
        self::assertCount(2, $payload['patrols']);
        self::assertSame($walk->getUuid()->toRfc4122(), $payload['patrols'][0]['uuid']);
        self::assertSame($walk->getRef(), $payload['patrols'][0]['ref']);
        self::assertSame('walk', $payload['patrols'][0]['type']);
        // The colour is the SAME one the chips, charts and legend use.
        self::assertSame(PatrolDashboardService::typeColors(self::TYPES)['walk'], $payload['patrols'][0]['color']);
        self::assertSame($walk->getTrack(), $payload['patrols'][0]['track']);
        self::assertSame('boat', $payload['patrols'][1]['type']);
    }

    public function testCoveragePayloadLeavesOutPatrolsWithoutATrack(): void
    {
        $service = new PatrolDashboardService();
        // A hand-logged patrol carries no geometry — it must not be drawn, and
        // it must not break the payload either.
        $sketch = $this->patrol('walk', '2026-03-20T06:00:00Z', 3.0);
        $recorded = $this->patrol('boat', '2026-03-19T06:00:00Z', 4.0);
        $recorded->setTrack('{"type":"LineString","coordinates":[[35.1,-3.1],[35.2,-3.15]]}');

        $payload = $service->coveragePayload(
            null,
            $service->build([$sketch, $recorded], self::TYPES, $this->now),
            self::TYPES,
        );

        self::assertNull($payload['boundary']);
        self::assertCount(1, $payload['patrols']);
        self::assertSame($recorded->getUuid()->toRfc4122(), $payload['patrols'][0]['uuid']);
        // A list, never a gappy array: json_encode must emit [] not {"1":…}.
        self::assertSame(range(0, \count($payload['patrols']) - 1), array_keys($payload['patrols']));
    }

    public function testCoveragePayloadOfAnAreaWithoutPatrolsStillCarriesTheBoundary(): void
    {
        $service = new PatrolDashboardService();
        $boundary = '{"type":"MultiPolygon","coordinates":[[[[35.0,-3.0],[35.9,-3.0],[35.9,-3.6],[35.0,-3.6],[35.0,-3.0]]]]}';

        $payload = $service->coveragePayload($boundary, $service->build([], self::TYPES, $this->now), self::TYPES);

        self::assertSame($boundary, $payload['boundary']);
        self::assertSame([], $payload['patrols']);
        self::assertSame([], $payload['stations']);
    }

    public function testCoveragePayloadPlacesEachStationAtWhereItsPatrolsSetOut(): void
    {
        $service = new PatrolDashboardService();
        $north = $this->patrol('walk', '2026-03-20T06:00:00Z', 10.0, 'North post');
        $north->setTrack('{"type":"LineString","coordinates":[[35.5,-3.2],[35.6,-3.3]]}');
        // A second patrol from the same station: one marker, not two.
        $northAgain = $this->patrol('boat', '2026-03-18T06:00:00Z', 5.0, 'North post');
        $northAgain->setTrack('{"type":"LineString","coordinates":[[35.55,-3.25],[35.7,-3.4]]}');
        $jetty = $this->patrol('boat', '2026-03-19T06:00:00Z', 4.0, 'Jetty');
        $jetty->setTrack('{"type":"LineString","coordinates":[[35.1,-3.1],[35.2,-3.15]]}');
        // No station, and a station whose patrol recorded no track: neither can
        // be placed on a map, so neither is invented.
        $anonymous = $this->patrol('walk', '2026-03-17T06:00:00Z', 2.0);
        $anonymous->setTrack('{"type":"LineString","coordinates":[[35.9,-3.9],[35.95,-3.95]]}');
        $unplaceable = $this->patrol('walk', '2026-03-16T06:00:00Z', 2.0, 'Sketch camp');

        $payload = $service->coveragePayload(
            null,
            $service->build([$north, $northAgain, $jetty, $anonymous, $unplaceable], self::TYPES, $this->now),
            self::TYPES,
        );

        self::assertSame([
            ['name' => 'North post', 'lon' => 35.5, 'lat' => -3.2],
            ['name' => 'Jetty', 'lon' => 35.1, 'lat' => -3.1],
        ], $payload['stations']);
        // Each track states its station too, so the station filter can drive the
        // map the same way the type chips do.
        self::assertSame('North post', $payload['patrols'][0]['station']);
        self::assertSame('', $payload['patrols'][3]['station']);
    }
}
