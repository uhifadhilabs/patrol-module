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

namespace Uhifadhi\Patrol\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolMapService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\Widget\PatrolWidgets;

/**
 * The patrols widget dashboard for one area: KPIs, the coverage map, the patrol
 * log, the feed, the per-week and per-station charts and the month calendar.
 *
 * A plain class, not a Symfony AbstractController subclass: a reusable bundle
 * defines its services explicitly ("Services should not use autowiring or
 * autoconfiguration" — https://symfony.com/doc/current/bundles/best_practices.html),
 * and without autoconfiguration AbstractController's #[Required] setContainer is
 * never called. FrameworkBundle's own TemplateController/RedirectController are
 * written exactly this way — see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php.
 *
 * Patrols is a uhifadhi module, so the bundle may depend on the host's
 * AreaOfInterest (never the reverse); the route's uuid resolves to it via
 * MapEntity.
 */
// EVERY ROUTE BELOW BELONGS TO THIS MODULE, and says so: where an area has
// parked Patrols, the registry closes these routes before the controller runs.
#[Route(defaults: [PatrolModuleProvider::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final class PatrolController
{
    /**
     * @param array<string, array{label: string}> $types         the deployment's patrol.types vocabulary
     * @param bool                                $recordScreens whether the recording screens EXIST in this installation (they need SecurityBundle) — a question about the installation, not about the viewer
     * @param bool                                $widgetScreens whether the widget library exists in this installation (it needs SecurityBundle)
     * @param bool                                $manageScreens whether the observation-taxonomy admin EXISTS in this installation (it needs SecurityBundle) — the viewer question is asked separately, in {@see self::mayManage()}
     * @param TokenStorageInterface|null          $tokenStorage  null without security — the layout is then the shipped composition for everyone
     * @param AuthorizationCheckerInterface|null  $authorization null without security — see {@see self::mayRecord()}
     * @param int                                 $retentionDays patrol.discard_retention_days — the register row states each discarded patrol's removal date from it
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly PatrolMapService $plates,
        // The one place the day's live reading is measured — "out right now",
        // the zone gaps and the observation queue. The dashboard's direction
        // widgets (Out right now, Where nobody has been, Observations awaiting
        // action, the handover note) read exactly what the area overview reads,
        // so the two surfaces can never disagree about the same morning.
        private readonly PatrolOverviewService $overview,
        private readonly WidgetService $widgets,
        private readonly array $types,
        private readonly bool $recordScreens = false,
        private readonly bool $widgetScreens = false,
        private readonly bool $manageScreens = false,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly int $retentionDays = PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    #[Route('/areas/{uuid}/modules/patrols', name: 'patrol_dashboard', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function dashboard(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        // "now" is injected into the pure dashboard service (never read from a
        // clock inside it) and handed to the template too — the last-patrol KPI
        // and the calendar title are stated relative to the SAME instant.
        $now = new \DateTimeImmutable();

        // ONE FILTER DRIVES EVERYTHING: the MONTH the map, log and charts read is
        // the ?month=YYYY-MM the month dropdown drives, defaulting to the month
        // containing "now". Untrusted like every query field — a month that does
        // not parse degrades to the current month rather than throwing.
        [$monthStart, $nextMonth] = self::windowFor($request, $now);

        // The rows to LOAD are wider than the month: the calendar draws its dimmed
        // neighbours and the five-week chart runs back before the month — one
        // window covers all three, and the service buckets each widget within it.
        [$loadFrom, $loadUntil] = PatrolDashboardService::loadRange($monthStart, $now);
        $patrols = $this->patrols->findByAreaStartedBetweenLatestFirst($area, $loadFrom, $loadUntil);

        $dashboard = $this->dashboard->build(
            $patrols,
            $this->types,
            $now,
            // PL·03 is the one month figure the loaded rows cannot answer: it is
            // a PostGIS set operation over the month's tracks, asked for exactly
            // the window the service counts in.
            $this->patrols->coverageFractionWithin(
                $area,
                PatrolDashboardService::COVERAGE_BUFFER_M,
                $monthStart,
                $nextMonth,
            ),
            $monthStart,
            // The ZONE each patrol set out in — a live PostGIS spatial join
            // against the host's zone polygons, over exactly the month's rows.
            $patrolZones = $this->patrols->zonesForPatrols($area, $monthStart, $nextMonth),
        );

        return new Response($this->twig->render('@UhifadhiPatrol/dashboard/show.html.twig', [
            'area' => $area,
            'types' => $this->types,
            'typeColor' => PatrolDashboardService::typeColors($this->types),
            'now' => $now,
            // The month on screen — the filter's choice, so the bar can name it
            // and mark the chosen option, and the page can read one month.
            'month' => $monthStart,
            // patrol id → zone name, so the log rows can carry data-patrol-zone
            // and the client-side ZONE filter drives the map + log together.
            'patrolZones' => $patrolZones,
            'recordScreens' => $this->mayRecord(),
            'manageScreens' => $this->mayManage(),
            'retentionDays' => $this->retentionDays,
            'widgetScreens' => $this->widgetScreens,
            // Which widgets this person keeps, how wide, in what order — the
            // HOST's widget framework resolving this surface's catalogue: the
            // shipped composition until they change it in the widget library.
            'widgets' => $this->widgets->resolve(PatrolWidgets::declaration(), $this->widgetUser(), $area->getUuid()),
            'dashboard' => $dashboard,
            // What the coverage map draws — boundary + every recorded track this
            // month, each tagged with the zone it set out in.
            'map' => $this->plates->coverage(
                $this->dashboard->coveragePayload($area->getGeom(), $dashboard, $this->types, $patrolZones),
                $this->types,
                PatrolDashboardService::typeColors($this->types),
            ),
            // The live reading the off-by-default direction widgets need, from
            // the ONE service that measures it (see the overview service): who is
            // still out, which handsets are silent, the zone gaps, and the recent
            // observation queue. A dashboard that shows none of these still pays
            // for them, which is cheap; a preset that shows them must have them.
        ] + $this->overview->dashboardReading($area, $now)));
    }

    /**
     * THE MONTH THE DASHBOARD OPENS ON — the `month=YYYY-MM` the month dropdown
     * drives, or the month containing "now" when it is absent or unreadable.
     *
     * Untrusted like every other query field: a hand-edited month that does not
     * parse degrades to the current month rather than throwing. Mirrors the
     * incidents dashboard's own window resolution, so the two modules read a
     * chosen month the same way.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [monthStart, nextMonth]
     */
    public static function windowFor(Request $request, \DateTimeImmutable $now): array
    {
        // getString() is deliberate: a query bag holding an ARRAY for "month" is
        // a bad request, and this coerces it to '' rather than letting an array
        // reach the parse.
        $month = trim($request->query->getString('month'));
        if ('' !== $month) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');
            if (false !== $parsed) {
                return PatrolDashboardService::monthRange($parsed);
            }
        }

        return PatrolDashboardService::monthRange($now);
    }

    /**
     * WHETHER TO OFFER THE TWO RECORDING SCREENS — and it is TWO questions, not
     * one, which is the bug this method exists to fix.
     *
     * The first is about the INSTALLATION: the screens that create patrols are
     * registered only where SecurityBundle is, so where it is absent there is no
     * route to link at. That is `$this->recordScreens`, decided at compile time.
     *
     * The second is about THE VIEWER: both screens enforce `patrols.record` in
     * code, so somebody without it who follows either link gets a 403. Asking
     * only the first question meant every signed-in person was handed two doors,
     * and the ones who could not open them found out by being refused.
     *
     * A CONTROL THE VIEWER MAY NOT HAVE IS ABSENT, never greyed out — the fleet's
     * rule, and the stronger reading here: a disabled button tells a ranger a
     * screen exists and they are not trusted with it, and a live link that fails
     * tells them nothing until they have lost the click.
     */
    private function mayRecord(): bool
    {
        return $this->recordScreens
            && null !== $this->authorization
            && $this->authorization->isGranted(PatrolRecordController::RECORD_PERMISSION);
    }

    /**
     * WHETHER TO OFFER THE OBSERVATION-TAXONOMY ADMIN — the same two questions as
     * {@see self::mayRecord()}, and for the same reason.
     *
     * The admin's every route enforces `patrols.manage`, and the screen exists
     * only where SecurityBundle can enforce it. So the door is drawn only where
     * the route exists (`$this->manageScreens`, compile-time) AND the viewer
     * holds the permission — never as a greyed control a manager-less ranger
     * would click into a 403.
     */
    private function mayManage(): bool
    {
        return $this->manageScreens
            && null !== $this->authorization
            && $this->authorization->isGranted(PatrolTaxonomyController::MANAGE_PERMISSION);
    }

    /**
     * WHOSE LAYOUT TO RESOLVE — the contract's person, never an installation's
     * own account class. Null where the installation has no security, or nobody
     * is signed in, and the framework then draws the shipped composition, which
     * is the right screen for a reader who has arranged nothing.
     */
    private function widgetUser(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
