<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Service;

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
}
