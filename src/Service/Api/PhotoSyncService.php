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

namespace UhifadhiLabs\Patrol\Service\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Api\PatrolApiException;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;
use UhifadhiLabs\Patrol\Repository\ObservationPhotoRepository;
use UhifadhiLabs\Patrol\Service\PhotoEvidenceKey;
use UhifadhiLabs\Storage\Exception\EvidenceRejectedException;
use UhifadhiLabs\Storage\Exception\EvidenceStorageFailedException;
use UhifadhiLabs\Storage\Service\EvidenceStorage;

/**
 * `POST /api/observations/{uuid}/photos` — API-CONTRACT.md §8. One request per
 * photo, so an interruption costs one photograph rather than a patrol's evidence.
 *
 * The phone does not delete its copy until this call succeeds, which means it
 * WILL re-send: a re-uploaded clientUuid must return `duplicate: true` and must
 * not store a second copy. That is enforced here and, underneath, by the unique
 * index on the column. It is patrol's rule, about patrol's unique index rather
 * than about bytes, which is why it stays here and did not move to the storage
 * bundle with everything else.
 *
 * The BYTES are storage-module's business: validation (the same three checks, in
 * the same order, over the same five types), the extension derived from the
 * detected type, the private on-disk-or-object-storage write and the one ~400px
 * preview. Field photographs are evidence — a snare, a carcass, sometimes a
 * person — and the whole reason to hand them over is that "private, outside the
 * document root, reachable only through an authorising route" is a property one
 * mechanism should hold for every module rather than four modules re-deriving.
 */
final class PhotoSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObservationPhotoRepository $photos,
        private readonly EvidenceStorage $evidence,
    ) {
    }

    /**
     * @param string|null $position  where the shutter fired, as GeoJSON Point text (§8); null is NO FIX — never 0,0
     * @param float|null  $accuracyM how good that fix was, in metres; only ever passed alongside a position
     *
     * @return array{0: ObservationPhoto, 1: bool} [photo, wasAlreadyHeld]
     *
     * @throws PatrolApiException
     */
    public function store(
        Observation $observation,
        Uuid $clientUuid,
        UploadedFile $file,
        ?\DateTimeImmutable $takenAt,
        ?string $position = null,
        ?float $accuracyM = null,
    ): array {
        if (!$observation->getPatrol()->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $observation->getPatrol()->getClientUuid()?->toRfc4122());
        }

        $existing = $this->photos->findOneByClientUuid($clientUuid);
        if ($existing instanceof ObservationPhoto) {
            // Already here. The bytes are not written again and nothing changes
            // — INCLUDING the position. Idempotent means the second call changes
            // nothing at all, not "nothing except the fields that came with it";
            // a retry that rewrote where a photograph was taken would let a
            // dropped connection quietly move evidence.
            return [$existing, true];
        }

        try {
            $stored = $this->evidence->store(
                $file,
                PhotoEvidenceKey::prefixFor($observation),
                $clientUuid->toRfc4122(),
            );
        } catch (EvidenceRejectedException $exception) {
            // Not a photograph, too big, or an upload that did not arrive: all
            // things that will be just as wrong in two minutes, so the app is
            // told to stop trying and tell the ranger.
            throw PatrolApiException::invalidPayload($exception->getMessage(), ['clientUuid' => $clientUuid->toRfc4122(), ...$exception->details]);
        } catch (EvidenceStorageFailedException $exception) {
            // Storage failed, so nothing is recorded. The app is told this is
            // worth retrying — a full disk or a transient mount is not the
            // phone's fault, and the photo still exists on the handset.
            throw new PatrolApiException(500, 'photo_storage_failed', 'The photo could not be stored.', retryable: true, details: ['clientUuid' => $clientUuid->toRfc4122(), 'reason' => $exception->getMessage()]);
        }

        $photo = new ObservationPhoto($observation, $clientUuid, $stored->key)
            // The DETECTED type, which is a deliberate change from the claimed
            // one this module used to record: the column now holds the truth
            // about the bytes rather than a header the handset wrote.
            ->setMimeType($stored->mimeType)
            ->setByteSize($stored->byteSize)
            // Null where nothing on this machine could decode the source. Stored
            // as null rather than as a key pointing at a file that is not there.
            ->setThumbKey($stored->thumbKey)
            ->setTakenAt($takenAt)
            ->setPosition($position)
            ->setAccuracyM($accuracyM);

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return [$photo, false];
    }
}
