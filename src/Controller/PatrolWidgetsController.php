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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;
use Uhifadhi\Patrol\Model\PatrolWidgets;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolWidgetUrls;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;

/**
 * THE WIDGET LIBRARY for the patrols surface — the one editing screen.
 *
 * The PAGE is chrome; everything inside it is the host's shared preset component
 * (templates/widgets/_library.html.twig), handed this surface's catalogue, this
 * surface's partial name and this AREA's routes. There are no patrol-specific
 * widget mechanics anywhere, which is the whole point of riding the host's
 * framework: composing a preset here works exactly as it does on departments,
 * team, zones and incidents.
 *
 * Every write is CSRF-checked and answered by {@see WidgetEndpoint}: this
 * controller validates nothing itself, mints no token and chooses no status
 * code.
 *
 * REGISTERED ONLY WHERE THE HOST RUNS SECURITY. A layout belongs to a PERSON,
 * so without a signed-in user there is nothing to read or write — a host in
 * that state gets no library at all rather than a screen that edits nobody's
 * preferences.
 *
 * A plain class, not a Symfony AbstractController subclass — see
 * PatrolController and config/services.php for the reusable-bundle rule.
 */
final class PatrolWidgetsController
{
    /**
     * @param array<string, array{label: string}> $types         the deployment's patrol.types vocabulary
     * @param int                                 $retentionDays patrol.discard_retention_days — the previewed log widget is the REAL one, and states removal dates from the same number
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly WidgetService $widgets,
        private readonly PatrolWidgetUrls $widgetUrls,
        private readonly WidgetEndpoint $endpoint,
        private readonly array $types,
        private readonly int $retentionDays = PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/widgets',
        name: 'patrol_widgets',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function library(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $catalog = PatrolWidgets::catalog();
        $userId = $this->endpoint->userId();
        $areaUuid = $area->getUuid();
        // Same instant for the last-patrol KPI and the calendar title, exactly
        // as the dashboard does it.
        $now = new \DateTimeImmutable();

        $dashboard = $this->dashboard->build(
            $this->patrols->findByAreaLatestFirst($area),
            $this->types,
            $now,
            // The library previews the REAL KPI strip, so PL·03 is queried here
            // exactly as the dashboard queries it.
            $this->patrols->coverageFractionWithin(
                $area,
                PatrolDashboardService::COVERAGE_BUFFER_M,
                ...PatrolDashboardService::monthRange($now),
            ),
        );

        return new Response($this->twig->render('@UhifadhiPatrol/widgets/show.html.twig', [
            'area' => $area,
            // The preset component, whole, over this surface's catalogue and
            // this AREA's routes.
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $userId, $areaUuid),
            'active' => $this->widgets->activeRef($catalog, $userId, $areaUuid),
            'widgets' => $this->widgets->resolve($catalog, $userId, $areaUuid),
            'partial' => '@UhifadhiPatrol/dashboard/_w_%s.html.twig',
            // EVERY widget partial renders the REAL widget on REAL data here,
            // at full size — the picture of a widget IS the widget, so what you
            // arrange is exactly what you get.
            'widgetContext' => [
                'area' => $area,
                'types' => $this->types,
                'typeColor' => PatrolDashboardService::typeColors($this->types),
                'now' => $now,
                'dashboard' => $dashboard,
                'coveragePayload' => $this->dashboard->coveragePayload($area->getGeom(), $dashboard, $this->types),
                'retentionDays' => $this->retentionDays,
            ],
            'urls' => $this->widgetUrls->forArea($area),
            'csrfToken' => $this->endpoint->csrfToken($catalog, $areaUuid),
        ]));
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/save', name: 'patrol_widgets_save', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->endpoint->save($request, PatrolWidgets::catalog(), $area->getUuid());
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/reset', name: 'patrol_widgets_reset', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function reset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->reset($request, PatrolWidgets::catalog(), $area->getUuid()),
            \sprintf('This area’s patrols dashboard is back to “%s”.', PatrolWidgets::DEFAULT_LABEL),
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/preset/{presetId}', name: 'patrol_widgets_preset', requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function applyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        $catalog = PatrolWidgets::catalog();
        // A design the surface does not ship is refused by the endpoint below;
        // naming it in the flash is only for the case where it IS shipped.
        $adopted = $catalog->preset($presetId);

        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyPreset($request, $catalog, $presetId, $area->getUuid()),
            \sprintf('This area’s patrols dashboard now follows “%s”.', null !== $adopted ? $adopted->label : $presetId),
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/preset/{presetId}/copy', name: 'patrol_widgets_preset_copy', requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 1)]
    public function copyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->copyPreset($request, PatrolWidgets::catalog(), $presetId, $area->getUuid()),
            'Copied — the copy is yours to edit, and the design it came from is untouched.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets', name: 'patrol_widgets_preset_create', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function createPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->createCustomPreset($request, PatrolWidgets::catalog(), $area->getUuid()),
            'Saved — this arrangement is now one of your own designs.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets/{presetUuid}/apply', name: 'patrol_widgets_preset_apply', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function applyCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyCustomPreset($request, PatrolWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Your design is on.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets/{presetUuid}/rename', name: 'patrol_widgets_preset_rename', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function renameCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->renameCustomPreset($request, PatrolWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Renamed.',
        );
    }

    #[Route('/areas/{uuid}/modules/patrols/widgets/presets/{presetUuid}/delete', name: 'patrol_widgets_preset_delete', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function deleteCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->deleteCustomPreset($request, PatrolWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Design deleted. Your dashboard is back on the one this module ships with.',
        );
    }

    /**
     * A refused write is returned as it came (the library's fetch() reads the
     * status and the message); a successful one says so and goes back to the
     * library, so the plain-form path works with no JavaScript at all.
     */
    private function afterWrite(Request $request, AreaOfInterest $area, Response $response, string $flash): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return new RedirectResponse($this->router->generate('patrol_widgets', ['uuid' => $area->getUuidString()]));
    }
}
