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

namespace Uhifadhi\Patrol\Model;

/**
 * ONE OF PL·03's RECORDS, as the form gave it — before anything has been checked
 * against the area's vocabulary or the track's clock.
 *
 * The category is ONE wire code, which is what an observation stores: the
 * sub-category's where a kind has one and the kind's own where it has not. The
 * two chip rows in the design are how a person reaches that one code, not two
 * columns behind it.
 *
 * `photoKeys` are keys the upload component already got back for this grid.
 * They are the browser's word for what it uploaded and are INTERSECTED with what
 * the draft actually holds before anything is attached — a key posted for
 * somebody else's draft attaches nothing.
 */
final readonly class LoggedObservation
{
    /**
     * @param int          $ordinal   which of the page's grids this is — 1, 2, 3 …
     * @param string       $category  the wire code the chips resolved to
     * @param ?string      $at        the time typed into PL·03's `time` row, "HH:MM"
     * @param list<string> $photoKeys evidence keys the component reported for this grid
     */
    public function __construct(
        public int $ordinal,
        public string $category,
        public ?string $at = null,
        public ?string $note = null,
        public array $photoKeys = [],
    ) {
    }

    /** Nothing chosen and nothing typed: a grid a person opened and left alone. */
    public function isEmpty(): bool
    {
        return '' === $this->category && null === $this->note && [] === $this->photoKeys;
    }
}
