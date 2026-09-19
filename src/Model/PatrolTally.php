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
 * ONE WINDOW'S THREE COUNTED FIGURES over one set of ground — the patrols, the
 * kilometres and the observations that fall inside it.
 *
 * A tiny value object rather than an array because it travels between the
 * figure service and every surface that reads it, and a mistyped key would be a
 * silent wrong number.
 */
final readonly class PatrolTally
{
    public function __construct(
        public int $patrols,
        public float $distanceKm,
        public int $observations,
    ) {
    }
}
