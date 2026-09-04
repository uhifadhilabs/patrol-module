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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationAmendment;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\ObservationAmendmentKindEnum;
use Uhifadhi\Patrol\Service\PhotoEvidenceKey;
use Uhifadhi\Storage\Exception\EvidenceRejectedException;
use Uhifadhi\Storage\Exception\EvidenceStorageFailedException;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * APPENDING ONE CORRECTION to an observation — the settled design's PL·06–PL·09.
 *
 * ONE ROUTE, ONE VERB, AND IT IS AN INSERT. There is deliberately no edit
 * counterpart and no delete counterpart: PL·09 states the rules plainly — the
 * original is never edited, an amendment is never deleted, and a wrong amendment
 * is corrected by another amendment. Routes that do not exist cannot be reached
 * by a bug, which is a stronger guarantee than a check that could be forgotten.
 *
 * THE FORM IS NOT HERE. It is rendered inline on the observation's own page
 * (`?amend=1`), below the trail, because you have to be able to read the
 * original while you write the correction. A modal would put the thing being
 * corrected behind the thing correcting it.
 *
 * WHO MAY: "anyone who may edit the patrol" — the module's one recording
 * permission, checked in code for the same reason PatrolRecordController checks
 * it in code (the #[IsGranted] attribute is honoured by a listener in
 * symfony/security-http, which this bundle does not require).
 *
 * A plain class, not a Symfony AbstractController subclass — see PatrolController
 * and config/services.php for the reusable-bundle rule.
 */
final class ObservationAmendmentController
{
    /** What one correction may say, in characters. Long enough for a paragraph. */
    private const int BODY_MAX = 4000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly EvidenceStorage $evidence,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/{patrol}/observations/{observation}/amendments',
        name: 'patrol_observation_amend',
        requirements: [
            'uuid' => Requirement::UUID,
            'patrol' => Requirement::UUID,
            'observation' => Requirement::UUID,
        ],
        methods: ['POST'],
    )]
    public function append(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['patrol' => 'uuid'])] Patrol $patrol,
        #[MapEntity(mapping: ['observation' => 'uuid'])] Observation $observation,
        Request $request,
    ): Response {
        // Area nesting is the access rule on every patrol screen: a record
        // reached through the wrong parent is a 404, never a write on somebody
        // else's data.
        if ($patrol->getArea()->getId() !== $area->getId()
            || $observation->getPatrol()->getId() !== $patrol->getId()) {
            throw new NotFoundHttpException('That observation belongs to another patrol.');
        }

        $this->denyUnlessRecorder();
        $this->denyUnlessCsrfValid($observation, $request);

        $kind = ObservationAmendmentKindEnum::tryFromSubmitted($request->request->getString('kind'));
        if (null === $kind) {
            return new Response(
                'Choose what is being corrected.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // An amendment with nothing written in it corrects nothing. Refused
        // rather than stored, because an empty entry on an evidence trail reads
        // as "something was changed here" while saying what.
        $body = trim($request->request->getString('body'));
        if ('' === $body) {
            return new Response(
                'Say what is right — an amendment with nothing in it corrects nothing.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return new Response(
                \sprintf('An amendment may be up to %d characters.', self::BODY_MAX),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $author = $this->currentUser();

        $amendment = new ObservationAmendment($observation, $kind, $body)
            // The name is copied NOW and kept, so the trail can still be read
            // back after the person has left the service.
            ->withAuthor($author, $author->getFullName())
            ->withSupersededValue($this->supersededValue($request));

        $photo = $this->attachedPhoto($observation, $request);
        if ($photo instanceof ObservationPhoto) {
            $amendment->withPhoto($photo);
            $this->entityManager->persist($photo);
        }

        $this->entityManager->persist($amendment);
        $this->entityManager->flush();

        return new RedirectResponse($this->urls->generate('patrol_observation_show', [
            'uuid' => $area->getUuid(),
            'patrol' => $patrol->getUuid(),
            'observation' => $observation->getUuid(),
        ]));
    }

    /** The CSRF token id the observation page mints, scoped to the one record. */
    public static function csrfTokenId(Observation $observation): string
    {
        return 'observation_amend_'.$observation->getUuid()->toRfc4122();
    }

    /**
     * The value this correction supersedes, quoted on the page under "as it was
     * recorded".
     *
     * Taken from the SUBMITTED form rather than read out of the observation:
     * only the amender knows which part of a paragraph they are correcting, and
     * a server that guessed would quote the wrong sentence. Absent is ordinary —
     * an amendment that ADDS supersedes nothing.
     */
    private function supersededValue(Request $request): ?string
    {
        $raw = trim($request->request->getString('supersedes'));

        return '' === $raw ? null : mb_substr($raw, 0, self::BODY_MAX);
    }

    /**
     * The optional photograph, stored through the same evidence path the field
     * uploads use — one way in for every photograph this module holds, so the
     * private storage, the detected type and the preview are identical whether a
     * handset or a browser sent it.
     *
     * MARKED as an amendment attachment, which is what keeps it out of PL·05 and
     * out of §9's completeness count.
     */
    private function attachedPhoto(Observation $observation, Request $request): ?ObservationPhoto
    {
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            return null;
        }

        $clientUuid = \Symfony\Component\Uid\Uuid::v7();

        try {
            $stored = $this->evidence->store(
                $file,
                PhotoEvidenceKey::prefixFor($observation),
                $clientUuid->toRfc4122(),
            );
        } catch (EvidenceRejectedException $rejected) {
            throw new AccessDeniedException($rejected->getMessage(), $rejected);
        } catch (EvidenceStorageFailedException $failed) {
            throw new \RuntimeException('The photograph could not be stored.', previous: $failed);
        }

        return new ObservationPhoto($observation, $clientUuid, $stored->key)
            ->setMimeType($stored->mimeType)
            ->setByteSize($stored->byteSize)
            ->setThumbKey($stored->thumbKey)
            ->markAsAmendmentAttachment();
    }

    /**
     * Checked in code rather than with #[IsGranted] — see
     * {@see PatrolRecordController} for the full reasoning.
     */
    private function denyUnlessRecorder(): void
    {
        if (!$this->authorizationChecker->isGranted(PatrolRecordController::RECORD_PERMISSION)) {
            throw new AccessDeniedException('Amending an observation requires "'.PatrolRecordController::RECORD_PERMISSION.'".');
        }
    }

    private function denyUnlessCsrfValid(Observation $observation, Request $request): void
    {
        $submitted = $request->request->getString('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($observation), $submitted))) {
            throw new AccessDeniedException('Invalid CSRF token for the observation amendment.');
        }
    }

    /**
     * Who signs it. PL·09's "go in unsigned: never" — so this refuses rather
     * than filing an anonymous correction onto an evidence trail.
     */
    private function currentUser(): UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw new AccessDeniedException('An amendment is signed, so it needs a signed-in user.');
        }

        return $user;
    }
}
