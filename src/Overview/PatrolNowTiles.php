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

namespace UhifadhiLabs\Patrol\Overview;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Overview\NowTile;
use Uhifadhi\Overview\NowTileProviderInterface;
use UhifadhiLabs\Patrol\Service\PatrolOverviewService;

/**
 * PATROLS' TWO TILES IN THE HOST'S RIGHT-NOW STRIP — PL·N1 and PL·N2.
 *
 * The host lays the strip out and orders it; it writes neither of these and
 * knows what neither counts. Install the module and they join the row, uninstall
 * it and they leave, and the same two objects are what the duty board draws at
 * wall scale — so "3 out" cannot read one way in the strip and another on the
 * wall.
 *
 * ABSENT IS NOT ZERO, and this is the clearest place it bites. If nobody is out,
 * PL·N1 is not returned: a tile reading 0 would claim the module measured the
 * area's live patrols and found none, which is a different statement from "there
 * is nothing to say about patrols being out".
 *
 * AND A TRUE ZERO IS NOT ABSENT, which is the other half of the same rule. PL·N2
 * stays on the strip through a quiet morning and reads 0 km, because an area
 * with a register really was measured and really did walk nothing yet. Only an
 * area that has never opened a patrol has no day to report.
 *
 * The numbers come from {@see PatrolOverviewService}, the one place this
 * module's reading of the morning is measured, so the strip's count and the
 * live card's rows are the same patrols.
 */
final readonly class PatrolNowTiles implements NowTileProviderInterface
{
    public function __construct(
        private PatrolOverviewService $overview,
        /** @var array<string, array{label: string}> the deployment's patrol.types map */
        private array $types,
    ) {
    }

    public function moduleSlug(): string
    {
        return PatrolOverviewContributor::SLUG;
    }

    public function nowTilesFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        return [
            ...$this->outTile($area, $now),
            ...$this->walkedTile($area, $now),
        ];
    }

    /**
     * PL·N1 — how many patrols are out, what kind, and the one that has stopped
     * talking.
     *
     * THE ONLY LIVE TILE THE MODULE SHIPS. `live: true` is a claim the surface's
     * one polling endpoint refreshes it, and it is true of this and of nothing
     * else here: yesterday's kilometres do not change while somebody watches.
     *
     * The alarm is the STALEST silent patrol rather than a count of them,
     * because a strip tile has room for one fact and the fact worth having is
     * which handset to raise. The rest of them are rows in "needs attention",
     * where there is room for all of them.
     *
     * @return list<NowTile>
     */
    private function outTile(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $out = $this->overview->out($area, $now);
        if ([] === $out) {
            return [];
        }

        $counts = [];
        foreach ($out as $row) {
            $type = $row['patrol']->getType();
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $type => $count) {
            $parts[] = \sprintf('%d %s', $count, mb_strtolower($this->types[$type]['label'] ?? $type));
        }

        // The longest-silent of the silent ones — the same one the live card
        // names in its closing line, counted in the same place, so the strip and
        // the card can never name two different handsets.
        $worst = $this->overview->handsets($out)['worst'];

        $alarm = null;
        if (null !== $worst) {
            $where = $worst['patrol']->getStation() ?? $worst['patrol']->getRef();
            $alarm = null === $worst['pingLabel']
                // Out long enough to have said something, and it never has.
                ? \sprintf('%s no ping yet', $where)
                : \sprintf('%s no ping %s', $where, $worst['pingLabel']);
        }

        return [new NowTile(
            'PL·N1',
            PatrolOverviewContributor::SLUG,
            'Patrols out',
            (string) \count($out),
            implode(' · ', $parts),
            alarm: $alarm,
            tone: null === $worst ? NowTile::TONE_HOT : NowTile::TONE_BAD,
            live: true,
            url: $this->overview->dashboardUrl($area),
            priority: 100,
        )];
    }

    /**
     * PL·N2 — the day's walking: kilometres closed today, and what closed and
     * was logged alongside them.
     *
     * A ZERO DAY IS A DAY, AND IT RENDERS AS A ZERO. Where the area has a
     * register, a morning on which nothing has closed is something this module
     * MEASURED — 0 km walked, 0 patrols closed — and the design's strip carries
     * the tile every day. It used to drop out of the row entirely, which read to
     * an area manager as the module being broken rather than the morning being
     * quiet, and which is the one thing an honest-absent rule must never buy.
     *
     * WHAT IS STILL ABSENT RATHER THAN ZERO is an area that has never opened a
     * patrol at all: there is no day to measure, so there is no tile. That is
     * the same line the incidents module draws between "filed today · 0" and an
     * area with no register — one asks the day, the other has no day to ask.
     *
     * The distance is an EM DASH where patrols DID close today and none of them
     * recorded one — a day of hand-logged patrols walked a distance nobody
     * measured, which is a different statement from having walked nothing. The
     * subline still states what closed, because that part IS known.
     *
     * @return list<NowTile>
     */
    private function walkedTile(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $today = $this->overview->today($area, $now);
        $quiet = 0 === $today['closed'] && 0 === $today['observations'];
        if ($quiet && !$this->overview->hasRegister($area)) {
            return [];
        }

        // Nothing closed means nothing walked, which is a measurement of 0 km.
        // A null distance is only unknown where there was something to measure.
        $known = null !== $today['distanceKm'] || 0 === $today['closed'];

        return [new NowTile(
            'PL·N2',
            PatrolOverviewContributor::SLUG,
            'Walked today',
            $known ? number_format($today['distanceKm'] ?? 0.0) : '—',
            \sprintf(
                '%d %s closed · %d %s',
                $today['closed'],
                1 === $today['closed'] ? 'patrol' : 'patrols',
                $today['observations'],
                1 === $today['observations'] ? 'observation' : 'observations',
            ),
            unit: $known ? 'km' : null,
            url: $this->overview->dashboardUrl($area),
            priority: 110,
        )];
    }
}
