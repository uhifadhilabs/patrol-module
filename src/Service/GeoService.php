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

namespace UhifadhiLabs\Patrol\Service;

/**
 * Small geodesy helpers the bundle owns. Pure math — no I/O, no state — so it
 * is unit-tested against independently computed truth (never hand arithmetic).
 */
final class GeoService
{
    /** IUGG mean Earth radius, km. */
    private const float EARTH_RADIUS_KM = 6371.0088;

    /** Great-circle distance between two WGS84 points, in kilometres. */
    public function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);

        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * The [lon, lat] pair inside a GeoJSON Point ({"type":"Point",
     * "coordinates":[lon, lat]} — the column's stored text).
     *
     * @return array{0: float, 1: float}
     */
    public function coordinates(string $geoJsonPoint): array
    {
        $decoded = json_decode($geoJsonPoint, true);
        $pair = \is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;
        $lon = \is_array($pair) ? ($pair[0] ?? null) : null;
        $lat = \is_array($pair) ? ($pair[1] ?? null) : null;

        if (!is_numeric($lon) || !is_numeric($lat)) {
            throw new \LogicException('Not a GeoJSON Point: '.$geoJsonPoint);
        }

        return [(float) $lon, (float) $lat];
    }

    /**
     * A position the way a field record prints it — degrees, minutes, seconds
     * with hemisphere letters, latitude first: 3°11'42"S 35°28'10"E. The
     * observation rows and the observation meta plate both state coordinates
     * this way (the settled patrol detail / observation designs).
     */
    public function formatDms(float $lon, float $lat): string
    {
        return self::dmsComponent($lat, 'N', 'S').' '.self::dmsComponent($lon, 'E', 'W');
    }

    private static function dmsComponent(float $value, string $positive, string $negative): string
    {
        $hemisphere = $value < 0 ? $negative : $positive;
        $seconds = (int) round(abs($value) * 3600);
        [$degrees, $rest] = [intdiv($seconds, 3600), $seconds % 3600];

        return \sprintf('%d°%02d\'%02d"%s', $degrees, intdiv($rest, 60), $rest % 60, $hemisphere);
    }
}
