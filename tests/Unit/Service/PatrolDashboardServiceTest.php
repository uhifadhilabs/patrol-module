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
}
