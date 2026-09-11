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

use Uhifadhi\Patrol\Entity\Patrol;

/**
 * ONE PAGE OF THE FULL LOG, and every number the page around it prints.
 *
 * IT IS THE ANSWER TO ONE GET. The filter row, the counts beside each option,
 * the rows and the pager are all readings of a single query, so the page can
 * never say "142 patrols" over a table showing a different question's rows.
 *
 * THE COUNTS ARE THE NARROWED VIEW'S. Each option's count is what choosing it
 * would show — every OTHER axis still applied — so a count is a promise about
 * the click rather than a fact about the whole month.
 */
final readonly class PatrolList
{
    /**
     * @param list<Patrol>          $patrols       the rows of the page on screen, newest first
     * @param int                   $totalCount    patrols matching every axis, across all pages
     * @param int                   $allCount      patrols matching every axis but the type — the "all" option's count
     * @param array<string, int>    $typeCounts    type key → what choosing it would show
     * @param array<string, int>    $stationCounts station KEY → what choosing it would show
     * @param array<string, int>    $zoneCounts    zone → what choosing it would show
     * @param array<string, string> $stations      the stations the month's patrols set off from, key → label
     * @param list<string>          $zones         the zones the month's patrols set out in
     * @param array<string, string> $patrolZones   patrol uuid → the zone it set out in
     * @param int                   $page          the 1-based page on screen
     * @param int                   $pageCount     how many pages the answer fills, at least one
     * @param int                   $firstIndex    the 1-based position of the first row on screen, 0 when there are none
     * @param int                   $lastIndex     the 1-based position of the last row on screen, 0 when there are none
     */
    public function __construct(
        public array $patrols,
        public int $totalCount,
        public int $allCount,
        public array $typeCounts,
        public array $stationCounts,
        public array $zoneCounts,
        public array $stations,
        public array $zones,
        public array $patrolZones,
        public int $page,
        public int $pageCount,
        public int $firstIndex,
        public int $lastIndex,
    ) {
    }
}
