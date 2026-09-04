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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Widget\PatrolWidgets;
use Uhifadhi\Widget\Service\WidgetService;

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
final class PatrolController
{
    /**
     * @param array<string, array{label: string}> $types         the deployment's patrol.types vocabulary
     * @param bool                                $recordScreens whether the recording screens EXIST in this installation (they need SecurityBundle) — a question about the installation, not about the viewer
     * @param bool                                $widgetScreens whether the widget library exists in this installation (it needs SecurityBundle)
     * @param TokenStorageInterface|null          $tokenStorage  null without security — the layout is then the shipped composition for everyone
     * @param AuthorizationCheckerInterface|null  $authorization null without security — see {@see self::mayRecord()}
     * @param int                                 $retentionDays patrol.discard_retention_days — the register row states each discarded patrol's removal date from it
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly WidgetService $widgets,
        private readonly array $types,
        private readonly bool $recordScreens = false,
        private readonly bool $widgetScreens = false,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly int $retentionDays = PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    #[Route('/areas/{uuid}/modules/patrols', name: 'patrol_dashboard', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function dashboard(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        // "now" is injected into the pure dashboard service (never read from a
        // clock inside it) and handed to the template too — the last-patrol KPI
        // and the calendar title are stated relative to the SAME instant.
        $now = new \DateTimeImmutable();

        $dashboard = $this->dashboard->build(
            $this->patrols->findByAreaLatestFirst($area),
            $this->types,
            $now,
            // PL·03 is the one month figure the loaded rows cannot answer: it is
            // a PostGIS set operation over the month's tracks, asked for exactly
            // the window the service counts in.
            $this->patrols->coverageFractionWithin(
                $area,
                PatrolDashboardService::COVERAGE_BUFFER_M,
                ...PatrolDashboardService::monthRange($now),
            ),
        );

        return new Response($this->twig->render('@UhifadhiPatrol/dashboard/show.html.twig', [
            'area' => $area,
            'types' => $this->types,
            'typeColor' => PatrolDashboardService::typeColors($this->types),
            'now' => $now,
            'recordScreens' => $this->mayRecord(),
            'retentionDays' => $this->retentionDays,
            'widgetScreens' => $this->widgetScreens,
            // Which widgets this person keeps, how wide, in what order — the
            // HOST's widget framework resolving this surface's catalogue: the
            // shipped composition until they change it in the widget library.
            'widgets' => $this->widgets->resolve(PatrolWidgets::declaration(), $this->widgetUser(), $area->getUuid()),
            'dashboard' => $dashboard,
            // What the coverage map draws — boundary + every recorded track.
            'coveragePayload' => $this->dashboard->coveragePayload($area->getGeom(), $dashboard, $this->types),
        ]));
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
