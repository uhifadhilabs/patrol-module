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
 * ONE ROW OF "NEEDS ATTENTION" — the host owns the list, a module owns the item.
 *
 * The area overview's attention list is not a list the host wrote. Every row is
 * returned by an installed module: a patrol that has stopped pinging, an
 * observation nobody filed, an incident past its term, a zone nobody has
 * entered. The host merges them, sorts them by urgency and draws them all
 * identically. That is the whole point of the seam — the host does not know what
 * a snare line is and must not, because the day it does, uninstalling a module
 * leaves a hard-coded row behind.
 *
 * NOBODY DISMISSES ONE BY HAND. An item leaves the list when the thing that
 * raised it is dealt with, because the host never stored it: the aggregator asks
 * every provider on every render. There is no acknowledged flag here on purpose.
 *
 * @see AttentionProviderInterface
 */
final readonly class AttentionItem
{
    /**
     * @param string       $headline   the sentence, in the module's own words; its first clause is emphasised
     * @param string|null  $detail     the rest, when the headline alone is not enough
     * @param string       $kind       what KIND of thing this is, in the module's vocabulary
     *                                 ("live position", "past term", "coverage gap") — the host prints it
     *                                 after the module name and never interprets it
     * @param list<string> $meta       short facts the row shows as chips: a place, a record id, a time
     * @param string       $ageLabel   how old, said the way the module says it ("2 h 10", "9 d")
     * @param int          $ageSeconds the same age as a number, so the host can sort within a severity
     * @param string       $url        where the row goes — the module's own page for the thing
     */
    public function __construct(
        public AttentionSeverity $severity,
        public string $moduleSlug,
        public string $moduleLabel,
        public string $headline,
        public string $kind,
        public string $ageLabel,
        public int $ageSeconds,
        public string $url,
        public ?string $detail = null,
        public array $meta = [],
    ) {
        if ('' === $moduleSlug || '' === $headline || '' === $url) {
            throw new \InvalidArgumentException('An attention item needs a module, a headline and somewhere to go.');
        }
        if ($ageSeconds < 0) {
            throw new \InvalidArgumentException('An attention item cannot be younger than nothing.');
        }
    }
}
