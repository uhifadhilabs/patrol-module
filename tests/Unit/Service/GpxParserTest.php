<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use UhifadhiLabs\PatrolBundle\Exception\InvalidGpxException;
use UhifadhiLabs\PatrolBundle\Service\GpxParser;

final class GpxParserTest extends TestCase
{
    private function fixture(): string
    {
        $xml = file_get_contents(\dirname(__DIR__, 2).'/Fixtures/gpx/short_track.gpx');
        \assert(false !== $xml);

        return $xml;
    }

    public function testParsesPointsTimespanDistanceAndGaps(): void
    {
        $track = new GpxParser()->parse($this->fixture(), gapThresholdMinutes: 5.0);

        self::assertSame(4, $track->pointCount());
        self::assertEquals(new \DateTimeImmutable('2026-03-01T06:00:00Z'), $track->startedAt);
        self::assertEquals(new \DateTimeImmutable('2026-03-01T06:25:00Z'), $track->endedAt);
        // Haversine over the four fixture points, verified independently.
        self::assertEqualsWithDelta(0.5293, $track->distanceKm, 0.001);
        // 06:05 → 06:20 is a 15-minute silence — one gap above the 5-minute threshold.
        self::assertSame(1, $track->gapCount);
    }

    public function testProducesAGeoJsonLineString(): void
    {
        $track = new GpxParser()->parse($this->fixture(), gapThresholdMinutes: 5.0);

        /** @var array{type: string, coordinates: list<list<float>>} $geo */
        $geo = json_decode($track->toGeoJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('LineString', $geo['type']);
        self::assertCount(4, $geo['coordinates']);
        // GeoJSON is [lon, lat]. Whole floats may round-trip as JSON ints —
        // compare numerically, not by PHP type.
        self::assertEqualsWithDelta([-30.0, -1.0], $geo['coordinates'][0], 1e-9);
        self::assertEqualsWithDelta([-30.003, -1.003], $geo['coordinates'][3], 1e-9);
    }

    public function testAWiderGapThresholdCountsNoGaps(): void
    {
        $track = new GpxParser()->parse($this->fixture(), gapThresholdMinutes: 20.0);

        self::assertSame(0, $track->gapCount);
    }

    public function testTracksWithoutTimestampsParseWithoutTimespanOrGaps(): void
    {
        $xml = <<<'GPX'
            <?xml version="1.0" encoding="UTF-8"?>
            <gpx version="1.1" creator="pen-and-paper" xmlns="http://www.topografix.com/GPX/1/1">
              <trk><trkseg>
                <trkpt lat="-1.0" lon="-30.0"/>
                <trkpt lat="-1.001" lon="-30.001"/>
              </trkseg></trk>
            </gpx>
            GPX;

        $track = new GpxParser()->parse($xml, gapThresholdMinutes: 5.0);

        self::assertSame(2, $track->pointCount());
        self::assertNull($track->startedAt);
        self::assertNull($track->endedAt);
        self::assertSame(0, $track->gapCount);
        self::assertGreaterThan(0.0, $track->distanceKm);
    }

    public function testInvalidXmlIsRefused(): void
    {
        $this->expectException(InvalidGpxException::class);

        new GpxParser()->parse('this is not xml', gapThresholdMinutes: 5.0);
    }

    public function testAGpxFileWithoutTrackPointsIsRefused(): void
    {
        $this->expectException(InvalidGpxException::class);
        $this->expectExceptionMessage('no track points');

        new GpxParser()->parse(
            '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg/></trk></gpx>',
            gapThresholdMinutes: 5.0,
        );
    }
}
