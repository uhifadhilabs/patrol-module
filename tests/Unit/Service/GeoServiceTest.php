<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UhifadhiLabs\Patrol\Service\GeoService;

/**
 * The position helpers behind the observation rows and the observation meta
 * plate. Expectations are computed, not guessed: a value's total arc-seconds
 * are round(|value| * 3600), split as degrees = seconds / 3600, minutes =
 * (seconds % 3600) / 60, seconds = the remainder — e.g. 3.195° * 3600 =
 * 11 502" = 3° 11' 42", and 35.469444° * 3600 = 127 689.998" → 127 690" =
 * 35° 28' 10".
 */
final class GeoServiceTest extends TestCase
{
    /** @return iterable<string, array{float, float, string}> */
    public static function positions(): iterable
    {
        yield 'southern + eastern hemisphere' => [35.469444, -3.195, '3°11\'42"S 35°28\'10"E'];
        yield 'northern + western hemisphere' => [-70.25, 12.5, '12°30\'00"N 70°15\'00"W'];
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
            [35.469444, -3.195],
            new GeoService()->coordinates('{"type":"Point","coordinates":[35.469444,-3.195]}'),
        );
    }

    public function testItRefusesGeometryThatIsNotAPoint(): void
    {
        $this->expectException(\LogicException::class);

        new GeoService()->coordinates('{"type":"LineString","coordinates":[[1,2],[3,4]]}');
    }
}
