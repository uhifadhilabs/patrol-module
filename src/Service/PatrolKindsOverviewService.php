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

namespace Uhifadhi\Patrol\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Entity\TaxonomySubcategory;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;

/**
 * WHAT THIS AREA FILES UNDER, AND HOW MUCH OF EACH — the read-only kinds
 * overview's whole reading: every kind with this month's tally beside it, and
 * for the kind on screen a matrix of its sub-categories across three windows.
 *
 * IT COUNTS BY THE WIRE-CODE, the one bridge the two vocabulary models have
 * ({@see PatrolKindsService} states the same rule for the dashboard's card). An
 * observation carries a flat category word; a kind and a sub-category each own
 * a code; so an observation counts to whichever of the two its word matches. A
 * word nothing has been filed under counts zero, which is an answer and not a
 * gap.
 *
 * A KIND'S OWN TOTAL IS ITS WORD PLUS ITS CHILDREN'S. An area that files
 * straight against the kind and an area that files against sub-categories both
 * get a total that is the whole of what was logged under that kind.
 *
 * THE HEAT IS MEASURED INSIDE THE KIND, never across the page: the busiest
 * sub-category of the kind on screen is the full wash, so a quiet kind still
 * reads as a distribution rather than as an empty row of grey.
 *
 * @phpstan-type KindOverviewRow array{
 *     kind: TaxonomyKind,
 *     thisMonth: int,
 *     lastMonth: int,
 *     allTime: int,
 *     subCount: int,
 *     rows: list<array{sub: TaxonomySubcategory, thisMonth: int, lastMonth: int, allTime: int, weight: float}>
 * }
 */
final readonly class PatrolKindsOverviewService
{
    public function __construct(
        private TaxonomyKindRepository $kinds,
        private ObservationRepository $observations,
    ) {
    }

    /**
     * @return list<KindOverviewRow>
     */
    public function forArea(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $thisMonthStart = $now->modify('first day of this month')->setTime(0, 0);
        $lastMonthStart = $thisMonthStart->modify('-1 month');
        $nextMonthStart = $thisMonthStart->modify('+1 month');

        $thisMonth = $this->observations->countByArea($area, $thisMonthStart, $nextMonthStart);
        $lastMonth = $this->observations->countByArea($area, $lastMonthStart, $thisMonthStart);
        $allTime = $this->observations->countByArea($area);

        $overview = [];
        foreach ($this->kinds->forArea($area) as $kind) {
            $rows = [];
            $busiest = 0;
            foreach ($kind->getSubcategories() as $sub) {
                $code = $sub->getCode();
                $tally = $thisMonth[$code] ?? 0;
                $busiest = max($busiest, $tally);
                $rows[] = [
                    'sub' => $sub,
                    'thisMonth' => $tally,
                    'lastMonth' => $lastMonth[$code] ?? 0,
                    'allTime' => $allTime[$code] ?? 0,
                    'weight' => 0.0,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['thisMonth'] <=> $a['thisMonth']);

            foreach ($rows as $index => $row) {
                $rows[$index]['weight'] = 0 === $busiest ? 0.0 : round($row['thisMonth'] / $busiest, 2);
            }

            $own = $kind->getCode();
            $overview[] = [
                'kind' => $kind,
                'thisMonth' => ($thisMonth[$own] ?? 0) + array_sum(array_column($rows, 'thisMonth')),
                'lastMonth' => ($lastMonth[$own] ?? 0) + array_sum(array_column($rows, 'lastMonth')),
                'allTime' => ($allTime[$own] ?? 0) + array_sum(array_column($rows, 'allTime')),
                'subCount' => \count($rows),
                'rows' => $rows,
            ];
        }

        // A retired word keeps its place in the list's order and sits below the
        // live ones, where the design dims it: it is still filed against, still
        // exported, and still not something a handset offers.
        usort($overview, static fn (array $a, array $b): int => (int) $b['kind']->isActive() <=> (int) $a['kind']->isActive());

        return $overview;
    }
}
