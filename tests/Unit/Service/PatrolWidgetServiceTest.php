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
use UhifadhiLabs\Patrol\Exception\InvalidWidgetPreferenceException;
use UhifadhiLabs\Patrol\Service\PatrolWidgetService;

/**
 * The widget catalogue and the pure preference algebra on top of it: the design's
 * defaults, stored preferences merged over them, and the validation that decides
 * what may be stored at all. Persistence is exercised by the functional tests.
 */
final class PatrolWidgetServiceTest extends TestCase
{
    public function testTheDefaultsAreTheSettledDesignsOwnLayout(): void
    {
        $widgets = PatrolWidgetService::merge(null);

        // Order and spans read straight off the design (ngoro-patrols-widgets):
        // everything full width except the two charts, which sit half and half.
        self::assertSame(
            ['kpis', 'map', 'log', 'feed', 'chweek', 'chstation', 'cal'],
            array_column($widgets, 'id'),
        );
        self::assertSame(
            [12, 12, 12, 12, 6, 6, 12],
            array_column($widgets, 'cols'),
        );
        // A fresh user sees every widget.
        self::assertSame([true, true, true, true, true, true, true], array_column($widgets, 'on'));
        self::assertSame('KPI strip', $widgets[0]['label']);
    }

    public function testOnlyTheTallWidgetsOfferFullWidth(): void
    {
        $spans = array_column(PatrolWidgetService::merge(null), 'spans', 'id');

        self::assertSame([12, 9, 6, 3], $spans['map']);
        self::assertSame([12, 9, 6, 3], $spans['log']);
        self::assertSame([12, 9, 6, 3], $spans['cal']);
        // The charts are half-width plates; full width is not on offer for them.
        self::assertSame([9, 6, 3], $spans['chweek']);
        self::assertSame([9, 6, 3], $spans['chstation']);
    }

    public function testStoredPreferencesWinOverTheDefaults(): void
    {
        $widgets = PatrolWidgetService::merge([
            'order' => ['cal', 'kpis'],
            'widgets' => [
                'cal' => ['on' => true, 'cols' => 6],
                'map' => ['on' => false, 'cols' => 12],
            ],
        ]);

        // Stored order first, then whatever the stored order never mentioned, in
        // catalogue order — a widget added by a later release still appears.
        self::assertSame(
            ['cal', 'kpis', 'map', 'log', 'feed', 'chweek', 'chstation'],
            array_column($widgets, 'id'),
        );
        $byId = array_column($widgets, null, 'id');
        self::assertSame(6, $byId['cal']['cols']);
        self::assertFalse($byId['map']['on']);
        // Untouched widgets keep the design default.
        self::assertSame(12, $byId['log']['cols']);
        self::assertTrue($byId['log']['on']);
    }

    public function testAStoredSpanOutsideTheAllowedSetIsClampedOnTheWayOut(): void
    {
        // A row written before a widget lost full width must not resurrect it.
        $byId = array_column(PatrolWidgetService::merge([
            'order' => [],
            'widgets' => ['chweek' => ['on' => true, 'cols' => 12]],
        ]), null, 'id');

        self::assertSame(9, $byId['chweek']['cols']);
    }

    public function testValidationCanonicalisesAPayload(): void
    {
        $prefs = PatrolWidgetService::validate([
            'order' => ['cal', 'map'],
            'widgets' => [
                'cal' => ['on' => false, 'cols' => 6],
                'map' => ['on' => true, 'cols' => 9],
            ],
        ]);

        self::assertSame(
            ['cal', 'map', 'kpis', 'log', 'feed', 'chweek', 'chstation'],
            $prefs['order'],
        );
        self::assertSame(['on' => false, 'cols' => 6], $prefs['widgets']['cal']);
        self::assertSame(['on' => true, 'cols' => 9], $prefs['widgets']['map']);
        // Widgets the payload never named are stored at their defaults, so the
        // stored row is always a complete picture.
        self::assertSame(['on' => true, 'cols' => 6], $prefs['widgets']['chstation']);
    }

    public function testValidationClampsASpanToTheNearestAllowedOne(): void
    {
        $prefs = PatrolWidgetService::validate([
            'order' => [],
            'widgets' => [
                'chweek' => ['on' => true, 'cols' => 12],
                'map' => ['on' => true, 'cols' => 7],
                'log' => ['on' => true, 'cols' => 400],
            ],
        ]);

        self::assertSame(9, $prefs['widgets']['chweek']['cols']);
        self::assertSame(6, $prefs['widgets']['map']['cols']);
        self::assertSame(12, $prefs['widgets']['log']['cols']);
    }

    public function testAPartialOrderRanksTheUnlistedWidgetsAfterTheListedOnes(): void
    {
        // The posted layout is never trusted to be complete: a stale page, or a
        // release that added a widget, posts only what it knows about.
        $prefs = PatrolWidgetService::validate([
            'order' => ['cal', 'chstation'],
            'widgets' => ['cal' => ['on' => true, 'cols' => 12]],
        ]);

        // Listed first, in the posted order; the rest appended in catalogue order
        // — never lost, never colliding for a position.
        self::assertSame(
            ['cal', 'chstation', 'kpis', 'map', 'log', 'feed', 'chweek'],
            $prefs['order'],
        );
        // Every catalogue widget is stored, so the row is a complete picture.
        $stored = array_keys($prefs['widgets']);
        $catalogue = array_keys(PatrolWidgetService::CATALOGUE);
        sort($stored);
        sort($catalogue);
        self::assertSame($catalogue, $stored);
        // A widget the payload never named keeps its design default.
        self::assertSame(['on' => true, 'cols' => 12], $prefs['widgets']['map']);
    }

    public function testARepeatedWidgetIdIsRankedOnce(): void
    {
        $prefs = PatrolWidgetService::validate(['order' => ['cal', 'cal', 'map'], 'widgets' => []]);

        self::assertSame(['cal', 'map', 'kpis', 'log', 'feed', 'chweek', 'chstation'], $prefs['order']);
    }

    public function testAStoredOrderMissingAWidgetStillResolvesIt(): void
    {
        // The same tolerance on the way out: a row written before a widget existed
        // must still render that widget, ranked after the ones it does list.
        $widgets = PatrolWidgetService::merge([
            'order' => ['cal', 'map'],
            'widgets' => ['cal' => ['on' => true, 'cols' => 12]],
        ]);

        self::assertSame(
            ['cal', 'map', 'kpis', 'log', 'feed', 'chweek', 'chstation'],
            array_column($widgets, 'id'),
        );
    }

    public function testAnUnknownWidgetIdIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        PatrolWidgetService::validate([
            'order' => [],
            'widgets' => ['tracker' => ['on' => true, 'cols' => 6]],
        ]);
    }

    public function testAnUnknownWidgetIdInTheOrderIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        PatrolWidgetService::validate(['order' => ['tracker'], 'widgets' => []]);
    }

    public function testAMalformedPayloadIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        PatrolWidgetService::validate(['order' => 'kpis', 'widgets' => []]);
    }

    public function testAMalformedWidgetEntryIsRejected(): void
    {
        $this->expectException(InvalidWidgetPreferenceException::class);

        PatrolWidgetService::validate(['order' => [], 'widgets' => ['map' => 'on']]);
    }

    public function testAStoredRowThatWentBadFallsBackToTheDefaults(): void
    {
        // merge() never throws: a hand-edited or half-written row must not take
        // the dashboard down, it just stops being honoured.
        self::assertSame(
            PatrolWidgetService::merge(null),
            PatrolWidgetService::merge(['order' => 'nonsense', 'widgets' => 42]),
        );
    }
}
