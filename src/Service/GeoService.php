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
     * The [lon, lat] pairs inside a GeoJSON LineString — the exact shape
     * {@see \Uhifadhi\Patrol\Model\ParsedTrack::toGeoJson()} writes into the
     * geometry column, read back for anything that has to walk the route (the
     * GPX export, most of all).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function lineCoordinates(string $geoJsonLineString): array
    {
        $decoded = json_decode($geoJsonLineString, true);
        $type = \is_array($decoded) ? ($decoded['type'] ?? null) : null;
        $coordinates = \is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;

        if ('LineString' !== $type || !\is_array($coordinates)) {
            throw new \LogicException('Not a GeoJSON LineString: '.$geoJsonLineString);
        }

        $points = [];
        foreach ($coordinates as $pair) {
            $lon = \is_array($pair) ? ($pair[0] ?? null) : null;
            $lat = \is_array($pair) ? ($pair[1] ?? null) : null;
            if (!is_numeric($lon) || !is_numeric($lat)) {
                throw new \LogicException('Not a GeoJSON LineString: '.$geoJsonLineString);
            }
            $points[] = [(float) $lon, (float) $lat];
        }

        return $points;
    }

    /**
     * The MIDDLE OF A GEOMETRY'S BOUNDING BOX, as [lon, lat] — or null where the
     * text carries no coordinate at all.
     *
     * The bbox centre and not a centroid, and the difference matters less than it
     * sounds: what asks for this is a picker opening a plate on an area, where the
     * question is "somewhere inside the frame" and not "the balance point of the
     * polygon". A true centroid needs the geometry engine, which means a database
     * round trip for a marker's starting position.
     *
     * EVERY GEOMETRY, WITHOUT KNOWING WHICH. A Point's coordinates, a Polygon's
     * rings and a MultiPolygon's polygons are the same numbers at different depths,
     * so the pairs are gathered by walking the nesting rather than by branching on
     * `type` — which is also what makes it answer for a shape this module has not
     * met yet.
     *
     * @return array{0: float, 1: float}|null
     */
    public function centre(string $geoJson): ?array
    {
        $decoded = json_decode($geoJson, true);
        $coordinates = \is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;
        if (!\is_array($coordinates)) {
            return null;
        }

        $box = [];
        self::gather($coordinates, $box);

        if ([] === $box) {
            return null;
        }

        $lons = array_column($box, 0);
        $lats = array_column($box, 1);

        return [(min($lons) + max($lons)) / 2, (min($lats) + max($lats)) / 2];
    }

    /**
     * Every [lon, lat] pair anywhere inside a coordinates member, however deeply
     * the geometry nests them.
     *
     * @param array<array-key, mixed>         $coordinates
     * @param list<array{0: float, 1: float}> $found
     */
    private static function gather(array $coordinates, array &$found): void
    {
        $first = $coordinates[0] ?? null;
        if (is_numeric($first) && is_numeric($coordinates[1] ?? null)) {
            $found[] = [(float) $first, (float) $coordinates[1]];

            return;
        }

        foreach ($coordinates as $nested) {
            if (\is_array($nested)) {
                self::gather($nested, $found);
            }
        }
    }

    /**
     * A GeoJSON Point from a latitude and a longitude, or NULL where the pair is
     * not a place on Earth.
     *
     * OFF THE WORLD IS NOT A COORDINATE. Latitude runs to ±90 and longitude to
     * ±180, and a pair outside that is not a point somebody nudged too far — it is
     * a value nothing on a map could have produced. So it is refused rather than
     * clamped: clamping would file a station at the pole and call it placed.
     *
     * Written in the axis order GeoJSON states — longitude first — which is the
     * order {@see self::coordinates()} reads back and the order the column stores.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7946#section-3.1.1
     */
    public function pointGeoJson(float $lat, float $lon): ?string
    {
        if (abs($lat) > 90.0 || abs($lon) > 180.0) {
            return null;
        }

        return json_encode(['type' => 'Point', 'coordinates' => [$lon, $lat]], \JSON_THROW_ON_ERROR);
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
