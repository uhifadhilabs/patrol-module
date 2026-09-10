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

namespace Uhifadhi\Patrol\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Devkit\CommandIo;

/**
 * The streams a descriptor's handler speaks through, kept in memory so a test
 * can read what was said.
 *
 * devkit wires the real one to a console; a module's own suite does not have a
 * console and does not need one — what it is asking is what the handler did and
 * what it reported, which is the whole of this contract.
 */
final class RecordingCommandIo implements CommandIo
{
    /** @var list<string> */
    public array $written = [];

    /** @var list<string> */
    public array $errors = [];

    /** @param list<string> $answers the lines a person would have typed */
    public function __construct(private array $answers = [])
    {
    }

    public function write(string $line): void
    {
        $this->written[] = $line;
    }

    public function error(string $line): void
    {
        $this->errors[] = $line;
    }

    public function readLine(): ?string
    {
        return array_shift($this->answers);
    }

    public function readSecret(): ?string
    {
        return $this->readLine();
    }

    public function output(): string
    {
        return implode("\n", $this->written);
    }
}
