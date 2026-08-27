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

namespace UhifadhiLabs\Patrol\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\DependencyInjection\PatrolConfiguration;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;
use UhifadhiLabs\Patrol\Service\PatrolWidgetService;

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
     * @param bool                                $recordScreens whether the recording screens exist in this host (they need SecurityBundle)
     * @param bool                                $widgetScreens whether the widget library exists in this host (it needs SecurityBundle)
     * @param TokenStorageInterface|null          $tokenStorage  null in a host without security — the layout is then the design's default for everyone
     * @param int                                 $retentionDays patrol.discard_retention_days — the register row states each discarded patrol's removal date from it
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly PatrolWidgetService $widgets,
        private readonly array $types,
        private readonly bool $recordScreens = false,
        private readonly bool $widgetScreens = false,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly int $retentionDays = PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
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

        return new Response($this->twig->render('@UhifadhiLabsPatrol/dashboard/show.html.twig', [
            'area' => $area,
            'types' => $this->types,
            'typeColor' => PatrolDashboardService::typeColors($this->types),
            'now' => $now,
            'recordScreens' => $this->recordScreens,
            'retentionDays' => $this->retentionDays,
            'widgetScreens' => $this->widgetScreens,
            // Which widgets this person keeps, how wide, in what order — the
            // design's own layout until they change it in the widget library.
            'widgets' => $this->widgets->resolve($area->getUuid(), $this->userId()),
            'dashboard' => $dashboard,
            // What the coverage map draws — boundary + every recorded track.
            'coveragePayload' => $this->dashboard->coveragePayload($area->getGeom(), $dashboard, $this->types),
        ]));
    }

    /** Null where the host has no security, or nobody is signed in: the defaults. */
    private function userId(): ?int
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
