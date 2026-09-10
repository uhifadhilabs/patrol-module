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

namespace Uhifadhi\Patrol\Devkit;

use Uhifadhi\Contracts\Devkit\CommandDescriptor;
use Uhifadhi\Contracts\Devkit\CommandIo;
use Uhifadhi\Contracts\Devkit\CommandProviderInterface;
use Uhifadhi\Patrol\Model\ThumbnailBackfillReport;
use Uhifadhi\Patrol\Service\PhotoThumbnailBackfillService;

/**
 * THE PREVIEW BACKFILL — a one-off migration aid for the photographs that
 * arrived before this module adopted the evidence storage, and the reason it is
 * a descriptor rather than a console command.
 *
 * The rule the fleet settles on is which console a thing belongs to. A sweep a
 * deployment SCHEDULES is production's and stays a command; a migration aid
 * somebody runs once, on the release that needs it, belongs to the dev console
 * — and the dev console is devkit's, installed through `require-dev`, which is
 * what keeps it out of a production build without anything having to remember.
 *
 * IT IS INERT HERE. In production this is an ordinary tagged service nobody
 * asks anything of, because the thing that would ask is not installed; in a dev
 * install devkit collects it and turns the descriptor below into a real console
 * command.
 *
 * NOTHING HERE NEEDS symfony/console. A name, a help line, and a closure taking
 * the argument tail and the streams to speak through, returning an exit code —
 * the process contract rather than the console one.
 *
 * THE HANDLER PARSES ITS OWN TAIL, because the contract deliberately models no
 * options: doing so would mean reimplementing an input definition in a package
 * whose whole claim is that depending on it costs nothing.
 *
 *     patrol:photos:backfill-thumbs [--dry-run]
 *
 * @see CommandProviderInterface
 */
final readonly class PatrolCommandProvider implements CommandProviderInterface
{
    public function __construct(
        private PhotoThumbnailBackfillService $backfill,
    ) {
    }

    public function commands(): array
    {
        return [
            new CommandDescriptor(
                'patrol:photos:backfill-thumbs',
                'Generate the missing previews for observation photos stored before the evidence storage (idempotent). Pass --dry-run to write nothing.',
                fn (array $arguments, CommandIo $io): int => $this->backfillThumbs($arguments, $io),
            ),
        ];
    }

    /**
     * @param list<string> $arguments
     */
    private function backfillThumbs(array $arguments, CommandIo $io): int
    {
        $unknown = array_values(array_filter($arguments, static fn (string $a): bool => '--dry-run' !== $a));
        if ([] !== $unknown) {
            $io->error(\sprintf('Unknown argument "%s". Usage: patrol:photos:backfill-thumbs [--dry-run]', $unknown[0]));

            return 1;
        }

        $report = $this->backfill->backfill(\in_array('--dry-run', $arguments, true));

        if (0 === $report->considered()) {
            $io->write('Every observation photo already has a preview.');

            return 0;
        }

        foreach ($report->rows as $row) {
            $io->write(\sprintf(
                '  %-12s %s%s',
                $row['outcome'],
                $row['key'],
                null !== $row['detail'] ? ' ('.$row['detail'].')' : '',
            ));
        }

        foreach ([
            ThumbnailBackfillReport::GENERATED => 'generated',
            ThumbnailBackfillReport::ADOPTED => 'adopted',
            ThumbnailBackfillReport::NO_ENGINE => 'no engine (left without a preview)',
            ThumbnailBackfillReport::UNAVAILABLE => 'bytes missing or unwritable',
        ] as $outcome => $label) {
            $io->write(\sprintf('%s: %d', $label, $report->countOf($outcome)));
        }

        if ($report->dryRun) {
            $io->write('Dry run — nothing was written.');
        }

        return 0;
    }
}
