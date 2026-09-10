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
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Uhifadhi\Patrol\Model\ThumbnailBackfillReport;
use Uhifadhi\Patrol\Repository\ObservationPhotoRepository;
use Uhifadhi\Storage\Service\EvidenceKey;
use Uhifadhi\Storage\Thumbnail\ThumbnailGenerator;

/**
 * Gives the photographs that arrived BEFORE this module adopted the evidence
 * storage the preview every photograph has had since.
 *
 * New uploads get one at store() time. The ones already on disk do not, and a
 * gallery that streams five full-size field photographs to draw five 180px
 * tiles is a gallery nobody on a field connection will wait for. This walks the
 * rows with no preview and makes one.
 *
 * IDEMPOTENT, in the way that matters for something somebody will run twice:
 * only rows with a null thumbKey are considered, a preview already sitting
 * beside its original is adopted rather than regenerated, and a photograph
 * whose bytes are missing or undecodable is reported and skipped rather than
 * failing the run. A second run therefore does nothing and says so.
 *
 * A NULL thumbKey stays null when nothing on this machine can decode the source
 * — HEIC without Imagick+libheif is the ordinary case — and that is not
 * failure: it is the same honest answer the upload path records, and the page
 * falls back to the original.
 *
 * IT TAKES THE FLYSYSTEM STORAGE AND THE ENGINE directly rather than the
 * evidence API, because what it does — write one derived object beside a key
 * that already exists — is the one thing that API deliberately does not expose:
 * store() validates and names a NEW upload, and this is neither.
 */
final readonly class PhotoThumbnailBackfillService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ObservationPhotoRepository $photos,
        private FilesystemOperator $evidence,
        private ThumbnailGenerator $thumbnails,
    ) {
    }

    /**
     * @param bool $dryRun report what would be generated and write nothing —
     *                     neither bytes nor rows
     */
    public function backfill(bool $dryRun = false): ThumbnailBackfillReport
    {
        $rows = [];

        foreach ($this->photos->findWithoutThumbKey() as $photo) {
            $key = $photo->getStoragePath();
            $thumbKey = EvidenceKey::thumb($key);

            if (!$this->exists($key)) {
                // The row outlived its bytes. Said out loud rather than fixed:
                // deciding what a photograph with no file means is not a
                // thumbnail sweep's call.
                $rows[] = ['outcome' => ThumbnailBackfillReport::UNAVAILABLE, 'key' => $key, 'detail' => 'the bytes are gone'];

                continue;
            }

            // A preview from an interrupted earlier run: adopt it rather than
            // decode the original again. This is what makes a second run cheap.
            if ($this->exists($thumbKey)) {
                if (!$dryRun) {
                    $photo->setThumbKey($thumbKey);
                }
                $rows[] = ['outcome' => ThumbnailBackfillReport::ADOPTED, 'key' => $thumbKey, 'detail' => null];

                continue;
            }

            $bytes = $this->thumbnailFor($key);
            if (null === $bytes) {
                $rows[] = ['outcome' => ThumbnailBackfillReport::NO_ENGINE, 'key' => $key, 'detail' => null];

                continue;
            }

            if (!$dryRun) {
                try {
                    $this->evidence->write($thumbKey, $bytes);
                } catch (FilesystemException $exception) {
                    $rows[] = ['outcome' => ThumbnailBackfillReport::UNAVAILABLE, 'key' => $thumbKey, 'detail' => $exception->getMessage()];

                    continue;
                }

                $photo->setThumbKey($thumbKey);
            }

            $rows[] = ['outcome' => ThumbnailBackfillReport::GENERATED, 'key' => $thumbKey, 'detail' => null];
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new ThumbnailBackfillReport($rows, $dryRun);
    }

    /**
     * The original's bytes, through a temporary local copy.
     *
     * The thumbnail engines take a PATH, because both underlying libraries read
     * files; the evidence storage may be an object store on another continent.
     * So the original is streamed down, decoded and thrown away — the one place
     * in this module that needs a photograph to be briefly local.
     */
    private function thumbnailFor(string $key): ?string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'patrol-thumb');
        if (false === $temporary) {
            return null;
        }

        try {
            $source = $this->evidence->readStream($key);
            $target = @fopen($temporary, 'w');
            if (false === $target) {
                return null;
            }

            stream_copy_to_stream($source, $target);
            fclose($target);
            if (\is_resource($source)) {
                fclose($source);
            }

            return $this->thumbnails->generate($temporary, $this->mimeType($key));
        } catch (FilesystemException) {
            return null;
        } finally {
            @unlink($temporary);
        }
    }

    private function mimeType(string $key): string
    {
        try {
            return $this->evidence->mimeType($key);
        } catch (FilesystemException) {
            // No engine claims octet-stream, so this becomes an honest "no
            // preview" rather than a guess at the format.
            return 'application/octet-stream';
        }
    }

    private function exists(string $key): bool
    {
        try {
            return $this->evidence->fileExists($key);
        } catch (FilesystemException) {
            return false;
        }
    }
}
