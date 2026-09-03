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
 * What kind of thing happened to a patrol — API-CONTRACT.md §9A.
 *
 * An enum, unlike patrol types and observation categories, and the difference is
 * behaviour: each kind here CHANGES THE PATROL ROW in its own way, so the module
 * has to be able to name every one it accepts. A deployment cannot invent a
 * fourth kind, because there would be no code to apply it — and an event stored
 * with nothing to apply is a row that claims something happened while the record
 * says otherwise.
 *
 * Unknown kinds are therefore refused at the door rather than stored.
 */
enum PatrolEventKindEnum: string
{
    /** The ranger gave the patrol a different name. Payload: `name`. */
    case Renamed = 'renamed';

    /** The ranger corrected the patrol type. Payload: `type`. */
    case TypeChanged = 'type_changed';

    /** The ranger threw the patrol away. Payload: `reason`. */
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Renamed => 'Renamed',
            self::TypeChanged => 'Type changed',
            self::Discarded => 'Discarded',
        };
    }

    /**
     * The payload key this kind carries its one value in.
     *
     * Named here rather than at each call site so the sync service, the entity
     * and the history card can never disagree about where a name lives.
     */
    public function payloadKey(): string
    {
        return match ($this) {
            self::Renamed => 'name',
            self::TypeChanged => 'type',
            self::Discarded => 'reason',
        };
    }
}
