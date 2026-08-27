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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Service\GeoService;
use UhifadhiLabs\Patrol\Service\GpxWriter;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;
use UhifadhiLabs\Patrol\Storage\PatrolFileSource;

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
        private readonly UrlGeneratorInterface $urls,
        private readonly GeoService $geo,
        private readonly GpxWriter $gpx,
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
                // The area outline travels with every plate: a track is read
                // AGAINST it, and it is all the plate can draw when a
                // hand-logged patrol recorded no route at all.
                'boundary' => $area->getGeom(),
                'track' => $patrol->getTrack(),
                // The one colour this patrol's type is drawn in everywhere.
                'color' => $this->trackColor($patrol),
                'observations' => array_values(array_map(
                    fn (array $row): array => [
                        'n' => $row['n'],
                        'position' => $row['position'],
                        'category' => $this->categoryLabel($row['observation']->getCategory()),
                        // Clicking a ring opens that observation, exactly as the
                        // row beneath the plate does.
                        'url' => $this->observationUrl($area, $patrol, $row['observation']),
                    ],
                    array_filter($rows, static fn (array $row): bool => null !== $row['position']),
                )),
            ],
        ]));
    }

    /**
     * The recorded track back out as GPX — the same file a handheld would have
     * written, so a patrol can be replayed in any GPS tool, and the platform is
     * never a place data can only go in.
     *
     * Gated exactly as {@see show()} (area nesting is the access rule), plus the
     * honesty rule: a patrol with no recorded route has nothing to export and is
     * a 404, never an empty file. The detail page renders no button in that
     * case, so the URL is never offered either.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/{patrol}/export.gpx',
        name: 'patrol_export_gpx',
        requirements: ['uuid' => Requirement::UUID, 'patrol' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function exportGpx(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['patrol' => 'uuid'])] Patrol $patrol,
    ): Response {
        $this->assertPatrolBelongsTo($area, $patrol);

        $track = $patrol->getTrack();
        if (null === $track || !$patrol->hasRecordedTrack()) {
            throw new NotFoundHttpException('That patrol recorded no track to export.');
        }

        // The observations ride along as waypoints, numbered and labelled the
        // way the detail page numbers and labels them.
        $waypoints = [];
        foreach ($this->observationRows($patrol) as $row) {
            if (null === $row['position']) {
                continue;
            }
            $waypoints[] = [
                'position' => $row['position'],
                'name' => $row['n'].' · '.$this->categoryLabel($row['observation']->getCategory()),
                'description' => $row['observation']->getNote(),
                'time' => $row['observation']->getLoggedAt(),
            ];
        }

        $typeLabel = $this->types[$patrol->getType()]['label'] ?? $patrol->getType();
        $description = mb_strtolower($typeLabel).' patrol'
            .(null !== $patrol->getStation() ? ' · '.$patrol->getStation() : '');

        $document = $this->gpx->write(
            'Patrol '.$patrol->getRef(),
            $track,
            $waypoints,
            $patrol->getStartedAt(),
            $description,
        );

        $response = new StreamedResponse(static function () use ($document): void {
            echo $document;
        });
        $response->headers->set('Content-Type', 'application/gpx+xml; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $patrol->getRef().'.gpx',
        ));

        return $response;
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
        $index = null;
        foreach ($rows as $position => $candidate) {
            if ($candidate['observation']->getId() === $observation->getId()) {
                $row = $candidate;
                $index = $position;
            }
        }
        if (null === $row || null === $index) {
            throw new NotFoundHttpException('That observation is not part of this patrol.');
        }

        // Circling the patrol's observations without going back up: the arrows
        // WRAP (last → first), because a patrol's observations are a ring the
        // reader walks, not a list with a dead end — the settled observation
        // design says nothing either way, so the kinder reading wins. A patrol
        // with a single observation has no ring and gets no arrows.
        $total = \count($rows);
        $siblings = $total > 1 ? [
            'prev' => $this->observationLink($area, $patrol, $rows[($index - 1 + $total) % $total]),
            'next' => $this->observationLink($area, $patrol, $rows[($index + 1) % $total]),
        ] : ['prev' => null, 'next' => null];

        return new Response($this->twig->render('@UhifadhiLabsPatrol/observation/show.html.twig', [
            'area' => $area,
            'patrol' => $patrol,
            'observation' => $observation,
            'fileAsIncidentUrl' => $this->fileAsIncidentUrl($area, $patrol, $observation, $row),
            'types' => $this->types,
            'categories' => $this->categories,
            'n' => $row['n'],
            'total' => $total,
            'dms' => $row['dms'],
            'prev' => $siblings['prev'],
            'next' => $siblings['next'],
            // The parent track travels with the payload as context (drawn
            // faded), so the observation reads as a point ON the patrol.
            'payload' => [
                'boundary' => $area->getGeom(),
                'track' => $patrol->getTrack(),
                'color' => $this->trackColor($patrol),
                'observation' => [
                    'n' => $row['n'],
                    'position' => $row['position'],
                    'category' => $this->categoryLabel($observation->getCategory()),
                ],
                // EVERY observation of the patrol, in the same order the arrows
                // walk, so the plate draws the whole ring and the reader can
                // cycle by clicking a sibling as well as by the arrows. Exactly
                // one entry is `current`. `position` is null where the record
                // has none — the same honesty the rows keep; a consumer draws
                // only what is positioned.
                'observations' => array_map(
                    fn (array $sibling): array => [
                        'n' => $sibling['n'],
                        'position' => $sibling['position'],
                        'category' => $this->categoryLabel($sibling['observation']->getCategory()),
                        'url' => $this->observationUrl($area, $patrol, $sibling['observation']),
                        'current' => $sibling['observation']->getId() === $observation->getId(),
                    ],
                    $rows,
                ),
            ],
        ]));
    }

    /**
     * The File-as-incident seam, from this side: the incidents module (when the
     * host installs one) exposes `incident_new` accepting a prefill query
     * string (record/label/back/source/at/lat/lng/note — its IncidentPrefill).
     * The ROUTE NAME + QUERY KEYS are the whole contract; neither bundle names
     * the other's classes, and without the module the button simply isn't
     * rendered — the same graceful absence the design draws.
     *
     * @param array{n: int, observation: Observation, position: string|null, dms: string|null} $row
     */
    private function fileAsIncidentUrl(AreaOfInterest $area, Patrol $patrol, Observation $observation, array $row): ?string
    {
        $params = [
            'uuid' => (string) $area->getUuid(),
            'record' => (string) $observation->getUuid(),
            'label' => \sprintf('OBS-%02d · %s', $row['n'], $this->categoryLabel($observation->getCategory())),
            'back' => $this->observationUrl($area, $patrol, $observation),
            'source' => PatrolFileSource::SOURCE_TOKEN,
        ];
        if (null !== $observation->getLoggedAt()) {
            $params['at'] = $observation->getLoggedAt()->format(\DateTimeInterface::ATOM);
        }
        if (null !== $row['position']) {
            [$lat, $lng] = $this->geo->coordinates($row['position']);
            $params['lat'] = (string) $lat;
            $params['lng'] = (string) $lng;
        }
        if (null !== $observation->getNote() && '' !== $observation->getNote()) {
            $params['note'] = $observation->getNote();
        }

        try {
            return $this->urls->generate('incident_new', $params);
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException) {
            return null;
        }
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

    /** The one place the observation URL is spelled out. */
    private function observationUrl(AreaOfInterest $area, Patrol $patrol, Observation $observation): string
    {
        return $this->urls->generate('patrol_observation_show', [
            'uuid' => $area->getUuid(),
            'patrol' => $patrol->getUuid(),
            'observation' => $observation->getUuid(),
        ]);
    }

    /**
     * A neighbouring observation as the arrow needs it: where it goes, and the
     * number the arrow announces ("previous: observation 3 of 3").
     *
     * @param array{n: int, observation: Observation, position: string|null, dms: string|null} $row
     *
     * @return array{n: int, url: string, category: string}
     */
    private function observationLink(AreaOfInterest $area, Patrol $patrol, array $row): array
    {
        return [
            'n' => $row['n'],
            'url' => $this->observationUrl($area, $patrol, $row['observation']),
            'category' => $this->categoryLabel($row['observation']->getCategory()),
        ];
    }

    /**
     * The colour this patrol's TYPE is drawn in — the same value the dashboard
     * chips, the charts and the legend use, so one patrol never changes colour
     * between the coverage map and its own plate.
     */
    private function trackColor(Patrol $patrol): string
    {
        $colors = PatrolDashboardService::typeColors($this->types);

        return $colors[$patrol->getType()] ?? PatrolDashboardService::TRACK_COLORS[0];
    }

    /** The deployment's word for a category — never the stored key. */
    private function categoryLabel(string $category): string
    {
        return $this->categories[$category]['label'] ?? $category;
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
