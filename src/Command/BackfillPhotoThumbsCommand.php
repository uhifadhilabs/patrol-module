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

namespace UhifadhiLabs\Patrol\Command;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;
use UhifadhiLabs\Patrol\Repository\ObservationPhotoRepository;
use UhifadhiLabs\Storage\Service\EvidenceKey;
use UhifadhiLabs\Storage\Thumbnail\ThumbnailGenerator;

/**
 * Give the photographs that arrived BEFORE storage-module the preview every
 * photograph has had since.
 *
 * New uploads get one at store() time. The ones already on disk do not, and a
 * gallery that streams five full-size field photographs to draw five 180px tiles
 * is a gallery nobody on a field connection will wait for. This walks the rows
 * with no preview and makes one.
 *
 * IDEMPOTENT, in the way that matters for a command someone will run twice: only
 * rows with a null thumbKey are considered, a preview already sitting beside its
 * original is adopted rather than regenerated, and a photograph whose bytes are
 * missing or undecodable is reported and skipped rather than failing the run. A
 * second run therefore does nothing and says so.
 *
 * A NULL thumbKey stays null when nothing on this machine can decode the source
 * — HEIC without Imagick+libheif is the ordinary case — and that is not failure:
 * it is the same honest answer the upload path records, and the page falls back
 * to the original.
 *
 * Dev tooling by registration (patrol.dev_tools), like the demo seeder: it is a
 * one-off migration aid, not an operation a production console needs standing by.
 */
#[AsCommand(
    name: 'patrol:photos:backfill-thumbs',
    description: 'Generate the missing ~400px previews for observation photos stored before storage-module (idempotent).',
)]
final class BackfillPhotoThumbsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObservationPhotoRepository $photos,
        private readonly FilesystemOperator $evidence,
        private readonly ThumbnailGenerator $thumbnails,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would be generated and write nothing — neither bytes nor rows.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $pending = $this->photos->findWithoutThumbKey();
        if ([] === $pending) {
            $io->success('Every observation photo already has a preview.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('%d photo%s without a preview', \count($pending), 1 === \count($pending) ? '' : 's'));

        $generated = 0;
        $adopted = 0;
        $undecodable = 0;
        $missing = 0;

        foreach ($pending as $photo) {
            $key = $photo->getStoragePath();
            $thumbKey = EvidenceKey::thumb($key);

            if (!$this->exists($key)) {
                // The row outlived its bytes. Said out loud rather than fixed:
                // deciding what a photograph with no file means is not a
                // thumbnail command's call.
                ++$missing;
                $io->writeln(\sprintf('  <comment>missing</comment>  %s', $key));

                continue;
            }

            // A preview from an interrupted earlier run: adopt it rather than
            // decode the original again. This is what makes a second run cheap.
            if ($this->exists($thumbKey)) {
                ++$adopted;
                $this->record($photo, $thumbKey, $dryRun);
                $io->writeln(\sprintf('  <info>adopted</info>  %s', $thumbKey));

                continue;
            }

            $bytes = $this->thumbnailFor($key);
            if (null === $bytes) {
                ++$undecodable;
                $io->writeln(\sprintf('  <comment>no engine</comment> %s', $key));

                continue;
            }

            if (!$dryRun) {
                try {
                    $this->evidence->write($thumbKey, $bytes);
                } catch (FilesystemException $exception) {
                    ++$missing;
                    $io->writeln(\sprintf('  <error>unwritable</error> %s (%s)', $thumbKey, $exception->getMessage()));

                    continue;
                }
            }

            ++$generated;
            $this->record($photo, $thumbKey, $dryRun);
            $io->writeln(\sprintf('  <info>generated</info> %s', $thumbKey));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->definitionList(
            ['generated' => (string) $generated],
            ['adopted' => (string) $adopted],
            ['no engine (left without a preview)' => (string) $undecodable],
            ['bytes missing or unwritable' => (string) $missing],
        );

        if ($dryRun) {
            $io->note('Dry run — nothing was written.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d preview%s written.', $generated, 1 === $generated ? '' : 's'));

        return Command::SUCCESS;
    }

    private function record(ObservationPhoto $photo, string $thumbKey, bool $dryRun): void
    {
        if (!$dryRun) {
            $photo->setThumbKey($thumbKey);
        }
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
