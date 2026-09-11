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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\PatrolDraft;
use Uhifadhi\Patrol\Entity\PatrolDraftFile;
use Uhifadhi\Patrol\Repository\PatrolDraftFileRepository;
use Uhifadhi\Patrol\Repository\PatrolDraftRepository;
use Uhifadhi\Storage\Model\StoredFile;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * THE LIFE OF A PATROL BEING WRITTEN — opened, fed files, emptied on save,
 * swept when nobody comes back.
 *
 * Everything about a draft that is not a column lives here, so the two upload
 * targets, the entry screen and the retention sweep all ask the same object the
 * same questions.
 *
 * ## Re-homing is a copy and then a delete, and it has to be
 *
 * A file reaches the storage before its patrol exists, so it lands under the
 * draft's prefix — `patrol-track/<draft>/…` or `observation/<draft>-1/…`. On
 * save it has to end up under the patrol's own prefix
 * ({@see PhotoEvidenceKey::prefixFor()}), because that is the prefix the voter
 * claims, the Files hub lists on, and the purge walks.
 *
 * The storage publishes no rename: {@see EvidenceStorage} stores, streams and
 * deletes, and a move is not one of the three. So each file is READ BACK,
 * STORED AGAIN under the new prefix, and only then deleted from the old one —
 * ONE FILE PER TRANSACTION, deliberately. An interrupted save then leaves a
 * duplicate of at most one photograph under a draft the sweep will collect,
 * which is recoverable; the alternative orders lose bytes or leave a row
 * pointing at a key that is not there.
 *
 * The copy goes through a temporary file rather than memory: a patrol's evidence
 * is field photography, and a save of a dozen of them must not be bounded by
 * whatever `memory_limit` an installation happens to run.
 */
final readonly class PatrolDraftService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatrolDraftRepository $drafts,
        private PatrolDraftFileRepository $draftFiles,
        private EvidenceStorage $storage,
    ) {
    }

    /** A new draft for this area and this person, persisted so a file can name it. */
    public function open(AreaOfInterest $area, ?UserInterface $owner): PatrolDraft
    {
        $draft = new PatrolDraft($area, $owner);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    /**
     * The draft an id names, or a fresh one for this area when it names nothing.
     *
     * A form that comes back with a draft that has been swept (or was never
     * real) is not an error a person can act on — they would be told their page
     * is stale and asked to start again, having lost nothing but typing. It
     * opens a new one instead, and the files that were on the old one are the
     * sweep's.
     */
    public function reopen(?string $uuid, AreaOfInterest $area, ?UserInterface $owner): PatrolDraft
    {
        $draft = null !== $uuid ? $this->drafts->findOneByUuid($uuid) : null;

        if ($draft instanceof PatrolDraft && $draft->getArea()->getId() === $area->getId()) {
            return $draft;
        }

        return $this->open($area, $owner);
    }

    public function findByUuid(string $uuid): ?PatrolDraft
    {
        return $this->drafts->findOneByUuid($uuid);
    }

    public function findFileByKey(string $key): ?PatrolDraftFile
    {
        return $this->draftFiles->findOneByStorageKey($key);
    }

    /**
     * Write the row for a file the storage has just kept.
     *
     * `$only` is the track's rule: a patrol has one track, so a second file in
     * that slot replaces the first and the first's bytes go with it.
     */
    public function receive(
        PatrolDraft $draft,
        string $slot,
        StoredFile $file,
        string $label,
        string $outcome,
        bool $only = false,
    ): PatrolDraftFile {
        if ($only) {
            foreach ($this->draftFiles->findByDraftAndSlot($draft, $slot) as $previous) {
                $this->forget($previous);
            }
        }

        $row = new PatrolDraftFile($draft, $slot, $file->key, $label, $outcome)
            ->setThumbKey($file->thumbKey)
            ->setMimeType($file->mimeType)
            ->setByteSize($file->byteSize);

        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }

    /**
     * Drop the row for a file the storage is about to delete.
     *
     * The bytes are the storage's to remove — the contract calls this BEFORE it
     * touches them — so nothing here deletes anything but the row.
     */
    public function released(string $key): void
    {
        $row = $this->draftFiles->findOneByStorageKey($key);
        if (!$row instanceof PatrolDraftFile) {
            return;
        }

        $row->getDraft()->removeFile($row);
        $this->entityManager->remove($row);
        $this->entityManager->flush();
    }

    /** The bytes a draft file holds, read back out of the storage. */
    public function bytesOf(PatrolDraftFile $file): string
    {
        return $this->bytesAt($file->getStorageKey());
    }

    /**
     * The bytes a key names.
     *
     * Addressed by key rather than by row because the first read happens INSIDE
     * `received()` — the call that writes the row — when the module is told the
     * bytes are stored and has one chance to take what it needs from them.
     */
    public function bytesAt(string $key): string
    {
        $stream = $this->storage->stream($key);
        try {
            return (string) stream_get_contents($stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * The file, under a new prefix, and gone from the old one.
     *
     * Copy → store → delete, in that order and one file at a time; see this
     * class's own note on why the storage cannot simply rename it.
     */
    public function rehome(PatrolDraftFile $file, string $prefix): StoredFile
    {
        $temporary = tempnam(sys_get_temp_dir(), 'patrol-rehome');
        if (false === $temporary) {
            throw new \RuntimeException('A patrol’s evidence could not be staged for re-homing.');
        }

        try {
            file_put_contents($temporary, $this->bytesOf($file));
            $stored = $this->storage->store(
                new \SplFileInfo($temporary),
                $prefix,
                Uuid::v7()->toRfc4122(),
            );
        } finally {
            @unlink($temporary);
        }

        // Only now: until the new copy exists, the old one is the only one.
        $this->forget($file);
        $this->entityManager->flush();

        return $stored;
    }

    /** Delete a draft, its rows and every byte still under it. */
    public function discard(PatrolDraft $draft): int
    {
        $removed = 0;
        foreach ($draft->getFiles()->toArray() as $file) {
            $this->forget($file);
            ++$removed;
        }

        $this->entityManager->remove($draft);
        $this->entityManager->flush();

        return $removed;
    }

    /**
     * Every draft opened before the cutoff, gone.
     *
     * @return array{drafts: int, files: int}
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff, bool $dryRun = false): array
    {
        $drafts = 0;
        $files = 0;

        foreach ($this->drafts->findByCreatedBefore($cutoff) as $draft) {
            ++$drafts;
            if ($dryRun) {
                $files += $draft->getFiles()->count();

                continue;
            }
            $files += $this->discard($draft);
        }

        return ['drafts' => $drafts, 'files' => $files];
    }

    /**
     * The row and its bytes, without the flush.
     *
     * A storage failure is swallowed for the reason the storage swallows its
     * own: every caller here is already removing the row, and a cleanup failure
     * reported as the outcome would replace an accurate message with a
     * misleading one. The leftover is a key nothing names, which the deployment
     * sees in its own storage report rather than as a broken save.
     */
    private function forget(PatrolDraftFile $file): void
    {
        try {
            $this->storage->delete($file->getStorageKey());
        } catch (\Throwable) {
        }

        $file->getDraft()->removeFile($file);
        $this->entityManager->remove($file);
    }
}
