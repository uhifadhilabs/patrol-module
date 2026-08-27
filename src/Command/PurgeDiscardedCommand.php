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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Storage\Exception\InvalidEvidenceKeyException;
use UhifadhiLabs\Storage\Service\EvidenceStorage;

/**
 * Delete the discarded patrols whose retention window has elapsed — the row, its
 * fixes, its observations, its events and the photographs' actual bytes.
 *
 * A discarded patrol is kept on purpose (see {@see \UhifadhiLabs\Patrol\Enum\PatrolStatusEnum::Discarded}):
 * long enough that a mistake can be caught, not forever. `patrol.discard_retention_days`
 * is how long, measured from {@see Patrol::discardedAt()}, and a patrol HELD for
 * review is skipped for as long as the hold stands — that is the whole point of
 * the hold, and it has no expiry of its own.
 *
 * ## Why this writes no trail row
 *
 * Every other destructive thing in this platform leaves a record of itself. This
 * cannot, honestly, and pretending otherwise would be worse than the gap:
 *
 * * A trail row keyed to the patrol would be an orphan the moment the patrol is
 *   gone — a foreign key pointing at nothing, or a nullable one pointing at a
 *   number that will be reissued.
 * * A trail row NOT keyed to the patrol is a copy of what was deleted, which is
 *   the opposite of deleting it. "P-0142, 3 photographs, discarded as a test
 *   run" is most of the record we were asked to remove, kept somewhere new.
 * * And a retention sweep is not an event about one patrol at all. It is an
 *   operation with a count, run on a schedule, whose evidence belongs in the run
 *   that performed it.
 *
 * So the trail is the command's own OUTPUT and its final count, which a cron
 * captures with everything else it logs. What is deliberately kept is the
 * ability to see the sweep coming: `--dry-run` names every patrol that would go,
 * so a scheduled purge can be inspected before it is trusted.
 *
 * IDEMPOTENT: a second run finds the same query empty and says so. Bytes are
 * deleted before rows, because Flysystem's delete is idempotent while a lost
 * row is not — an interrupted run that already removed some files finishes
 * cleanly on the next pass, whereas dropping the rows first would leave
 * photographs on disk with nothing left to name them.
 *
 * Registered UNCONDITIONALLY, unlike the seeder and the thumbnail backfill: this
 * is not dev tooling. A deployment that never runs it never deletes anything,
 * which is the safe direction — but a production console must have it, because
 * production is the only place there is anything to purge.
 */
#[AsCommand(
    name: 'patrol:purge-discarded',
    description: 'Delete discarded patrols (and their photographs) past the retention window, unless held for review.',
)]
final class PurgeDiscardedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolRepository $patrols,
        private readonly EvidenceStorage $evidence,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List what would be deleted and remove nothing — neither bytes nor rows.',
        );
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Override the retention window for this run (default: patrol.discard_retention_days).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $days = $this->retentionDays;
        $override = $input->getOption('days');
        if (null !== $override) {
            if (!is_numeric($override) || (int) $override < 0) {
                $io->error('--days must be a whole number of days, zero or more.');

                return Command::INVALID;
            }
            $days = (int) $override;
        }

        $cutoff = new \DateTimeImmutable()->modify(\sprintf('-%d days', $days));

        $due = [];
        $undatable = 0;
        foreach ($this->patrols->findDiscardedNotHeld() as $patrol) {
            $discardedAt = $patrol->discardedAt();
            if (null === $discardedAt) {
                // Neither an event, nor an end, nor even a creation stamp. Left
                // alone and counted: a row this module cannot date is a row it
                // cannot honestly say is old enough to destroy.
                ++$undatable;

                continue;
            }
            if ($discardedAt > $cutoff) {
                continue;
            }
            $due[] = $patrol;
        }

        if ([] === $due) {
            $io->success(\sprintf('No discarded patrol is older than %d day%s.', $days, 1 === $days ? '' : 's'));
            $this->reportSkipped($io, $undatable);

            return Command::SUCCESS;
        }

        $io->title(\sprintf(
            '%d discarded patrol%s past %d day%s',
            \count($due),
            1 === \count($due) ? '' : 's',
            $days,
            1 === $days ? '' : 's',
        ));

        $purged = 0;
        $photos = 0;
        $unremovable = 0;

        foreach ($due as $patrol) {
            $keys = self::evidenceKeysOf($patrol);

            $io->writeln(\sprintf(
                '  <info>%s</info> %s · discarded %s · %d photo%s',
                $patrol->getRef(),
                $patrol->getDisplayName() ?? $patrol->getType(),
                $patrol->discardedAt()?->format('Y-m-d') ?? '—',
                \count($keys),
                1 === \count($keys) ? '' : 's',
            ));

            if ($dryRun) {
                ++$purged;
                $photos += \count($keys);

                continue;
            }

            foreach ($keys as $key) {
                try {
                    // Deletes the preview beside the original, always — see
                    // EvidenceStorage::delete(). Removing evidence and leaving a
                    // readable thumbnail of it behind is the worst kind of
                    // half-done.
                    $this->evidence->delete($key);
                    ++$photos;
                } catch (InvalidEvidenceKeyException $exception) {
                    /*
                     * A key this storage cannot even parse — a row written by
                     * something older or something broken. THIS patrol is
                     * skipped and its rows KEPT: deleting the only record of
                     * where a file lives, while the file may still be there,
                     * would make it unreachable and undeletable at once. The
                     * sweep carries on, because one bad row says nothing about
                     * the next.
                     *
                     * A STORE-level failure (unreachable disk, refused bucket)
                     * is deliberately NOT caught. It is not about this patrol,
                     * it will be just as true for the next one, and it escapes
                     * before any flush() — so the run aborts having removed no
                     * rows at all, and the next run starts from exactly where
                     * this one did. That is what makes an interrupted purge
                     * safe rather than half-applied.
                     */
                    ++$unremovable;
                    $io->writeln(\sprintf('    <error>kept</error> %s (%s)', $key, $exception->getMessage()));

                    continue 2;
                }
            }

            // The row, and with it — by cascade / orphanRemoval — the track
            // batches, the fixes, the observations and their photo rows, the
            // launch points, the flights and the events.
            $this->entityManager->remove($patrol);
            ++$purged;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->definitionList(
            ['patrols' => (string) $purged],
            ['photographs' => (string) $photos],
            ['photographs that could not be removed' => (string) $unremovable],
        );
        $this->reportSkipped($io, $undatable);

        if ($dryRun) {
            $io->note('Dry run — nothing was deleted.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            '%d discarded patrol%s purged, %d photograph%s removed.',
            $purged,
            1 === $purged ? '' : 's',
            $photos,
            1 === $photos ? '' : 's',
        ));

        return Command::SUCCESS;
    }

    private function reportSkipped(SymfonyStyle $io, int $undatable): void
    {
        if ($undatable > 0) {
            $io->warning(\sprintf(
                '%d discarded patrol%s carries no date to measure from and %s left in place.',
                $undatable,
                1 === $undatable ? '' : 's',
                1 === $undatable ? 'was' : 'were',
            ));
        }
    }

    /**
     * Every evidence key this patrol's observations hold.
     *
     * Read BEFORE the row is removed, obviously, but also read into a plain list
     * rather than walked during the delete: the collections are about to be
     * detached, and a key that cannot be produced afterwards is a photograph
     * nothing will ever ask about again.
     *
     * @return list<string>
     */
    private static function evidenceKeysOf(Patrol $patrol): array
    {
        $keys = [];
        foreach ($patrol->getObservations() as $observation) {
            foreach ($observation->getPhotos() as $photo) {
                $keys[] = $photo->getStoragePath();
            }
        }

        return $keys;
    }
}
