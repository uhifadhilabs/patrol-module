<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use UhifadhiLabs\PatrolBundle\Entity\Observation;
use UhifadhiLabs\PatrolBundle\Entity\Patrol;
use UhifadhiLabs\PatrolBundle\Service\GeoService;

/**
 * The two patrol detail screens (settled designs "detail" and "observation"):
 * one patrol — its track plate, meta, history and the observations logged en
 * route — and one observation — its location plate, meta, verbatim note and
 * history.
 *
 * Both are VIEW-ONLY: the designs carry no edit controls on a detail page.
 *
 * Every URL is area-nested (an area owns its module screens), and a record
 * reached through the wrong parent is a 404, never a page about someone else's
 * data: a patrol whose area is not the URL's area, or an observation whose
 * patrol is not the URL's patrol.
 *
 * A plain class, not a Symfony AbstractController subclass — see
 * PatrolController and config/services.php for the reusable-bundle rule.
 */
final class PatrolDetailController
{
    /**
     * @param array<string, array{label: string}> $types      the deployment's patrol.types vocabulary
     * @param array<string, array{label: string}> $categories the deployment's patrol.observation_categories vocabulary
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly GeoService $geo,
        private readonly array $types,
        private readonly array $categories,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/{patrol}',
        name: 'patrol_show',
        requirements: ['uuid' => Requirement::UUID, 'patrol' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['patrol' => 'uuid'])] Patrol $patrol,
    ): Response {
        $this->assertPatrolBelongsTo($area, $patrol);

        $rows = $this->observationRows($patrol);

        return new Response($this->twig->render('@UhifadhiLabsPatrol/patrol/show.html.twig', [
            'area' => $area,
            'patrol' => $patrol,
            'types' => $this->types,
            'categories' => $this->categories,
            'observations' => $rows,
            'avgSpeedKmh' => $this->avgSpeedKmh($patrol),
            // The plate payload: the recorded track plus the positioned
            // observations, which the controller draws as numbered rings.
            'payload' => [
                'track' => $patrol->getTrack(),
                'observations' => array_values(array_map(
                    static fn (array $row): array => [
                        'n' => $row['n'],
                        'position' => $row['position'],
                        'category' => $row['observation']->getCategory(),
                    ],
                    array_filter($rows, static fn (array $row): bool => null !== $row['position']),
                )),
            ],
        ]));
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/{patrol}/observations/{observation}',
        name: 'patrol_observation_show',
        requirements: ['uuid' => Requirement::UUID, 'patrol' => Requirement::UUID, 'observation' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function observationShow(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['patrol' => 'uuid'])] Patrol $patrol,
        #[MapEntity(mapping: ['observation' => 'uuid'])] Observation $observation,
    ): Response {
        $this->assertPatrolBelongsTo($area, $patrol);
        if ($observation->getPatrol()->getId() !== $patrol->getId()) {
            throw new NotFoundHttpException('That observation belongs to another patrol.');
        }

        $rows = $this->observationRows($patrol);
        $row = null;
        foreach ($rows as $candidate) {
            if ($candidate['observation']->getId() === $observation->getId()) {
                $row = $candidate;
            }
        }
        if (null === $row) {
            throw new NotFoundHttpException('That observation is not part of this patrol.');
        }

        return new Response($this->twig->render('@UhifadhiLabsPatrol/observation/show.html.twig', [
            'area' => $area,
            'patrol' => $patrol,
            'observation' => $observation,
            'types' => $this->types,
            'categories' => $this->categories,
            'n' => $row['n'],
            'total' => \count($rows),
            'dms' => $row['dms'],
            // The parent track travels with the payload as context (drawn
            // faded), so the observation reads as a point ON the patrol.
            'payload' => [
                'track' => $patrol->getTrack(),
                'observation' => [
                    'n' => $row['n'],
                    'position' => $row['position'],
                    'category' => $observation->getCategory(),
                ],
            ],
        ]));
    }

    /**
     * The patrol's observations in logged order, each numbered from 1 — the
     * number the design prints in the amber ring on the plate AND in the row
     * badge, so both must come from this one list.
     *
     * @return list<array{n: int, observation: Observation, position: string|null, dms: string|null}>
     */
    private function observationRows(Patrol $patrol): array
    {
        $rows = [];
        $n = 0;
        foreach ($patrol->getObservations() as $observation) {
            ++$n;
            $position = $observation->getPosition();
            $rows[] = [
                'n' => $n,
                'observation' => $observation,
                'position' => $position,
                'dms' => null !== $position ? $this->geo->formatDms(...$this->geo->coordinates($position)) : null,
            ];
        }

        return $rows;
    }

    /** Distance over elapsed time, km/h — stated only when both are known. */
    private function avgSpeedKmh(Patrol $patrol): ?float
    {
        $distanceKm = $patrol->getDistanceKm();
        $startedAt = $patrol->getStartedAt();
        $endedAt = $patrol->getEndedAt();
        if (null === $distanceKm || null === $startedAt || null === $endedAt) {
            return null;
        }

        $hours = ($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 3600;

        return $hours > 0 ? $distanceKm / $hours : null;
    }

    private function assertPatrolBelongsTo(AreaOfInterest $area, Patrol $patrol): void
    {
        if ($patrol->getArea()->getId() !== $area->getId()) {
            throw new NotFoundHttpException('That patrol belongs to another area.');
        }
    }
}
