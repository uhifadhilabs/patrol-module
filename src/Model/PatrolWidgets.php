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

namespace UhifadhiLabs\Patrol\Model;

use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetGroup;

/**
 * THE CATALOGUE of the per-area PATROLS surface — a transcription of the
 * design's own surface declaration (patrols.widgets.js), which is the spec.
 *
 * THE FIVE DIRECTIONS ARE HEADED SECTIONS, NOT PAGES — the same grammar
 * incidents uses. Patrols was first drawn as ONE composed screen; that
 * composition is still exactly what the module ships, named below
 * ({@see DEFAULT_LABEL}) so the host's {@see WidgetCatalog::builtins()} leads
 * the preset strip with it rather than with a generic "Default layout".
 *
 * THE DIRECTIONS DO NOT SHIP AS PRESETS YET, and that is deliberate rather
 * than an oversight: every one of the five composes at least one of the nine
 * widgets the design added for them (out right now, coverage + log, the
 * handover note, the observation queue, zone gaps, effort by ranger, export,
 * planned vs actual, patrols next week), and those widgets need features the
 * module has not built — live positions, zone coverage analysis, planned
 * patrols. A preset that names an unshipped widget refuses to boot
 * ({@see WidgetCatalog}), which is the framework agreeing. Each direction
 * lands as a preset in the change that lands its widgets; the gallery at
 * presets/patrols/ is the spec they land against.
 *
 * It rides the HOST's widget framework rather than a copy of it: the
 * dashboard, the library and the save endpoints all read this one object, so a
 * widget can never exist on one screen and not the other.
 *
 * AREA-SCOPED: the same person may lay one area's patrols out one way and
 * another area's another, so every widget-framework call passes the area's
 * UUID and the stored preference rows are keyed by (surface, user, area).
 *
 * Static rather than a service: a catalogue is a statement of what a surface
 * ships. It has no dependencies and nothing may vary it at runtime.
 */
final class PatrolWidgets
{
    /** What a stored preference row is keyed by. */
    public const string SURFACE = 'patrols';

    /** What the composition this module ships with is CALLED when it leads the preset strip. */
    public const string DEFAULT_LABEL = 'The patrols dashboard';

    public const string DEFAULT_DESCRIPTION = 'What the module ships with: the counts, then where, then every patrol, then the feed and the month. The direction-neutral screen — adopt one of the five below to lead with something sharper.';

    public static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            self::directions(),
            self::widgets(),
            // No direction ships as a preset until its widgets do — see the
            // class docblock. The strip then carries exactly one built-in: the
            // shipped composition, under the design's own name for it.
            [],
            WidgetCatalog::DEFAULT_PRESET_ID,
            self::DEFAULT_LABEL,
            self::DEFAULT_DESCRIPTION,
        );
    }

    /**
     * The surface's widgets: the seven the module has always drawn, in exactly
     * the order it has always drawn them — catalogue order IS the shipped
     * composition (the design: "ORDER IS THE SHIPPED DASHBOARD"). `cols` is the
     * width the catalogue draws each at, the spans are the widths the
     * width-chips offer (widest first, as the host's Widget enforces), and the
     * two charts are half-width plates that are never offered the full row.
     *
     * @return list<Widget>
     */
    private static function widgets(): array
    {
        return [
            new Widget('kpis', 'KPI strip', 'b', 12, [12, 9, 6, 3], on: true, note: 'Patrols, distance, coverage and the last patrol — this month.'),
            new Widget('map', 'Coverage map', 'a', 12, [12, 9, 6, 3], on: true, note: 'The area with every track on it; the filter here drives the log too.'),
            new Widget('log', 'Patrol log', 'b', 12, [12, 9, 6, 3], on: true, note: 'Every patrol as a row: date, type, station, distance, observations.'),
            new Widget('feed', 'Feed + mini-map', 'c', 12, [12, 9, 6, 3], on: true, note: 'The latest observations beside a mini-map of where they were filed.'),
            new Widget('chweek', 'Patrols per week', 'd', 6, [9, 6, 3], on: true, note: 'Patrols per week, by type.'),
            new Widget('chstation', 'By station', 'd', 6, [9, 6, 3], on: true, note: 'Patrols by the station that logged them.'),
            new Widget('cal', 'Patrol calendar', 'e', 12, [12, 9, 6, 3], on: true, note: 'The month as a calendar, one mark per patrol.'),
        ];
    }

    /**
     * THE FIVE DIRECTIONS as the library's headed sections: the letter the
     * library files each widget under, what it is called, and the gallery's own
     * trade-off line, verbatim — written once here so the product can never say
     * something about a direction that the design did not.
     *
     * @return list<WidgetGroup>
     */
    private static function directions(): array
    {
        return [
            new WidgetGroup('a', 'Live coverage', 'The map is the dashboard: every track this month at full height, the log docked beside it and whoever is still out on top. Best for the officer who has to see where cover is right now; says almost nothing about effort, planning, or the month as a whole.'),
            new WidgetGroup('b', 'The patrol log', 'The book. Every patrol as a row — date, type, station, lead, distance, observations — under the month\'s headline numbers. Fastest for whoever keeps the record and the only direction that never hides a field; you have to picture the geography yourself.'),
            new WidgetGroup('c', 'Shift handover', 'What came in, what is still out, and what the next shift inherits. Reads like a duty log and is the direction to hand a station over on; anything older than the last two shifts sinks out of sight.'),
            new WidgetGroup('d', 'Coverage & effort', 'Where nobody has been, who did the walking, and the month as two charts with the export beside them. The direction that answers "is this area actually being covered" and the one a monthly report is written from; it never shows you an individual patrol.'),
            new WidgetGroup('e', 'The month ahead', 'The calendar leads, with planned against actual under it and next week\'s planned patrols below that. The only direction that looks forward rather than back — and the weakest for anything that has already happened.'),
        ];
    }
}
