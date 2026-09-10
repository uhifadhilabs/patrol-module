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

use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * THE GROUND A MONTH'S ROUTES COVERED, HELD FOR THE DAY.
 *
 * PL·03 states the share of the area within {@see PatrolDashboardService::COVERAGE_BUFFER_M}
 * of a track; the coverage plate draws that same set operation as a shape. It is
 * genuine work — a month of dense traces buffered, unioned, clipped to the
 * boundary and simplified — and it is asked for on every load of the dashboard
 * and of the widget library.
 *
 * WHY IT IS CACHED, AND WHY BY THE DAY. It answers in roughly 145 ms on a
 * seeded month of 142 traces, which is a visible share of a page load for a
 * figure that changes only when a new track arrives. Held per AREA, per MONTH
 * and per DAY, so the key rolls at midnight and a shape can never outlive the
 * day it was measured on — a track synced this afternoon is on the plate
 * tomorrow morning at the latest, and the KPI beside it moves with it.
 *
 * IT IS NOT KEYED BY THE FILTER. The covered ground is the MONTH's, exactly as
 * the KPI beside it is: the shape on the plate and the number in the strip must
 * be the same measurement or one of them is lying. Narrowing to a type or a
 * station changes which routes are drawn over it, never the ground the month
 * covered.
 *
 * THE CACHE IS OPTIONAL. A host that wired none still gets the answer, measured
 * every time — the cache is a saving, never a requirement, and a coverage layer
 * that silently drew nothing without one would be worse than a slow one.
 *
 * @see https://symfony.com/doc/current/cache.html#cache-invalidation
 * @see vendor/symfony/cache-contracts/CacheInterface.php
 */
final readonly class PatrolCoverageService
{
    /**
     * How long a measured shape is held. The DAY is already in the key, so this
     * is only the ceiling for one written just before midnight; the key, not the
     * clock, is what makes the answer a day old at most.
     */
    private const int TTL_SECONDS = 86400;

    public function __construct(
        private PatrolRepository $patrols,
        private float $bufferMetres,
        private ?CacheInterface $cache = null,
    ) {
    }

    /**
     * The covered ground as GeoJSON text, or null where the month recorded no
     * track at all — which the plate draws as an empty layer that still ships
     * its legend row.
     *
     * "Now" is handed in rather than read from the clock, like every other
     * instant in this module, so the day a shape is filed under is a parameter
     * and not a second code path.
     */
    public function bufferFor(
        AreaOfInterest $area,
        \DateTimeImmutable $monthStart,
        \DateTimeImmutable $nextMonth,
        \DateTimeImmutable $now,
    ): ?string {
        $measure = fn (): ?string => $this->patrols->coverageBufferGeoJson(
            $area,
            $this->bufferMetres,
            $monthStart,
            $nextMonth,
        );

        $key = self::key($area, $monthStart, $now);
        if (null === $this->cache || null === $key) {
            return $measure();
        }

        /** @var string|null $covered */
        $covered = $this->cache->get(
            $key,
            static function (CacheItemInterface $item) use ($measure): ?string {
                $item->expiresAfter(self::TTL_SECONDS);

                return $measure();
            },
        );

        return $covered;
    }

    /**
     * The area, the month and the day, in a key PSR-6 accepts: the reserved
     * characters {}()/\@: may not appear, so the area is named by its uuid and
     * the two dates by their plain digits.
     *
     * Null for an area that has never been saved, which has no stable name to
     * file an answer under — and no recorded track to measure either.
     *
     * @see https://www.php-fig.org/psr/psr-6/#definitions
     */
    private static function key(AreaOfInterest $area, \DateTimeImmutable $monthStart, \DateTimeImmutable $now): ?string
    {
        $uuid = $area->getUuid();

        return null === $uuid ? null : \sprintf(
            'patrol.coverage.%s.%s.%s',
            $uuid->toRfc4122(),
            $monthStart->format('Ym'),
            $now->format('Ymd'),
        );
    }
}
