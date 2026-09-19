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

namespace Uhifadhi\Patrol\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Patrol\Module\PatrolPerformanceGeo;

/**
 * WHAT THE TWO GROUND SERIES CLAIM, asserted without a kernel — a key, a unit
 * and a direction are properties of the figure, never of a request.
 *
 * A PLATE HUES A PLACING. A series that lost its polarity would still draw,
 * shaded exactly as before, with nothing on the page to say that the claim
 * "more of this is better" had gone missing — which is the silent loss this
 * test exists to hold.
 */
final class PatrolPerformanceGeoSeriesTest extends TestCase
{
    public function testTheTwoSeriesAreKeyedUnderThisModule(): void
    {
        self::assertSame('patrols.coverage_by_area', PatrolPerformanceGeo::BY_AREA);
        self::assertSame('patrols.coverage_by_zone', PatrolPerformanceGeo::BY_ZONE);
        self::assertNotSame(PatrolPerformanceGeo::BY_AREA, PatrolPerformanceGeo::BY_ZONE);
    }

    public function testCoverageIsAShareAndJudgesUpwards(): void
    {
        self::assertSame('%', PatrolPerformanceGeo::UNIT);
        self::assertSame(ColumnPolarity::Up, PatrolPerformanceGeo::POLARITY);
        self::assertTrue(PatrolPerformanceGeo::POLARITY->judges(), 'A plate that hues must know which way is good.');
    }
}
