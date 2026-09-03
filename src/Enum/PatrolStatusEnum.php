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

    /**
     * Whether the module may DRAW this patrol at all — on the map, in the log,
     * in the feed, on the calendar.
     *
     * FALSE for exactly one status: `Recording`. Its parts are still arriving,
     * so every field a surface would print is provisional — a distance that
     * will grow, a track that stops in the middle of nowhere, an end time that
     * has not happened. Drawing it is not "showing work in progress", it is
     * publishing a half-uploaded record as a finished one.
     *
     * TRUE for a discarded patrol, which is not a contradiction: a discard is a
     * CLOSED recording, and the settled discard design shows it deliberately —
     * subdued, pilled, with its reason and its purge date — because a server
     * that hid what a ranger worked to upload gives them no way to tell
     * "accepted" from "lost". Presentable is about whether the recording has
     * finished, never about whether the effort counted; that second question is
     * {@see self::countsTowardsStatistics()}, and the two answers differ for a
     * discard on purpose.
     */
    public function isPresentable(): bool
    {
        return self::Recording !== $this;
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
     *
     * FALSE for a recording patrol too, and for a different reason: not that
     * the effort is withdrawn but that it is not all here yet. Half a track
     * buffered into a coverage share reports ground nobody has finished
     * walking, and a partial distance added to the month makes the month wrong
     * until the phone happens to finish syncing. A figure is only honest over
     * patrols that are whole.
     *
     * So a statistic is the STRICTER of the two questions — presentable AND not
     * discarded, which leaves `Complete` alone.
     */
    public function countsTowardsStatistics(): bool
    {
        return $this->isPresentable() && self::Discarded !== $this;
    }
}
