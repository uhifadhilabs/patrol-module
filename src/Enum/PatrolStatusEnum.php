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
 * How finished a patrol is, from the module's point of view.
 *
 * This is not decoration: a patrol that is still `Recording` has arrived in
 * pieces and the module does not yet hold everything needed to draw it, so it
 * must NOT appear on the map, in the library or on the calendar. Only the
 * phone's `complete` call — which is checked against what actually arrived —
 * flips it, which is exactly what the field app means by "synced".
 */
enum PatrolStatusEnum: string
{
    /** Parts are still arriving (or the patrol was never finished). */
    case Recording = 'recording';

    /** Every declared part is present; the patrol is a record now. */
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::Recording => 'Recording',
            self::Complete => 'Complete',
        };
    }

    /** Whether the module may present this patrol as a finished record. */
    public function isPresentable(): bool
    {
        return self::Complete === $this;
    }
}
