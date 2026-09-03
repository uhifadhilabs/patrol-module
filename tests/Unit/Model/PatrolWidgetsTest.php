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

namespace Uhifadhi\Patrol\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Patrol\Model\PatrolWidgets;

/**
 * THE CATALOGUE IS A TRANSCRIPTION of the design's surface declaration
 * (areas/ngorongoro/modules/patrols/patrols.widgets.js). Every assertion here
 * quotes that file, because a catalogue that drifted from it would put a widget
 * on the dashboard that the design app has never drawn.
 */
final class PatrolWidgetsTest extends TestCase
{
    public function testItIsThePatrolsSurface(): void
    {
        self::assertSame('patrols', PatrolWidgets::catalog()->surface);
    }

    /**
     * The five headed sections ARE the five directions patrols is drawn in, in
     * the design's own order and words.
     */
    public function testTheLibrarysSectionsAreTheFiveDirections(): void
    {
        $groups = PatrolWidgets::catalog()->groups();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($g) => $g->id, $groups));
        self::assertSame(
            ['Live coverage', 'The patrol log', 'Shift handover', 'Coverage & effort', 'The month ahead'],
            array_map(static fn ($g) => $g->label, $groups),
        );
    }

    /**
     * The seven widgets the module has always drawn, in exactly the order it has
     * always drawn them — catalogue order IS the shipped composition. The nine
     * the design added for the directions arrive with the features they need
     * (live positions, zone gaps, planned patrols); until then the surface ships
     * what it can honestly render.
     */
    public function testItShipsTheSevenWidgetsInTheShippedOrder(): void
    {
        self::assertSame(
            ['kpis', 'map', 'log', 'feed', 'chweek', 'chstation', 'cal'],
            PatrolWidgets::catalog()->ids(),
        );
    }

    /** Each of the seven is filed under the direction the design files it under. */
    public function testEveryWidgetIsFiledWhereTheDesignFilesIt(): void
    {
        $catalog = PatrolWidgets::catalog();

        $expected = [
            'kpis' => 'b',
            'map' => 'a',
            'log' => 'b',
            'feed' => 'c',
            'chweek' => 'd',
            'chstation' => 'd',
            'cal' => 'e',
        ];
        foreach ($expected as $id => $group) {
            self::assertSame($group, $catalog->get($id)->group, \sprintf('Widget "%s" is filed under the wrong direction.', $id));
        }
    }

    /**
     * The two charts are half-width plates: they sit at six columns and are
     * never offered the full row — exactly the design's spans declaration.
     */
    public function testOnlyTheTallWidgetsOfferFullWidth(): void
    {
        $catalog = PatrolWidgets::catalog();

        foreach (['kpis', 'map', 'log', 'feed', 'cal'] as $tall) {
            self::assertSame([12, 9, 6, 3], $catalog->spans($tall), \sprintf('"%s" is drawn tall and offers every span.', $tall));
            self::assertSame(12, $catalog->get($tall)->cols);
        }
        foreach (['chweek', 'chstation'] as $chart) {
            self::assertSame([9, 6, 3], $catalog->spans($chart), \sprintf('"%s" is a half-width plate.', $chart));
            self::assertSame(6, $catalog->get($chart)->cols);
        }
    }

    /** The shipped composition is all seven — nothing the module draws is off by default. */
    public function testTheShippedCompositionIsAllSevenWidgets(): void
    {
        self::assertSame(
            ['kpis' => 12, 'map' => 12, 'log' => 12, 'feed' => 12, 'chweek' => 6, 'chstation' => 6, 'cal' => 12],
            PatrolWidgets::catalog()->defaultLayout(),
        );
    }

    /**
     * THE SHIPPED COMPOSITION LEADS THE STRIP AS A NAMED DESIGN — "The patrols
     * dashboard", the design's own name for it, never a generic "Default layout".
     */
    public function testTheShippedCompositionIsItsOwnNamedDesign(): void
    {
        $catalog = PatrolWidgets::catalog();

        self::assertSame(WidgetCatalog::DEFAULT_PRESET_ID, $catalog->defaultPresetId());

        $shipped = $catalog->preset(WidgetCatalog::DEFAULT_PRESET_ID);
        self::assertNotNull($shipped);
        self::assertSame(PatrolWidgets::DEFAULT_LABEL, $shipped->label);
        self::assertSame('The patrols dashboard', PatrolWidgets::DEFAULT_LABEL);
    }

    /**
     * NO DIRECTION SHIPS AS A PRESET YET. Every one of the five composes at
     * least one widget the module has not built (out right now, coverage + log,
     * the handover note, the observation queue, zone gaps, effort, export,
     * planned vs actual, next week) — and a preset naming an unshipped widget
     * must not boot. They arrive with those widgets; nothing here is a cut.
     */
    public function testNoDirectionShipsUntilItsWidgetsDo(): void
    {
        self::assertSame([], PatrolWidgets::catalog()->presets());
    }

    /** Every widget carries the one line the add-widget picker prints — the design's own. */
    public function testEveryWidgetSaysWhatItShows(): void
    {
        $catalog = PatrolWidgets::catalog();

        foreach ($catalog->ids() as $id) {
            self::assertNotNull($catalog->get($id)->note, \sprintf('Widget "%s" has no picker line.', $id));
        }
        self::assertSame(
            'Patrols, distance, coverage and the last patrol — this month.',
            $catalog->get('kpis')->note,
        );
    }
}
