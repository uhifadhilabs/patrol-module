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
 * ONE MOVE IN THE AREA PULSE.
 *
 * The pulse is NOT a log of records — it is a log of MOVES: a patrol opened, an
 * incident changed state, an observation was logged. Every module already keeps
 * one (patrol events, incident events), and they all have the same shape: a
 * time, a record, what happened, and who did it. That is why one component can
 * draw all of them and why a new module needs no work on this widget.
 *
 * The host merges and sorts by time, groups by day, and prints the contributor's
 * name on every row. It does not interpret `$move`.
 */
final readonly class PulseEvent
{
    /**
     * @param string       $recordRef what moved, in the module's own vocabulary: `P-0145`, `INC-0316`
     * @param string       $move      what happened to it: `patrol opened`, `reported → verified`
     * @param string|null  $state     the state it landed in, where the move was a transition — the
     *                                row wears the module's own status chip for it
     * @param string       $summary   one line a person can read without opening the record
     * @param list<string> $meta      short facts: the place, who did it
     * @param string       $swatch    the colour the module gives this kind of move, as CSS
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public string $moduleSlug,
        public string $moduleLabel,
        public string $recordRef,
        public string $move,
        public string $summary,
        public string $url,
        public string $swatch,
        public ?string $state = null,
        public ?string $stateClass = null,
        public array $meta = [],
    ) {
        if ('' === $moduleSlug || '' === $recordRef || '' === $move) {
            throw new \InvalidArgumentException('A pulse event needs a module, a record and a move.');
        }
    }
}
