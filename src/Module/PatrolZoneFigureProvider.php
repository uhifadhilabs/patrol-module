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

namespace Uhifadhi\Patrol\Module;

use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureRequest;
use Uhifadhi\Contracts\Kpi\ZoneFigures;
use Uhifadhi\Contracts\Kpi\ZoneRef;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\PatrolDashboardService;

/**
 * WHAT THE PATROLS MODULE RECORDED OVER EACH ZONE OF AN AREA — three figures per
 * zone, measured in one pass over the whole set the caller is about to draw.
 *
 * A zone is the area module's ground and this module's records are the module's;
 * the seam is where the two meet, so nothing here names a zone, stores one, or
 * decides who may read it. The zone arrives as a ref, the geometry is read from
 * the area module's own table, and the answer is keyed by the zone's published
 * uuid.
 *
 * WHY THE COUNT AND THE SHARE DISAGREE ABOUT WHICH PATROLS MATTER, and it is not
 * an inconsistency: a patrol is "in" a zone when its TRACK entered the ring, but
 * ground is covered by every track within its own type's width of it — a round
 * walked along the fence covers the ring's edge without ever crossing it. The
 * two questions have two answers, and {@see PatrolRepository::zoneFiguresFor()}
 * gives each its own set.
 *
 * NOTHING IS A ZERO THAT WAS NOT MEASURED. A zone no track entered, in a month
 * the area recorded no track at all, is left out of the answer entirely — the
 * surfaces render that absence in the product's own words, which is the honest
 * reading of "this module has nothing to say about that ground".
 */
final class PatrolZoneFigureProvider implements ZoneFigureProviderInterface
{
    public function __construct(
        private readonly PatrolRepository $patrols,
        /** The slug this module is registered under in the registry's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Patrols',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * THREE FIGURES PER ZONE, ONCE: the patrols that entered it, the distance
     * they ran inside it, and the share of it the month's tracks lie over.
     *
     * The period answered is the period asked for — a patrol carries the instant
     * it started and the query windows on that, so there is no window this module
     * can measure and the caller cannot ask for.
     */
    public function figuresFor(ZoneFigureRequest $request): ZoneFigures
    {
        if ($request->isEmpty()) {
            return ZoneFigures::none($request->period);
        }

        $measured = $this->patrols->zoneFiguresFor(
            $request->zoneUuids(),
            PatrolDashboardService::COVERAGE_BUFFER_M,
            $request->period->from,
            $request->period->until,
        );

        $byZone = [];
        foreach ($request->zones as $zone) {
            $figures = $measured[$zone->zoneUuid] ?? null;
            if (null === $figures) {
                continue;
            }

            // Nothing entered the ring AND nothing was measured over it: the month
            // recorded no track in this area, which is unknown and not naught.
            if (0 === $figures['patrols'] && null === $figures['coverageFraction']) {
                continue;
            }

            $byZone[$zone->zoneUuid] = $this->plates($zone, $figures);
        }

        return new ZoneFigures($byZone, $request->period);
    }

    /**
     * One zone's three plates, in the order a surface draws them.
     *
     * The share is carried as the number a plate prints — 54.0 for 54 % — because
     * {@see DepartmentKpi::display()} formats the value it is handed and
     * {@see DepartmentKpi::delta()} moves a share in points.
     *
     * `previous` and the sparkline are left empty on purpose: the seam asks about
     * ONE period for a whole set of zones, and a month-over-month move would be a
     * second spatial pass per zone for a card that does not draw one.
     *
     * @param array{patrols: int, distanceKm: float, coverageFraction: float|null} $figures
     *
     * @return list<DepartmentKpi>
     */
    private function plates(ZoneRef $zone, array $figures): array
    {
        $caption = \sprintf('%s module · %s', $this->name, $zone->name);
        $fraction = $figures['coverageFraction'];

        return [
            new DepartmentKpi('patrols', 'Patrols logged', $this->slug, $this->name, (float) $figures['patrols'], '', null, [], \sprintf('%s · every track that entered the zone', $caption)),
            new DepartmentKpi('distance', 'Distance patrolled', $this->slug, $this->name, $figures['distanceKm'], 'km', null, [], \sprintf('%s · measured inside the zone only', $caption)),
            new DepartmentKpi(
                self::COVERED,
                'Covered',
                $this->slug,
                $this->name,
                null === $fraction ? null : $fraction * 100.0,
                DepartmentKpi::SHARE,
                null,
                [],
                // The width is part of what the share MEANS, so it is printed with it:
                // each track counts as covering its own type's width, and a type that
                // sets none falls back on the module's figure.
                \sprintf(
                    '%s · each track at its type\'s own width, %s km where a type sets none',
                    $caption,
                    rtrim(rtrim(number_format(PatrolDashboardService::COVERAGE_BUFFER_M / 1000, 1, '.', ''), '0'), '.'),
                ),
            ),
        ];
    }
}
