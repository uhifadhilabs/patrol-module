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
 * What a GPX file actually contained, before anything is saved: the points in
 * document order, the time span, the summed distance and the GPS gaps (silences
 * longer than the configured threshold — flagged, stored, never smoothed).
 */
final readonly class ParsedTrack
{
    /**
     * @param list<array{0: float, 1: float}> $points [lon, lat], document order
     * @param list<?\DateTimeImmutable>       $times  when each point was recorded, in the
     *                                                same order and the same length; an entry
     *                                                is null where the file timed some fixes
     *                                                and not that one
     */
    public function __construct(
        public array $points,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        public float $distanceKm,
        public int $gapCount,
        public array $times = [],
    ) {
    }

    public function pointCount(): int
    {
        return \count($this->points);
    }

    /**
     * WHERE THE PATROL WAS AT A GIVEN MOMENT — the design's "from the track at
     * 07:20", which is how an observation lands where the patrol actually was
     * rather than where somebody guessed.
     *
     * The NEAREST timed fix, not an interpolation between two. A track is a
     * sequence of places a person stood; a point between two of them is a place
     * nobody was, and inventing one would make the map say something the file
     * does not. With a fix every few seconds the difference is metres, and where
     * it is not — a long silence, which this module already counts as a gap —
     * being on the last known fix is the honest answer.
     *
     * Null where the track carries no clock at all: "the file cannot say" is a
     * different fact from "the patrol was at the start".
     *
     * @return array{0: float, 1: float}|null [lon, lat]
     */
    public function positionAt(\DateTimeImmutable $moment): ?array
    {
        $nearest = null;
        $distance = null;

        foreach ($this->times as $index => $time) {
            if (null === $time || !isset($this->points[$index])) {
                continue;
            }
            $apart = abs($time->getTimestamp() - $moment->getTimestamp());
            if (null === $distance || $apart < $distance) {
                $distance = $apart;
                $nearest = $this->points[$index];
            }
        }

        return $nearest;
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
