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

namespace Uhifadhi\Patrol\Exception;

/**
 * A PATROL-TYPE OR STATION NAME THAT WOULD COLLIDE within its area, or a blank
 * one. The Settings section refuses the write rather than producing two rows a
 * saved filter, a chart and an export could never tell apart.
 *
 * Its own class rather than {@see TaxonomyConflictException}, which is the
 * OBSERVATION vocabulary's: the two admins are separate screens with separate
 * rules, and one exception carrying both would make a catch block in either
 * place a claim about the other.
 */
final class VocabularyConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function label(string $message): self
    {
        return new self('label', $message);
    }
}
