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

namespace UhifadhiLabs\Patrol\Enum;

/**
 * How a drone launch point's coverage sector is described — API-CONTRACT.md §7.
 *
 * Drone coverage is DECLARED, not measured: nobody records where the aircraft
 * actually flew, so the module renders the sector the operator claimed rather
 * than pretending to a track.
 */
enum SectorTypeEnum: string
{
    /** A circle of {@see \UhifadhiLabs\Patrol\Entity\LaunchPoint::getSectorRadiusM()} around the launch point. */
    case Radius = 'radius';

    /** An explicit GeoJSON Polygon (lon/lat) the operator drew. */
    case Polygon = 'polygon';

    public function label(): string
    {
        return match ($this) {
            self::Radius => 'Radius',
            self::Polygon => 'Polygon',
        };
    }
}
