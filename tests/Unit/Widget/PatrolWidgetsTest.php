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

namespace Uhifadhi\Patrol\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Widget\PatrolWidgets;
use Uhifadhi\Widget\Model\WidgetCatalog;

/**
 * THE CATALOGUE IS A TRANSCRIPTION of the design's surface declaration
 * (the patrols surface's patrols.widgets.js). Every assertion here
 * quotes that file, because a catalogue that drifted from it would put a widget
 * on the dashboard that the design app has never drawn.
 */
final class PatrolWidgetsTest extends TestCase
{
    public function testItIsThePatrolsSurface(): void
    {
        self::assertSame('patrols', PatrolWidgets::declaration()->surface);
    }

    /**
     * The five headed sections ARE the five directions patrols is drawn in, in
     * the design's own order and words.
     */
    public function testTheLibrarysSectionsAreTheFiveDirections(): void
    {
        $groups = PatrolWidgets::declaration()->groups();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($g) => $g->id, $groups));
        self::assertSame(
            ['Live coverage', 'The patrol log', 'Shift handover', 'Coverage & effort', 'The month ahead'],
            array_map(static fn ($g) => $g->label, $groups),
        );
    }

    /**
     * The sixteen widgets, in the design's declaration order: the seven the
     * module has always drawn (catalogue order IS the shipped composition),
     * then the nine added for the directions.
     */
    public function testItShipsTheSixteenWidgetsInDeclarationOrder(): void
    {
        self::assertSame(
            ['kpis', 'map', 'log', 'feed', 'chweek', 'chstation', 'cal', 'maplog', 'now', 'obsq', 'handover', 'gaps', 'effort', 'export', 'plan', 'roster'],
            PatrolWidgets::declaration()->ids(),
        );
    }

    /** Each widget is filed under the direction the design files it under. */
    public function testEveryWidgetIsFiledWhereTheDesignFilesIt(): void
    {
        $catalog = PatrolWidgets::declaration();

        $expected = [
            'kpis' => 'b', 'map' => 'a', 'log' => 'b', 'feed' => 'c',
            'chweek' => 'd', 'chstation' => 'd', 'cal' => 'e',
            'maplog' => 'a', 'now' => 'a', 'obsq' => 'c', 'handover' => 'c',
            'gaps' => 'd', 'effort' => 'd', 'export' => 'd', 'plan' => 'e', 'roster' => 'e',
        ];
        foreach ($expected as $id => $group) {
            self::assertSame($group, $catalog->get($id)->group, \sprintf('Widget "%s" is filed under the wrong direction.', $id));
        }
    }

    /**
     * The spans each widget offers, verbatim from the design. "By station" gains
     * the full row (the log direction draws it full-width); the per-week chart
     * stays a half-width plate.
     */
    public function testEveryWidgetOffersTheDesignsSpans(): void
    {
        $catalog = PatrolWidgets::declaration();

        $spans = [
            'kpis' => [12, 9, 6, 3], 'map' => [12, 9, 6, 3], 'log' => [12, 9, 6, 3],
            'feed' => [12, 9, 6, 3], 'cal' => [12, 9, 6, 3], 'chstation' => [12, 9, 6, 3],
            'chweek' => [9, 6, 3],
            'maplog' => [12, 9],
            'now' => [12, 9, 6], 'obsq' => [12, 9, 6], 'handover' => [12, 9, 6],
            'gaps' => [12, 9, 6], 'effort' => [12, 9, 6], 'export' => [12, 9, 6],
            'plan' => [12, 9, 6], 'roster' => [12, 9, 6],
        ];
        foreach ($spans as $id => $expected) {
            self::assertSame($expected, $catalog->spans($id), \sprintf('"%s" offers the wrong spans.', $id));
        }
    }

    /** The shipped composition is the original six — the feed came off it (owner
     * ruling 2026-09-08) and nothing added is on by default. */
    public function testTheShippedCompositionIsTheOriginalSixWidgets(): void
    {
        self::assertSame(
            ['kpis' => 12, 'map' => 12, 'log' => 12, 'chweek' => 6, 'chstation' => 6, 'cal' => 12],
            PatrolWidgets::declaration()->defaultLayout(),
        );
    }

    /**
     * THE FEED IS OFF THE DEFAULT BUT NOT GONE. On the combined screen it drew the
     * same latest-N patrols the log register already lists, so the owner took it
     * off the shipped composition (2026-09-08). It stays in the catalogue and it
     * still LEADS direction C "Shift handover" — off by default, one click away.
     */
    public function testTheFeedIsOffTheDefaultButStillInTheCatalogueAndDirectionC(): void
    {
        $catalog = PatrolWidgets::declaration();

        // Available: still a catalogue widget, just switched off.
        self::assertTrue($catalog->has('feed'));
        self::assertFalse($catalog->get('feed')->on);

        // Off the shipped composition: absent from the default layout.
        self::assertArrayNotHasKey('feed', $catalog->defaultLayout());

        // Still the point of direction C — the reverse-chron handover feed.
        $directionC = $catalog->preset('c');
        self::assertNotNull($directionC);
        self::assertArrayHasKey('feed', $directionC->layout);
    }

    /**
     * THE SHIPPED COMPOSITION LEADS THE STRIP AS A NAMED DESIGN — "The patrols
     * dashboard", the design's own name for it, never a generic "Default layout".
     * It is the sixth built-in, alongside the five directions.
     */
    public function testTheShippedCompositionIsItsOwnNamedDesign(): void
    {
        $catalog = PatrolWidgets::declaration();

        self::assertSame(WidgetCatalog::DEFAULT_PRESET_ID, $catalog->defaultPresetId());

        $shipped = $catalog->preset(WidgetCatalog::DEFAULT_PRESET_ID);
        self::assertNotNull($shipped);
        self::assertSame(PatrolWidgets::DEFAULT_LABEL, $shipped->label);
        self::assertSame('The patrols dashboard', PatrolWidgets::DEFAULT_LABEL);

        // Six built-ins: the shipped composition leads, then the five directions.
        self::assertSame(
            ['default', 'a', 'b', 'c', 'd', 'e'],
            array_map(static fn ($p) => $p->id, $catalog->builtins()),
        );
    }

    /**
     * THE FIVE DIRECTIONS SHIP AS PRESETS, each composing the exact widgets the
     * gallery draws, at the widths it draws them — verbatim from the design.
     */
    public function testTheFiveDirectionsShipAsPresetsWithTheDesignsLayouts(): void
    {
        $catalog = PatrolWidgets::declaration();

        $expected = [
            'a' => ['kpis' => 12, 'now' => 12, 'maplog' => 12],
            'b' => ['kpis' => 12, 'log' => 12, 'chstation' => 12],
            'c' => ['handover' => 12, 'now' => 12, 'obsq' => 12, 'feed' => 12],
            'd' => ['kpis' => 12, 'gaps' => 6, 'effort' => 6, 'chweek' => 6, 'chstation' => 6, 'export' => 12],
            'e' => ['cal' => 12, 'plan' => 12, 'roster' => 12],
        ];

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($p) => $p->id, $catalog->presets()));
        foreach ($expected as $id => $layout) {
            $preset = $catalog->preset($id);
            self::assertNotNull($preset, \sprintf('Direction "%s" does not ship as a preset.', $id));
            self::assertSame($layout, $preset->layout, \sprintf('Preset "%s" composes the wrong widgets.', $id));
        }
    }

    /**
     * A preset and its headed section carry the SAME trade-off line — written
     * once in directions() and read twice — so the product can never say
     * something about a direction the design did not.
     */
    public function testAPresetAndItsSectionShareOneTradeOffLine(): void
    {
        $catalog = PatrolWidgets::declaration();

        foreach ($catalog->groups() as $group) {
            $preset = $catalog->preset($group->id);
            self::assertNotNull($preset);
            self::assertSame($group->label, $preset->label);
            self::assertSame($group->description, $preset->description);
        }
    }

    /** Every widget carries the one line the add-widget picker prints — the design's own. */
    public function testEveryWidgetSaysWhatItShows(): void
    {
        $catalog = PatrolWidgets::declaration();

        foreach ($catalog->ids() as $id) {
            self::assertNotNull($catalog->get($id)->note, \sprintf('Widget "%s" has no picker line.', $id));
        }
        self::assertSame(
            'Patrols, distance, coverage and the last patrol — this month.',
            $catalog->get('kpis')->note,
        );
    }
}
