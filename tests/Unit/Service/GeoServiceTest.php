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

namespace Uhifadhi\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Service\GeoService;

/**
 * The position helpers behind the observation rows and the observation meta
 * plate. Expectations are computed, not guessed: a value's total arc-seconds
 * are round(|value| * 3600), split as degrees = seconds / 3600, minutes =
 * (seconds % 3600) / 60, seconds = the remainder — e.g. 3.195° * 3600 =
 * 11 502" = 3° 11' 42", and 29.530556° * 3600 = 106 310.002" → 106 310" =
 * 29° 31' 50".
 */
final class GeoServiceTest extends TestCase
{
    /** @return iterable<string, array{float, float, string}> */
    public static function positions(): iterable
    {
        yield 'southern + western hemisphere' => [-29.530556, -3.195, '3°11\'42"S 29°31\'50"W'];
        yield 'northern + eastern hemisphere' => [70.25, 12.5, '12°30\'00"N 70°15\'00"E'];
        yield 'null island counts as N/E' => [0.0, 0.0, '0°00\'00"N 0°00\'00"E'];
        yield 'rounds up to whole seconds' => [179.999999, -89.5, '89°30\'00"S 180°00\'00"E'];
    }

    #[DataProvider('positions')]
    public function testItPrintsAPositionAsDegreesMinutesSecondsLatitudeFirst(float $lon, float $lat, string $expected): void
    {
        self::assertSame($expected, new GeoService()->formatDms($lon, $lat));
    }

    public function testItReadsTheCoordinatePairOutOfAGeoJsonPoint(): void
    {
        self::assertSame(
            [-29.530556, -3.195],
            new GeoService()->coordinates('{"type":"Point","coordinates":[-29.530556,-3.195]}'),
        );
    }

    public function testItRefusesGeometryThatIsNotAPoint(): void
    {
        $this->expectException(\LogicException::class);

        new GeoService()->coordinates('{"type":"LineString","coordinates":[[1,2],[3,4]]}');
    }
}
