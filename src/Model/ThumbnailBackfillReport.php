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

namespace Uhifadhi\Patrol\Model;

/**
 * What a preview backfill did, said in numbers and one line per photograph.
 *
 * It exists so the work and the reporting are separable: the service decides
 * what happened to each photograph, and whoever asked decides how to say it —
 * a console line, a log entry, an assertion in a test.
 */
final readonly class ThumbnailBackfillReport
{
    public const string GENERATED = 'generated';
    public const string ADOPTED = 'adopted';
    public const string NO_ENGINE = 'no engine';
    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param list<array{outcome: string, key: string, detail: string|null}> $rows one per photograph considered, in the order they were considered
     */
    public function __construct(
        public array $rows,
        public bool $dryRun,
    ) {
    }

    public function countOf(string $outcome): int
    {
        return \count(array_filter($this->rows, static fn (array $row): bool => $outcome === $row['outcome']));
    }

    public function considered(): int
    {
        return \count($this->rows);
    }
}
