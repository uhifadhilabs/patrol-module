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

namespace Uhifadhi\Patrol\Overview;

use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Area\Overview\ContributesStylesheetInterface;
use Uhifadhi\Area\Overview\OverviewContributorInterface;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\UhifadhiPatrolBundle;
use Uhifadhi\Widget\Model\Widget;
use Uhifadhi\Widget\Model\WidgetGroup;

/**
 * WHAT PATROLS PUTS ON THE AREA OVERVIEW — a transcription of the design's own
 * surface declaration (areas/ngorongoro/overview.widgets.js), which is the spec.
 *
 * NOT THE PATROLS DASHBOARD IN MINIATURE. The module's own screen is a month:
 * 142 patrols, 2,214 km, a calendar, a five-week series. These four cards are
 * the short-window slice an area manager needs BEFORE opening the module — who
 * is out, how the day is going, and where nobody has been — and they
 * deliberately do not add up to the month. Different question, different window.
 *
 * UNINSTALL THE MODULE AND EVERY ONE OF THESE DISAPPEARS from the library, from
 * the strip, from the attention list and from the map's legend. That is the
 * whole point of the seam, and it is why the group here is a CONTRIBUTOR rather
 * than a design direction: a person has to be able to tell that "Out right now"
 * came from patrols, so that its disappearance reads as the system working.
 *
 * THE COLUMN IS THE SAME CARDS. `pl_column` is this module's entire overview
 * section as one widget, and its template INCLUDES `_w_pl_now`, `_w_pl_today`
 * and `_w_pl_gaps` rather than restating them. That is the rule the host's
 * interface leaves to the module to keep, and including is the only way to keep
 * it: a card can never read one way on its own and another in the column,
 * because there is only one card.
 *
 * Tagged EXPLICITLY in the bundle extension, like `uhifadhi.module` and
 * `uhifadhi.department_kpi` before it — a reusable bundle is not autoconfigured,
 * so the host's registerForAutoconfiguration never fires for it.
 */
final readonly class PatrolOverviewContributor implements ContributesStylesheetInterface, OverviewContributorInterface
{
    /**
     * The same slug {@see \Uhifadhi\Patrol\Module\PatrolModuleProvider} declares.
     * The host asks this contributor for widgets ONLY where a module of that
     * slug is switched on, so the two must match or the section never appears.
     */
    public const string SLUG = 'patrols';

    public function __construct(
        private PatrolOverviewService $overview,
        /** @var array<string, array{label: string}> the deployment's patrol.types map */
        private array $types,
    ) {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            self::SLUG,
            'Patrols · uhifadhi/patrol-module',
            'What the patrols module contributes to the area overview. Not the patrols dashboard in miniature — the short-window slice an area manager needs before opening the module: who is out, how the day is going, and where nobody has been. Uninstall the module and every one of these disappears from the library.',
        );
    }

    /**
     * The design's five, with its ids, labels, widths and notes.
     *
     * Two of them offer a THIRD of the row (span 4) where the design offered a
     * quarter, because the host's grid has since gained that span for exactly
     * this case: the "Module columns" direction is a page made of one column per
     * module, and three columns have no expression in 12/9/6/3. `pl_column` is
     * the column, and `pl_today` is the card narrow enough to sit beside two
     * others; nothing else here reads at a third of the row.
     */
    public function widgets(): array
    {
        $group = self::SLUG;

        return [
            new Widget('pl_now', 'Out right now', $group, 6, [12, 9, 6], on: true, note: 'The patrols that have opened and not closed, with their last position ping. The only polling widget on the surface.'),
            new Widget('pl_today', 'Patrols today', $group, 6, [12, 9, 6, 4, 3], on: false, note: 'Today only — closed, kilometres, observations — against the same day last week.'),
            new Widget('pl_gaps', 'Where nobody has been', $group, 6, [12, 9, 6], on: false, note: 'Zones by days since a patrol last entered them, worst first. Absence, not activity.'),
            new Widget('pl_obsq', 'Observations awaiting action', $group, 12, [12, 9, 6], on: false, note: 'Observations logged on patrol that nobody has filed yet — the seam between the two modules.'),
            new Widget('pl_column', 'Patrols — the whole column', $group, 6, [12, 9, 6, 4, 3], on: false, note: 'The module’s entire overview section as ONE widget: its heading and its cards, stacked. A module may contribute a column as well as widgets.'),
        ];
    }

    public function partialPattern(): string
    {
        return '@UhifadhiPatrol/overview/_w_%s.html.twig';
    }

    /**
     * THE PLATES WEAR PATROL'S OWN VOCABULARY, and here the HOST renders them.
     *
     * Every other patrol page extends this module's `base.html.twig`, which
     * links this same sheet; the area overview extends the host's layout, so
     * without this the type dots, the stale-ping tone and the coverage legend on
     * a contributed plate render naked. The host asks only contributors that
     * implement {@see ContributesStylesheetInterface} — one with no CSS of its
     * own does not, and is asked nothing.
     *
     * The path is the BUNDLE'S constant, so this and base.html.twig cannot name
     * two different files.
     */
    public function stylesheet(): string
    {
        return UhifadhiPatrolBundle::STYLESHEET;
    }

    /**
     * Everything the module's five partials read, for this one area, measured
     * ONCE at `$now`.
     *
     * Four cards share one reading of the morning on purpose: "3 out" in the
     * strip, three rows on the live card and three live tracks on the plate are
     * the same three patrols, and computing them per widget is the difference
     * between one set of queries and four — and between cards that agree and
     * cards that were measured a second apart.
     *
     * @return array<string, mixed>
     */
    public function context(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $out = $this->overview->out($area, $now);

        return [
            'out' => $out,
            'handsets' => $this->overview->handsets($out),
            'today' => $this->overview->today($area, $now),
            'gaps' => $this->overview->gaps($area, $now),
            'observations' => $this->overview->observations($area, $now),
            // The deployment's own vocabulary and the ONE colour map every
            // patrol surface reads, so a walking round is the same word and the
            // same green here as on the module's dashboard.
            'types' => $this->types,
            'typeColors' => PatrolDashboardService::typeColors($this->types),
            'stalePingMinutes' => intdiv(PatrolOverviewService::PING_STALE_AFTER_SECONDS, 60),
            'coverageBufferKm' => PatrolDashboardService::COVERAGE_BUFFER_M / 1000,
            'dashboardUrl' => $this->overview->dashboardUrl($area),
        ];
    }
}
