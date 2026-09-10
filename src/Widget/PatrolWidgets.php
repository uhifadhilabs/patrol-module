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

namespace Uhifadhi\Patrol\Widget;

use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetCatalog;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;

/**
 * THE CATALOGUE of the per-area PATROLS surface — a transcription of the
 * design's own surface declaration (patrols.widgets.js), which is the spec.
 *
 * THE FIVE DIRECTIONS ARE PRESETS, NOT PAGES — the same grammar incidents uses.
 * Patrols was first drawn as ONE composed screen; that composition is still
 * exactly what the module ships, named below ({@see DEFAULT_LABEL}) so the
 * host's {@see WidgetCatalog::builtins()} leads the preset strip with it rather
 * than with a generic "Default layout". What arrived with the five directions is
 * the rest of the surface: five directions the same material genuinely supports —
 * live coverage, the log, a shift handover, coverage & effort, the month ahead —
 * each a HEADED SECTION of this catalogue AND a PRESET that composes it, so a
 * person adopts a direction, copies it, and mixes a widget from another one into
 * their copy. The gallery that compares them lives at presets/patrols/.
 *
 * THE NINE WIDGETS ADDED FOR THOSE DIRECTIONS ARE ADDITIVE. Not one of the seven
 * the module already drew was redrawn or re-numbered; the shipped composition is
 * the screen patrols settled on. Where a direction needs data the model cannot
 * yet produce — a planning entity for "planned vs actual" and "patrols next
 * week" — the widget ships as an honest empty state rather than an invented
 * number, and the preset still composes it (see the module's dashboard partials).
 *
 * It rides ShellBundle's widget framework rather than a copy of it: the dashboard, the
 * library and the save endpoints all read this one object, so a widget can never
 * exist on one screen and not the other.
 *
 * AREA-SCOPED: the same person may lay one area's patrols out one way and
 * another area's another, so every widget-framework call passes the area's UUID
 * and the stored preference rows are keyed by (surface, user, area).
 *
 * A CATALOGUE IS A STATEMENT OF WHAT A SURFACE SHIPS, so this class has no
 * dependencies and nothing may vary it at runtime. It is nonetheless a
 * {@see WidgetSurfaceInterface} and TAGGED as one (see the bundle's
 * loadExtension), because being FINDABLE is the half a catalogue alone cannot
 * do: `widget:prune` walks the registry, and a surface no service claims is a
 * surface whose stored layouts read as orphans and get deleted. The static
 * accessors stay — they are how this module's own controllers and templates
 * reach the catalogue without a container round-trip.
 */
final class PatrolWidgets implements WidgetSurfaceInterface
{
    /** What a stored preference row is keyed by. */
    public const string SURFACE = 'patrols';

    /** What the composition this module ships with is CALLED when it leads the preset strip. */
    public const string DEFAULT_LABEL = 'The patrols dashboard';

    public const string DEFAULT_DESCRIPTION = 'What the module ships with: the counts, then where, then every patrol, then the month. The direction-neutral screen — adopt one of the five below to lead with something sharper.';

    public function catalog(): WidgetCatalog
    {
        return self::declaration();
    }

    /** The catalogue, reachable without an instance — see the class docblock. */
    public static function declaration(): WidgetCatalog
    {
        $groups = [];
        $presets = [];
        foreach (self::directions() as $letter => [$label, $tradeOff, $layout]) {
            $groups[] = new WidgetGroup($letter, $label, $tradeOff);
            // The preset id IS the direction's letter, exactly as the design
            // declares it, and the trade-off line is written ONCE — so a headed
            // section and the preset that adopts it can never disagree about what
            // the same design costs.
            $presets[] = new WidgetPreset($letter, $label, $tradeOff, $layout);
        }

        return new WidgetCatalog(
            self::SURFACE,
            $groups,
            self::widgets(),
            $presets,
            // A person who has never chosen opens on the shipped composition, not
            // on the first direction: the module's own screen is direction-neutral
            // on purpose, and picking one of the five for somebody would be making
            // the choice the gallery exists to let them make.
            WidgetCatalog::DEFAULT_PRESET_ID,
            self::DEFAULT_LABEL,
            self::DEFAULT_DESCRIPTION,
        );
    }

    /**
     * The surface's widgets, in the order the shipped composition lays them out.
     * The six with `on: true` are the patrols screen itself, the feed excepted
     * (see the feed widget below for why it is off), in the order the module
     * draws them — catalogue order IS the shipped composition. The nine the
     * directions need follow;
     * a widget's SECTION in the library comes from its `group`, never from its
     * place here.
     *
     * `cols` is the width the catalogue draws it at, the spans are the widths the
     * width-chips offer (widest first, as the shell's Widget enforces), and `on`
     * is whether the SHIPPED composition includes it.
     *
     * @return list<Widget>
     */
    private static function widgets(): array
    {
        return [
            new Widget('kpis', 'KPI strip', 'b', 12, [12, 9, 6, 3], on: true, note: 'Patrols, distance, coverage and the last patrol — this month.'),
            new Widget('map', 'Coverage map', 'a', 12, [12, 9, 6, 3], on: true, note: 'The area with every track on it; the filter here drives the log too.'),
            new Widget('log', 'Patrol log', 'b', 12, [12, 9, 6, 3], on: true, note: 'Every patrol as a row: date, type, station, distance, observations.'),
            // The feed is OFF the shipped composition: on the combined screen it
            // draws the SAME latest-N patrols the log register already lists, just
            // as a compact stream, which is duplication and nothing more. It stays
            // in the catalogue and LEADS direction C "Shift handover", where a
            // reverse-chron feed is the point; the register carries the default
            // screen alone.
            new Widget('feed', 'Patrol feed', 'c', 12, [12, 9, 6, 3], on: false, note: 'Every patrol newest first, with its type, distance and observation count.'),
            new Widget('chweek', 'Patrols per week', 'd', 6, [9, 6, 3], on: true, note: 'Patrols per week, by type.'),
            // Offers the full row as well as the half: "The patrol log" direction
            // draws it full-width under the log, and "Coverage & effort" at six.
            new Widget('chstation', 'By station', 'd', 6, [12, 9, 6, 3], on: true, note: 'Patrols by the station that logged them.'),
            new Widget('cal', 'Patrol calendar', 'e', 12, [12, 9, 6, 3], on: true, note: 'The month as a calendar, one mark per patrol.'),
            // ---- the nine widgets the five directions needed, all off by default ----
            new Widget('maplog', 'Coverage + log', 'a', 12, [12, 9], on: false, note: 'The coverage map at full height with the matching patrols docked beside it — one filter, one viewport, one list.'),
            new Widget('now', 'Out right now', 'a', 12, [12, 9, 6], on: false, note: 'The patrols that have started and not yet closed, with their last position ping and how long they have been out.'),
            new Widget('obsq', 'Observations awaiting action', 'c', 12, [12, 9, 6], on: false, note: 'Observations logged on patrol that nobody has filed as an incident yet, oldest first. The join between the two modules, made visible.'),
            new Widget('handover', 'Shift handover note', 'c', 12, [12, 9, 6], on: false, note: 'The last shift in one card: what closed, what is still open, and the three things the next shift is being handed.'),
            new Widget('gaps', 'Where nobody has been', 'd', 6, [12, 9, 6], on: false, note: 'Every zone by how long since a patrol last entered it, worst first. The one widget that shows absence rather than activity.'),
            new Widget('effort', 'Effort by ranger', 'd', 6, [12, 9, 6], on: false, note: 'Patrol-hours per ranger this month — who carried the month, not who logged the most rows.'),
            new Widget('export', 'Export & reporting', 'd', 12, [12, 9, 6], on: false, note: 'The three things this module hands to somebody else: the log as CSV, the tracks as GPX, the month as a coverage report.'),
            new Widget('plan', 'Planned vs actual', 'e', 12, [12, 9, 6], on: false, note: 'What was planned for each week against what was walked, and the gap between them.'),
            new Widget('roster', 'Patrols next week', 'e', 12, [12, 9, 6], on: false, note: 'The patrols planned for next week — which station, which lead, and which of those are confirmed rather than still pencilled.'),
        ];
    }

    /**
     * THE FIVE DIRECTIONS: the letter the library files each under, what it is
     * called, what the gallery says it COSTS, and the layout that IS that design —
     * listed is on, at the width listed, in that order; absent is off.
     *
     * The trade-off line is the gallery's own sentence, verbatim. It is written
     * once here and read twice — by the headed section and by the preset — so the
     * product can never say something about a direction that the design did not.
     *
     * @return array<string, array{string, string, array<string, int>}>
     */
    private static function directions(): array
    {
        return [
            'a' => [
                'Live coverage',
                'The map is the dashboard: every track this month at full height, the log docked beside it and whoever is still out on top. Best for the officer who has to see where cover is right now; says almost nothing about effort, planning, or the month as a whole.',
                ['kpis' => 12, 'now' => 12, 'maplog' => 12],
            ],
            'b' => [
                'The patrol log',
                'The book. Every patrol as a row — date, type, station, lead, distance, observations — under the month\'s headline numbers. Fastest for whoever keeps the record and the only direction that never hides a field; you have to picture the geography yourself.',
                ['kpis' => 12, 'log' => 12, 'chstation' => 12],
            ],
            'c' => [
                'Shift handover',
                'What came in, what is still out, and what the next shift inherits. Reads like a duty log and is the direction to hand a station over on; anything older than the last two shifts sinks out of sight.',
                ['handover' => 12, 'now' => 12, 'obsq' => 12, 'feed' => 12],
            ],
            'd' => [
                'Coverage & effort',
                'Where nobody has been, who did the walking, and the month as two charts with the export beside them. The direction that answers "is this area actually being covered" and the one a monthly report is written from; it never shows you an individual patrol.',
                ['kpis' => 12, 'gaps' => 6, 'effort' => 6, 'chweek' => 6, 'chstation' => 6, 'export' => 12],
            ],
            'e' => [
                'The month ahead',
                'The calendar leads, with planned against actual under it and next week\'s planned patrols below that. The only direction that looks forward rather than back — and the weakest for anything that has already happened.',
                ['cal' => 12, 'plan' => 12, 'roster' => 12],
            ],
        ];
    }
}
