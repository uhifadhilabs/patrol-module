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

namespace Uhifadhi\Patrol\Enum;

/**
 * WHAT A PATROL TYPE RECORDS — the one thing about a type that is a fixed key
 * rather than a word somebody chose.
 *
 * A type's NAME is unlimited: foot, bicycle, motorbike, vehicle, horse, drone,
 * night sweep. Its BASE is one of two, and it is what the handset resolves its
 * screen from:
 *
 *   SURFACE the person's own position is the track, whether they walk, ride or
 *           drive, and an observation is filed where they stand.
 *   AERIAL  a flight log, where the operator's position is not the coverage, and
 *           an observation is marked on the map instead.
 *
 * TWO, AND A NEW VEHICLE IS NEVER A THIRD. Adding a name with the surface base
 * is how a motorbike patrol arrives; a third value would be a third screen to
 * build on every handset for something that records exactly what surface does.
 *
 * NULLABLE ON THE TYPE, deliberately: a type carried over from before bases
 * existed has none, and the section draws that as a row ASKING for one rather
 * than as an error. Nothing is blocked while it has none.
 */
enum PatrolBaseEnum: string
{
    case Surface = 'surface';
    case Aerial = 'aerial';

    /** The word the section prints on the badge; the sheet does the casing. */
    public function label(): string
    {
        return $this->value;
    }

    /** What each base means, in the sentence the section prints under the rows. */
    public function records(): string
    {
        return match ($this) {
            self::Surface => 'the ranger’s own position is the track, whether they walk, ride or drive',
            self::Aerial => 'a flight log, where the operator’s position is not the coverage',
        };
    }
}
