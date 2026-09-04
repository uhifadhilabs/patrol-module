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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Exception\InvalidGpxException;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\TrackIngestService;

/**
 * The two screens that CREATE patrols (settled designs "import" and "log"):
 * uploading a GPX track, and logging a patrol that was never tracked.
 *
 * Both are entirely about recording, so both — GET included — require the one
 * permission this module declares, "patrols.record". The bundle DECLARES the
 * requirement (see {@see \Uhifadhi\Patrol\Module\PatrolModuleProvider});
 * the host's voter decides who holds it. Installing a module may never hand
 * existing users a new power, so the bundle grants it to nobody.
 *
 * Checked in code rather than with #[IsGranted]: that attribute is honoured by a
 * listener in symfony/security-http, which this bundle does not require — a host
 * without it would get silently UNPROTECTED recording screens. Symfony treats
 * security as an optional dependency the same way (symfony/twig-bridge lists
 * security-* under require-dev and injects a nullable AuthorizationCheckerInterface).
 * Here the service is only registered when the host has security at all, so the
 * checker is never null and the routes simply do not exist otherwise — see
 * UhifadhiPatrolBundle::loadExtension().
 *
 * Neither screen parses or persists a track itself: TrackIngestService is THE
 * ingest path (one service, two doors — this form today, the tracker app's API
 * POST later), and the controller stays thin.
 *
 * A plain class, not a Symfony AbstractController subclass — see PatrolController
 * and config/services.php for the reusable-bundle rule.
 */
final class PatrolRecordController
{
    /**
     * The permission both screens require. Declared by the module; the host's
     * voter decides which positions actually hold it.
     */
    public const string RECORD_PERMISSION = 'patrols.record';

    /**
     * @param array<string, array{label: string}> $types the deployment's patrol.types vocabulary
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolRepository $patrols,
        private readonly PatrolDashboardService $dashboard,
        private readonly TrackIngestService $ingest,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly array $types,
        private readonly float $gapThresholdMinutes,
    ) {
    }

    /**
     * Import a GPX track — ONE page in two steps, exactly as the design composes
     * it: upload (PL·01) + patrol details (PL·02), and after a parse the parsed
     * preview (PL·03) beside them. The confirm submit re-posts to the same route.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/import',
        name: 'patrol_import',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function import(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->denyUnlessRecorder();

        $form = self::submittedDetails($request);
        $filename = $request->request->getString('filename') ?: null;
        $filesize = $request->request->getInt('filesize');
        $gpxXml = null;
        $track = null;
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $uploaded = $request->files->get('gpx');
            if ($uploaded instanceof UploadedFile) {
                $gpxXml = (string) file_get_contents($uploaded->getPathname());
                $filename = $uploaded->getClientOriginalName();
                $filesize = (int) $uploaded->getSize();
            } else {
                // The confirm step: the uploaded XML travelled back in a hidden
                // base64 field rather than a session, because a reusable bundle
                // cannot assume the host gives it one — and re-picking the file
                // to confirm it is not a step anyone should have to repeat.
                $encoded = $request->request->getString('gpxData');
                $decoded = '' !== $encoded ? base64_decode($encoded, true) : false;
                $gpxXml = \is_string($decoded) && '' !== $decoded ? $decoded : null;
            }

            if (null === $gpxXml) {
                $error = 'Choose a .gpx track to import.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                try {
                    $track = $this->ingest->preview($gpxXml);
                } catch (InvalidGpxException $invalid) {
                    $error = $invalid->getMessage();
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $gpxXml = null;
                }
            }

            // A parsed track means the XML above is readable — confirming saves
            // exactly the bytes that were previewed.
            if (null !== $track && $request->request->has('confirm')) {
                if (!isset($this->types[$form['type']])) {
                    $error = 'Choose a patrol type.';
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } else {
                    $patrol = $this->ingest->ingest(
                        $gpxXml,
                        $area,
                        $form['type'],
                        PatrolSourceEnum::Gpx,
                        $form['station'],
                        $this->lead($form['lead']),
                        $form['team'],
                        $form['note'],
                    );
                    $this->addFlash($request, 'success', \sprintf('Patrol %s imported from the GPX track.', $patrol->getRef()));

                    return new RedirectResponse($this->urlGenerator->generate('patrol_show', [
                        'uuid' => $area->getUuid()->toRfc4122(),
                        'patrol' => $patrol->getUuid()->toRfc4122(),
                    ]));
                }
            }
        }

        return new Response(
            $this->twig->render('@UhifadhiPatrol/import/show.html.twig', [
                'area' => $area,
                'types' => $this->types,
                'stations' => $this->stations($area),
                'users' => $this->users(),
                'form' => $form,
                'track' => $track,
                'filename' => $filename,
                'filesize' => $filesize,
                // Kept for the confirm submit; never echoed as anything but a
                // hidden value.
                'gpxData' => null !== $gpxXml ? base64_encode($gpxXml) : null,
                // The area outline is drawn under the parsed track, so an
                // imported file can be seen to land inside the area.
                'payload' => [
                    'boundary' => $area->getGeom(),
                    'track' => $track?->toGeoJson(),
                ],
                'gapThresholdMinutes' => $this->gapThresholdMinutes,
                'error' => $error,
            ]),
            $status,
        );
    }

    /**
     * Log a patrol by hand — no file, no track. The record is stamped
     * {@see PatrolSourceEnum::Manual} so a hand-entered patrol can never be
     * mistaken for a recorded one.
     */
    #[Route(
        '/areas/{uuid}/modules/patrols/log',
        name: 'patrol_log',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function log(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->denyUnlessRecorder();

        $form = self::submittedDetails($request);
        $error = null;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $startedAt = self::parseMoment($form['startedAt']);
            $endedAt = self::parseMoment($form['endedAt']);

            if (!isset($this->types[$form['type']])) {
                $error = 'Choose a patrol type.';
            } elseif (null === $startedAt) {
                $error = 'A patrol needs the time it started.';
            } elseif (null !== $endedAt && $endedAt <= $startedAt) {
                $error = 'A patrol cannot end before it started.';
            }

            // No error means every rule above passed, the required start included.
            if (null === $error) {
                $patrol = new Patrol($area, $form['type'])
                    ->setSource(PatrolSourceEnum::Manual)
                    ->setStation($form['station'])
                    ->setLead($this->lead($form['lead']))
                    ->setTeam($form['team'])
                    ->setNote($form['note'])
                    ->setStartedAt($startedAt)
                    ->setEndedAt($endedAt)
                    ->setDistanceKm($form['distanceKm']);
                $this->entityManager->persist($patrol);
                $this->entityManager->flush();
                $this->addFlash($request, 'success', \sprintf('Patrol %s logged.', $patrol->getRef()));

                return new RedirectResponse($this->urlGenerator->generate('patrol_show', [
                    'uuid' => $area->getUuid()->toRfc4122(),
                    'patrol' => $patrol->getUuid()->toRfc4122(),
                ]));
            }

            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return new Response(
            $this->twig->render('@UhifadhiPatrol/log/show.html.twig', [
                'area' => $area,
                'types' => $this->types,
                'stations' => $this->stations($area),
                'users' => $this->users(),
                'form' => $form,
                'error' => $error,
            ]),
            $status,
        );
    }

    /** Recording patrols is the privilege; nothing here runs without it. */
    private function denyUnlessRecorder(): void
    {
        if (!$this->authorizationChecker->isGranted(self::RECORD_PERMISSION)) {
            throw new AccessDeniedException('Recording a patrol needs the patrols.record permission.');
        }
    }

    /**
     * What the form contributed — everything a track cannot know. Read once, so
     * a re-rendered page (preview, or an error) shows back what was typed.
     *
     * @return array{type: string, station: ?string, lead: ?int, team: ?string, note: ?string, startedAt: ?string, endedAt: ?string, distanceKm: ?float}
     */
    private static function submittedDetails(Request $request): array
    {
        $lead = $request->request->getInt('lead');
        $distance = trim($request->request->getString('distanceKm'));

        return [
            'type' => $request->request->getString('type'),
            'station' => self::trimmedOrNull($request->request->getString('station')),
            'lead' => $lead > 0 ? $lead : null,
            'team' => self::trimmedOrNull($request->request->getString('team')),
            'note' => self::trimmedOrNull($request->request->getString('note')),
            'startedAt' => self::trimmedOrNull($request->request->getString('startedAt')),
            'endedAt' => self::trimmedOrNull($request->request->getString('endedAt')),
            'distanceKm' => is_numeric($distance) ? (float) $distance : null,
        ];
    }

    private static function trimmedOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }

    /** A datetime-local value ("2026-08-22T05:55"), or null when absent/unreadable. */
    private static function parseMoment(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function lead(?int $id): ?UserInterface
    {
        return null !== $id ? $this->entityManager->getRepository(UserInterface::class)->find($id) : null;
    }

    /**
     * The people the deployment can name as a lead. `lead` is a relation to a
     * person, never free text — the detail screen prints "A. Alpha" from the
     * record. The repository is asked for by the CONTRACT, which the
     * installation has resolved to its own account class; this module never
     * learns what that class is.
     *
     * @return list<UserInterface>
     */
    private function users(): array
    {
        return $this->entityManager->getRepository(UserInterface::class)
            ->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);
    }

    /**
     * The stations already recorded in this area, ranked — the same list the
     * dashboard's station menu offers, computed the same way.
     *
     * @return list<string>
     */
    private function stations(AreaOfInterest $area): array
    {
        return $this->dashboard->build(
            $this->patrols->findByAreaLatestFirst($area),
            $this->types,
            new \DateTimeImmutable(),
        )->stations;
    }

    /** No-op when the request has no session (stateless calls). */
    private function addFlash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
