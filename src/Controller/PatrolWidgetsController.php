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
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Exception\InvalidWidgetPreferenceException;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;
use UhifadhiLabs\Patrol\Service\PatrolWidgetService;

/**
 * The widget library — the module's ONE editing surface. Per the settled design
 * every widget is shown as the real widget at full size (the same partials the
 * dashboard renders, on the same live data), with its own on/off toggle, width
 * chips and drag handle beside it. The dashboard itself stays clean: nothing is
 * edited there.
 *
 * A layout is one person's, so all three routes need a signed-in user; the
 * service is registered only where the host runs SecurityBundle (see
 * UhifadhiLabsPatrolBundle) and the routes simply do not exist otherwise. No
 * further permission is asked: arranging your own dashboard is not a privilege,
 * and the data on show is the dashboard's, which this user already reads.
 *
 * A plain class, not a Symfony AbstractController subclass — see PatrolController
 * and config/services.php for the reusable-bundle rule.
 */
final class PatrolWidgetsController
{
    /**
     * The header the library's fetch() carries its CSRF token in. A header, not a
     * form field: the body is JSON, and only same-origin script can set one — a
     * cross-site form can post a body but never this.
     */
    public const string CSRF_HEADER = 'X-CSRF-Token';

    /**
     * @param array<string, array{label: string}> $types the deployment's patrol.types vocabulary
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly PatrolWidgetService $widgets,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly array $types,
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
        $userId = $this->userId();
        // Same instant for the last-patrol KPI and the calendar title, exactly as
        // the dashboard does it.
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

        return new Response($this->twig->render('@UhifadhiLabsPatrol/widgets/show.html.twig', [
            'area' => $area,
            'types' => $this->types,
            'typeColor' => PatrolDashboardService::typeColors($this->types),
            'now' => $now,
            // Handed to the template rather than fetched there with Twig's
            // csrf_token(): that function only exists where the host wired the
            // twig-bridge CSRF extension, and this screen must not depend on it.
            'csrfToken' => $this->csrfTokenManager->getToken(self::csrfTokenId($area))->getValue(),
            'widgets' => $this->widgets->resolve($area->getUuid(), $userId),
            'dashboard' => $dashboard,
            // The library previews the REAL map widget, so it carries the real
            // payload the dashboard's map carries.
            'coveragePayload' => $this->dashboard->coveragePayload($area->getGeom(), $dashboard, $this->types),
        ]));
    }

    /**
     * The whole layout in one JSON body — the library posts its complete state
     * after every toggle, width change and drop, so a partial write can never
     * leave a half-applied layout behind.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/widgets',
        name: 'patrol_widgets_save',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function save(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($area, $request);

        // A body that is not JSON, or a layout the catalogue does not recognise,
        // is unprocessable rather than malformed — 422, as the recording screens
        // answer a rejected form.
        try {
            /** @var array<string, mixed> $payload */
            $payload = $request->toArray();
            $this->widgets->save($area->getUuid(), $userId, $payload);
        } catch (InvalidWidgetPreferenceException|JsonException $invalid) {
            return new Response($invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/widgets/reset',
        name: 'patrol_widgets_reset',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function reset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($area, $request);
        $this->widgets->reset($area->getUuid(), $userId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Scoped per area: a token minted for one area's library cannot rearrange
     * another's.
     */
    private static function csrfTokenId(AreaOfInterest $area): string
    {
        return 'patrol_widgets_'.$area->getUuid()->toRfc4122();
    }

    /**
     * Both writes are state-changing, so both carry the token. Read from the
     * header the library sends, falling back to the conventional "_token"
     * parameter so a plain form post can reach these routes too.
     */
    private function denyUnlessCsrfValid(AreaOfInterest $area, Request $request): void
    {
        $submitted = $request->headers->get(self::CSRF_HEADER)
            ?? $request->request->getString('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($area), $submitted))) {
            throw new AccessDeniedException('Invalid CSRF token for the patrol widget library.');
        }
    }

    /** A layout belongs to a person; without one there is nothing to read or write. */
    private function userId(): int
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $id = $user instanceof User ? $user->getId() : null;
        if (null === $id) {
            throw new AccessDeniedException('Widget preferences belong to a signed-in user.');
        }

        return $id;
    }
}
