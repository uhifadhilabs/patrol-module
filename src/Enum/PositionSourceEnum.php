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

namespace UhifadhiLabs\Patrol\Enum;

/**
 * Where an observation's position actually came from — API-CONTRACT.md §6.
 *
 * The whole point of storing this is honesty about coverage. A drone
 * observation is marked at the spot the OPERATOR believes the drone was; it is
 * not a GPS fix of anything, and presenting it as one would quietly turn a
 * judgement call into evidence. The module must show the difference wherever it
 * shows the position, which is why this is a persisted column and not something
 * inferred from the patrol type at render time.
 */
enum PositionSourceEnum: string
{
    /** A satellite fix from the phone, with accuracy and satellite count. */
    case Gps = 'gps';

    /** A point the operator tapped on the map. Not measured — asserted. */
    case OperatorMarked = 'operator_marked';

    public function label(): string
    {
        return match ($this) {
            self::Gps => 'GPS fix',
            self::OperatorMarked => 'Operator-marked',
        };
    }

    /** Whether this position was measured by an instrument. */
    public function isMeasured(): bool
    {
        return self::Gps === $this;
    }
}
