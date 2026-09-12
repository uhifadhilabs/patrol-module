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
 * WHERE AN OBSERVATION OF THIS TYPE OF PATROL IS PUT.
 *
 * AT_POSITION the recorder's own fix is the observation's position, which is
 *             what somebody on the ground means by "here".
 * ON_MAP      the recorder marks the place on the map, because their own
 *             position is not where the thing they saw is.
 *
 * A TUNABLE RATHER THAN A CONSEQUENCE OF THE BASE. Each base prefills one of
 * them ({@see \Uhifadhi\Patrol\Model\PatrolBaseDefaults}), and an area that flies
 * low enough to file at the aircraft's position, or walks a transect it marks off
 * to the side, may say so per type.
 */
enum ObservationPlacementEnum: string
{
    case AtPosition = 'at_position';
    case OnMap = 'on_map';

    /** What the section's two-button picker says. */
    public function label(): string
    {
        return match ($this) {
            self::AtPosition => 'at position',
            self::OnMap => 'marked on the map',
        };
    }

    /** The same choice inside the sentence the section prints under a row. */
    public function sentence(): string
    {
        return match ($this) {
            self::AtPosition => 'at the ranger’s position',
            self::OnMap => 'marked on the map',
        };
    }
}
