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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\ObservationAmendmentKindEnum;
use Uhifadhi\Patrol\Repository\ObservationAmendmentRepository;
use Uhifadhi\Patrol\Service\GeoService;
use Uhifadhi\Patrol\Service\GpxWriter;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Storage\PatrolFileSource;

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
     * @param array<string, array{label: string}> $types                the deployment's patrol.types vocabulary
     * @param array<string, array{label: string}> $categories           the deployment's patrol.observation_categories vocabulary
     * @param int                                 $discardRetentionDays patrol.discard_retention_days — what the purge-window line states
     * @param AuthorizationCheckerInterface|null  $authorizationChecker null in a host with no security: the hold action is then offered to nobody
     * @param CsrfTokenManagerInterface|null      $csrfTokenManager     null for the same reason — a write with no token to protect it is not rendered
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly GeoService $geo,
        private readonly GpxWriter $gpx,
        private readonly ObservationAmendmentRepository $amendments,
        private readonly array $types,
        private readonly array $categories,
        private readonly int $discardRetentionDays = PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
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

        return new Response($this->twig->render('@UhifadhiPatrol/patrol/show.html.twig', [
            'area' => $area,
            'patrol' => $patrol,
            'types' => $this->types,
            'categories' => $this->categories,
            'observations' => $rows,
            'avgSpeedKmh' => $this->avgSpeedKmh($patrol),
            // What the page says will happen to a discarded patrol, and when.
            // Null while it is held: the clock is stopped, and printing a date
            // would promise a deletion that is not scheduled.
            'purgeDueAt' => $this->purgeDueAt($patrol),
            'retentionDays' => $this->discardRetentionDays,
            // The hold action, or nothing at all. Absent rather than disabled
            // for whoever may not use it: a greyed control advertises a power
            // the reader does not have.
            'holdToken' => $this->holdToken($patrol),
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
        Request $request,
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

        return new Response($this->twig->render('@UhifadhiPatrol/observation/show.html.twig', [
            'area' => $area,
            'patrol' => $patrol,
            'observation' => $observation,
            'fileAsIncidentUrl' => $this->fileAsIncidentUrl($area, $patrol, $observation, $row),
            'types' => $this->types,
            'categories' => $this->categories,
            'n' => $row['n'],
            'total' => $total,
            'dms' => $row['dms'],
            'photoDms' => $this->photoPositions($observation),
            // THE AMENDMENT TRAIL (PL·06–PL·09). Read through the repository
            // rather than off the collection so the ordering is stated once, in
            // SQL, and a lazily-loaded collection can never hand the page a
            // trail in a different order than the history above it.
            'amendments' => $this->amendments->findForObservation($observation),
            'amendmentCountWord' => self::countWord($observation->amendmentCount()),
            'amendmentKinds' => ObservationAmendmentKindEnum::cases(),
            // The affordance is offered only to somebody who could actually use
            // it — a button that 403s is a worse answer than no button.
            'canAmend' => $canAmend = $this->canAmend(),
            'amending' => $canAmend && $request->query->getBoolean('amend'),
            // Null where there is no token manager at all, which is the same
            // hosts where canAmend() is false — but stated separately, because
            // the template must never be handed a token it cannot have.
            'amendToken' => $canAmend
                ? $this->csrfTokenManager?->getToken(ObservationAmendmentController::csrfTokenId($observation))->getValue()
                : null,
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

    /**
     * Whether THIS caller may append a correction — the module's one recording
     * permission, the same one that gates the hold and the recording screens
     * ("anyone who may edit the patrol", PL·09).
     *
     * False wherever the host runs no security: there is nobody to sign an
     * amendment, and the route to post one was never registered.
     */
    private function canAmend(): bool
    {
        return $this->authorizationChecker?->isGranted(PatrolRecordController::RECORD_PERMISSION) ?? false;
    }

    /**
     * "Corrected ONCE since", "corrected TWICE since", "corrected 3 TIMES
     * since" — the design's own wording, which is how a person says it.
     *
     * English has words for the first two and then gives up, and so does this:
     * "corrected 2 times" is the kind of phrasing that makes a careful record
     * read like a machine wrote it.
     */
    private static function countWord(int $count): string
    {
        return match ($count) {
            1 => 'once',
            2 => 'twice',
            default => $count.' times',
        };
    }

    /**
     * WHERE EACH PHOTOGRAPH WAS TAKEN, in the same degrees-minutes-seconds the
     * observation's own position is read in — keyed by the photograph's client
     * uuid, which is the one id a template can address a photo by.
     *
     * A photograph's place is its OWN, not the observation's: a ranger stands
     * where it is safe to stand and photographs what is over there. A photograph
     * with no fix is absent from this map rather than present with a null, so
     * the template's `default` says "no position recorded" and nothing silently
     * inherits the observation's coordinates.
     *
     * @return array<string, string>
     */
    private function photoPositions(Observation $observation): array
    {
        $positions = [];
        foreach ($observation->getPhotos() as $photo) {
            $position = $photo->getPosition();
            if (null === $position || '' === $position) {
                continue;
            }
            $positions[$photo->getClientUuid()->toRfc4122()] = $this->geo->formatDms(
                ...$this->geo->coordinates($position),
            );
        }

        return $positions;
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

    /**
     * When `patrol:purge-discarded` will delete this patrol, if nothing changes.
     *
     * Null for every patrol that is not discarded (there is no window), for one
     * that is HELD (the clock is stopped, so there is no date to state), and for
     * one the module cannot date at all — the same three cases the command
     * itself distinguishes, computed from the same {@see Patrol::discardedAt()}.
     */
    private function purgeDueAt(Patrol $patrol): ?\DateTimeImmutable
    {
        if (!$patrol->isDiscarded() || $patrol->isHeld()) {
            return null;
        }

        return $patrol->discardedAt()?->modify(\sprintf('+%d days', $this->discardRetentionDays));
    }

    /**
     * The CSRF token for the hold form — or null, which is how the template
     * learns not to draw the form at all.
     *
     * Null in three situations, and they collapse to one rule: the action is
     * offered only where it can be both performed and protected. No security
     * bundle (the route does not exist), no `patrols.record` (this reader may
     * not), or a patrol that was never discarded (there is no clock to stop).
     */
    private function holdToken(Patrol $patrol): ?string
    {
        if (!$patrol->isDiscarded() || null === $this->csrfTokenManager || null === $this->authorizationChecker) {
            return null;
        }

        if (!$this->authorizationChecker->isGranted(PatrolRecordController::RECORD_PERMISSION)) {
            return null;
        }

        return $this->csrfTokenManager->getToken(PatrolHoldController::csrfTokenId($patrol))->getValue();
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
