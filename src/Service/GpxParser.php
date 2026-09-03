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

use Uhifadhi\Patrol\Exception\InvalidGpxException;
use Uhifadhi\Patrol\Model\ParsedTrack;

/**
 * Reads a GPX 1.0/1.1 document into a {@see ParsedTrack}: points in document
 * order across every <trk>/<trkseg>, the recorded time span, the haversine
 * distance, and the number of GPS gaps (consecutive timestamps further apart
 * than the threshold). Timestamps are optional in GPX — a track without them
 * still parses; it just has no time span and no measurable gaps.
 */
final class GpxParser
{
    private readonly GeoService $geo;

    public function __construct(?GeoService $geo = null)
    {
        $this->geo = $geo ?? new GeoService();
    }

    public function parse(string $xml, float $gapThresholdMinutes): ParsedTrack
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (false === $document) {
            throw new InvalidGpxException('The file is not well-formed XML.');
        }

        // GPX documents are namespaced; xpath with a registered prefix reaches
        // trkpt elements whichever GPX version wrote them.
        $namespaces = $document->getNamespaces();
        $document->registerXPathNamespace('g', $namespaces[''] ?? 'http://www.topografix.com/GPX/1/1');
        $trkpts = $document->xpath('//g:trk/g:trkseg/g:trkpt') ?: [];

        /** @var list<array{0: float, 1: float}> $points */
        $points = [];
        /** @var list<?\DateTimeImmutable> $times */
        $times = [];
        foreach ($trkpts as $trkpt) {
            $lat = isset($trkpt['lat']) ? (float) $trkpt['lat'] : null;
            $lon = isset($trkpt['lon']) ? (float) $trkpt['lon'] : null;
            if (null === $lat || null === $lon) {
                continue;
            }
            $points[] = [$lon, $lat];
            $time = $trkpt->children($namespaces[''] ?? '')->time ?? null;
            $times[] = null !== $time && '' !== (string) $time
                ? new \DateTimeImmutable((string) $time)
                : null;
        }

        if ([] === $points) {
            throw new InvalidGpxException('The file contains no track points.');
        }

        $distanceKm = 0.0;
        $gapCount = 0;
        $gapSeconds = $gapThresholdMinutes * 60;
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $distanceKm += $this->geo->haversineKm(
                $points[$i - 1][1],
                $points[$i - 1][0],
                $points[$i][1],
                $points[$i][0],
            );

            $a = $times[$i - 1];
            $b = $times[$i];
            if (null !== $a && null !== $b && ($b->getTimestamp() - $a->getTimestamp()) > $gapSeconds) {
                ++$gapCount;
            }
        }

        $recorded = array_values(array_filter($times));

        return new ParsedTrack(
            points: $points,
            startedAt: $recorded[0] ?? null,
            endedAt: [] !== $recorded ? $recorded[\count($recorded) - 1] : null,
            distanceKm: $distanceKm,
            gapCount: $gapCount,
        );
    }
}
