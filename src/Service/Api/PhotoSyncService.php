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
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Api\PatrolApiException;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;
use UhifadhiLabs\Patrol\Repository\ObservationPhotoRepository;

/**
 * `POST /api/observations/{uuid}/photos` — API-CONTRACT.md §8. One request per
 * photo, so an interruption costs one photograph rather than a patrol's evidence.
 *
 * The phone does not delete its copy until this call succeeds, which means it
 * WILL re-send: a re-uploaded clientUuid must return `duplicate: true` and must
 * not store a second copy. That is enforced here and, underneath, by the unique
 * index on the column.
 *
 * Bytes are written outside the document root. Field photographs are evidence —
 * a snare, a carcass, sometimes a person — and must not be retrievable by
 * guessing a URL.
 */
final class PhotoSyncService
{
    /** What a camera may send. Anything else is not a photograph. */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObservationPhotoRepository $photos,
        private readonly string $photoDir,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @return array{0: ObservationPhoto, 1: bool} [photo, wasAlreadyHeld]
     *
     * @throws PatrolApiException
     */
    public function store(Observation $observation, Uuid $clientUuid, UploadedFile $file, ?\DateTimeImmutable $takenAt): array
    {
        if (!$observation->getPatrol()->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $observation->getPatrol()->getClientUuid()?->toRfc4122());
        }

        $existing = $this->photos->findOneByClientUuid($clientUuid);
        if ($existing instanceof ObservationPhoto) {
            // Already here. The bytes are not written again and nothing changes.
            return [$existing, true];
        }

        $this->guardFile($file, $clientUuid);

        $directory = $this->directoryFor($observation);
        $filename = $clientUuid->toRfc4122().'.'.$this->extensionFor($file);
        $relativePath = $this->relativeDirectoryFor($observation).'/'.$filename;

        try {
            $file->move($directory, $filename);
        } catch (FileException $exception) {
            // Storage failed, so nothing is recorded. The app is told this is
            // worth retrying — a full disk or a transient mount is not the
            // phone's fault, and the photo still exists on the handset.
            throw new PatrolApiException(500, 'photo_storage_failed', 'The photo could not be stored.', retryable: true, details: ['clientUuid' => $clientUuid->toRfc4122(), 'reason' => $exception->getMessage()]);
        }

        $photo = new ObservationPhoto($observation, $clientUuid, $relativePath)
            ->setMimeType($file->getClientMimeType())
            ->setByteSize(filesize($directory.'/'.$filename) ?: null)
            ->setTakenAt($takenAt);

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return [$photo, false];
    }

    /** @throws PatrolApiException */
    private function guardFile(UploadedFile $file, Uuid $clientUuid): void
    {
        if (!$file->isValid()) {
            throw PatrolApiException::invalidPayload('That upload did not arrive intact.', ['clientUuid' => $clientUuid->toRfc4122(), 'reason' => $file->getErrorMessage()]);
        }

        $size = $file->getSize();
        if (false !== $size && $size > $this->maxBytes) {
            throw PatrolApiException::invalidPayload('That photo is larger than this deployment accepts.', ['clientUuid' => $clientUuid->toRfc4122(), 'byteSize' => $size, 'maxBytes' => $this->maxBytes]);
        }

        $mimeType = $file->getMimeType();
        if (null !== $mimeType && !\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw PatrolApiException::invalidPayload('That file is not a photograph.', ['clientUuid' => $clientUuid->toRfc4122(), 'mimeType' => $mimeType]);
        }
    }

    /**
     * Photos are filed under their patrol, so a patrol's evidence can be found,
     * archived or handed over as one thing rather than hunted for across a flat
     * directory of a hundred thousand files.
     */
    private function relativeDirectoryFor(Observation $observation): string
    {
        $patrol = $observation->getPatrol();

        return 'patrol-'.($patrol->getClientUuid()?->toRfc4122() ?? $patrol->getUuid()->toRfc4122());
    }

    /** @throws PatrolApiException */
    private function directoryFor(Observation $observation): string
    {
        $directory = rtrim($this->photoDir, '/').'/'.$this->relativeDirectoryFor($observation);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new PatrolApiException(500, 'photo_storage_failed', 'The photo store is not writable.', retryable: true);
        }

        return $directory;
    }

    /**
     * Derived from the DETECTED type, never from the client's filename: a
     * filename is attacker-controlled text, and letting it choose the extension
     * is how an upload directory ends up holding a ".php".
     */
    private function extensionFor(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
