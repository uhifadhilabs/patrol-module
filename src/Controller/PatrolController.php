<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;

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
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly array $types,
        private readonly bool $recordScreens = false,
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

        return new Response($this->twig->render('@UhifadhiLabsPatrol/dashboard/show.html.twig', [
            'area' => $area,
            'types' => $this->types,
            'now' => $now,
            'recordScreens' => $this->recordScreens,
            'dashboard' => $this->dashboard->build(
                $this->patrols->findByAreaLatestFirst($area),
                $this->types,
                $now,
            ),
        ]));
    }
}
