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
 * A patrol with neither a track to read a start out of nor a start somebody
 * typed.
 *
 * The entry flow cannot settle this at the form, and that is the point: a start
 * is REQUIRED of a patrol nobody tracked and SUPPLIED by the file of one
 * somebody did, so which of the two applies is only known once the service has
 * looked at what the draft actually holds. The screen words the refusal; the
 * record's own rule lives with the write.
 */
final class MissingPatrolStartException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A patrol needs either a track or the time it started.');
    }
}
