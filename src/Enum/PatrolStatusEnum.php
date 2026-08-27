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

    /**
     * The ranger threw this patrol away — a false start, a test run, a device
     * left recording in a vehicle — and said why.
     *
     * KEPT, not deleted on arrival. Two reasons, and both are about trust: a
     * server that silently drops what a phone worked to upload gives the app no
     * way to tell "accepted" from "lost", and a discard is itself a fact
     * somebody may need to read back ("that patrol was discarded as a test run
     * on the 3rd"). So it is stored, shown quietly, counted nowhere, and swept
     * up later by `patrol:purge-discarded`.
     */
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Recording => 'Recording',
            self::Complete => 'Complete',
            self::Discarded => 'Discarded',
        };
    }

    /** Whether the module may present this patrol as a finished record. */
    public function isPresentable(): bool
    {
        return self::Complete === $this;
    }

    /**
     * Whether this patrol may contribute to a KPI, a coverage figure or any
     * other statistic.
     *
     * FALSE for a discarded patrol, everywhere and without exception. A discard
     * says the effort did not happen as recorded, so counting its kilometres or
     * buffering its track into a coverage share would report ground nobody
     * walked — the exact misreading the status exists to prevent. It still
     * renders in the lists, subdued: hiding it outright would make a ranger's
     * uploaded patrol look lost.
     */
    public function countsTowardsStatistics(): bool
    {
        return self::Discarded !== $this;
    }
}
