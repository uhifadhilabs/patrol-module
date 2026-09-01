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

namespace Uhifadhi\Overview;

/**
 * DEV/TEST-ONLY stub of uhifadhi's own class of this name, carried in the
 * bundle's autoload-dev so the module's overview contribution compiles, is
 * phpstan'd and is tested standalone. COPIED VERBATIM, signatures and
 * validation alike: the moment it drifts from the host's the tests stop
 * proving anything about the seam. NOT shipped — autoload-dev is dropped on
 * install, and inside uhifadhi the real class is loaded instead.
 *
 * How loudly one {@see AttentionItem} asks.
 *
 * THREE STEPS, NOT A NUMBER. A module says which of three things it means and
 * the host sorts by it — a free integer would let two modules invent
 * incompatible scales and the merged list would be sorted by nothing.
 *
 * The words are the module's promise about time, not about importance: `Now`
 * means somebody has to act today, `Soon` means this week, `Watch` means it is
 * being carried and would be missed if it were not written down.
 */
enum AttentionSeverity: string
{
    case Now = 'now';
    case Soon = 'soon';
    case Watch = 'watch';

    /** Loudest first — the order the host's one list is sorted in. */
    public function rank(): int
    {
        return match ($this) {
            self::Now => 0,
            self::Soon => 1,
            self::Watch => 2,
        };
    }

    /** The modifier the row's markup wears; the stylesheet colours the rail from it. */
    public function cssClass(): string
    {
        return $this->value;
    }
}
