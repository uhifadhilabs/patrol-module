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
 * A NAME OR WIRE-CODE THAT WOULD COLLIDE within its scope — a kind label already
 * used in this area, a sub label already used under this kind, or a wire-code
 * already held by another row in this area. The admin refuses the write rather
 * than quietly producing two rows a saved filter could never tell apart.
 *
 * Carries the field it is ABOUT ({@see $field}) so the controller can put the
 * message back beside the input it belongs to.
 */
final class TaxonomyConflictException extends \RuntimeException
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

    public static function code(string $message): self
    {
        return new self('code', $message);
    }
}
