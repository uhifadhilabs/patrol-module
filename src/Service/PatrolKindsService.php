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

use Uhifadhi\Patrol\Entity\Patrol;

/**
 * HOW OFTEN EACH OBSERVATION KIND WAS LOGGED — the one number the dashboard's
 * read-only kinds card puts under each chip.
 *
 * IT COUNTS BY THE WIRE-CODE, which is the only bridge the two models have. An
 * observation still carries the flat `patrol.observation_categories` word
 * ({@see \Uhifadhi\Patrol\Entity\Observation::$category}) while the area-scoped
 * kinds are a parallel model; a kind's code is what a saved filter, an export
 * column and an offline handset hold, so it is what the two can be matched on.
 * A kind whose code nothing has been filed under counts zero, which is the
 * honest answer and not a missing one.
 *
 * IT IS PURE. The caller has already loaded the month; this only counts.
 */
final readonly class PatrolKindsService
{
    /**
     * @param list<Patrol> $patrols the month's patrols
     *
     * @return array<string, int> observation category/wire-code → how many this month
     */
    public function countsByCode(array $patrols): array
    {
        $counts = [];
        foreach ($patrols as $patrol) {
            foreach ($patrol->getObservations() as $observation) {
                $code = $observation->getCategory();
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
