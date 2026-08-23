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

namespace UhifadhiLabs\Patrol\Model;

/**
 * What a GPX file actually contained, before anything is saved: the points in
 * document order, the time span, the summed distance and the GPS gaps (silences
 * longer than the configured threshold — flagged, stored, never smoothed).
 */
final readonly class ParsedTrack
{
    /**
     * @param list<array{0: float, 1: float}> $points [lon, lat], document order
     */
    public function __construct(
        public array $points,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        public float $distanceKm,
        public int $gapCount,
    ) {
    }

    public function pointCount(): int
    {
        return \count($this->points);
    }

    /** The track as a GeoJSON LineString (what the geometry column stores). */
    public function toGeoJson(): string
    {
        return json_encode(
            ['type' => 'LineString', 'coordinates' => $this->points],
            \JSON_THROW_ON_ERROR,
        );
    }
}
