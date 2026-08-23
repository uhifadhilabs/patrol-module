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

namespace UhifadhiLabs\Patrol\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use UhifadhiLabs\Patrol\Service\GpxParser;
use UhifadhiLabs\Patrol\Service\GpxWriter;

/**
 * The writer is the inverse of {@see GpxParser}: what it emits must parse back
 * into the same points, so every assertion here is either about the document's
 * shape or about that round trip.
 */
final class GpxWriterTest extends TestCase
{
    private const string LINE = '{"type":"LineString","coordinates":[[-30.0,-1.0],[-30.001,-1.0005],[-30.0025,-1.001]]}';

    public function testWritesAGpx11TrackWithOneTrkptPerCoordinate(): void
    {
        $xml = new GpxWriter()->write('Patrol P-0016', self::LINE);

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        self::assertStringContainsString('<gpx', $xml);
        self::assertStringContainsString('version="1.1"', $xml);
        self::assertStringContainsString('xmlns="http://www.topografix.com/GPX/1/1"', $xml);
        self::assertStringContainsString('<trkseg>', $xml);

        $document = simplexml_load_string($xml);
        self::assertNotFalse($document);
        $document->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
        self::assertCount(3, $document->xpath('//g:trk/g:trkseg/g:trkpt') ?: []);
        self::assertSame('Patrol P-0016', (string) ($document->xpath('//g:trk/g:name')[0] ?? ''));
    }

    public function testWhatItWritesParsesBackIntoTheSamePoints(): void
    {
        $xml = new GpxWriter()->write('Patrol P-0016', self::LINE);

        $track = new GpxParser()->parse($xml, gapThresholdMinutes: 5.0);

        self::assertSame(3, $track->pointCount());
        // GeoJSON in, GeoJSON out — the round trip is lossless for geometry.
        /** @var array{coordinates: list<list<float>>} $geo */
        $geo = json_decode($track->toGeoJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertEqualsWithDelta([-30.0, -1.0], $geo['coordinates'][0], 1e-9);
        self::assertEqualsWithDelta([-30.0025, -1.001], $geo['coordinates'][2], 1e-9);
    }

    public function testObservationsBecomeWaypointsWithNameDescriptionAndTime(): void
    {
        $xml = new GpxWriter()->write('Patrol P-0016', self::LINE, [
            [
                'position' => '{"type":"Point","coordinates":[-30.0005,-1.0002]}',
                'name' => '1 · Maintenance need',
                'description' => 'Culvert washed out on the ridge track.',
                'time' => new \DateTimeImmutable('2026-03-01T06:48:00+00:00'),
            ],
            [
                'position' => '{"type":"Point","coordinates":[-30.0015,-1.0008]}',
                'name' => '2 · unlisted',
                'description' => null,
                'time' => null,
            ],
        ]);

        $document = simplexml_load_string($xml);
        self::assertNotFalse($document);
        $document->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');

        $waypoints = $document->xpath('//g:wpt') ?: [];
        self::assertCount(2, $waypoints);
        // GPX is lat/lon attributes, the mirror of GeoJSON's [lon, lat].
        self::assertEqualsWithDelta(-1.0002, (float) $waypoints[0]['lat'], 1e-9);
        self::assertEqualsWithDelta(-30.0005, (float) $waypoints[0]['lon'], 1e-9);
        self::assertSame('1 · Maintenance need', (string) $waypoints[0]->name);
        self::assertSame('Culvert washed out on the ridge track.', (string) $waypoints[0]->desc);
        self::assertSame('2026-03-01T06:48:00Z', (string) $waypoints[0]->time);
        // A waypoint that knows no note and no time states neither.
        self::assertCount(0, $waypoints[1]->desc);
        self::assertCount(0, $waypoints[1]->time);
    }

    public function testMetadataCarriesTheRecordedStartAndTheDescription(): void
    {
        $xml = new GpxWriter()->write(
            'Patrol P-0016',
            self::LINE,
            recordedAt: new \DateTimeImmutable('2026-03-01T06:10:00+00:00'),
            description: 'walking round patrol · North post',
        );

        $document = simplexml_load_string($xml);
        self::assertNotFalse($document);
        $document->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
        self::assertSame('2026-03-01T06:10:00Z', (string) ($document->xpath('//g:metadata/g:time')[0] ?? ''));
        self::assertSame(
            'walking round patrol · North post',
            (string) ($document->xpath('//g:trk/g:desc')[0] ?? ''),
        );
    }

    public function testTextIsEscapedNeverInjected(): void
    {
        $xml = new GpxWriter()->write('Patrol & <P-0016>', self::LINE, [[
            'position' => '{"type":"Point","coordinates":[-30.0005,-1.0002]}',
            'name' => '1 · Sign',
            'description' => 'Note with </desc> & an ampersand.',
            'time' => null,
        ]]);

        self::assertStringNotContainsString('<P-0016>', $xml);
        $document = simplexml_load_string($xml);
        self::assertNotFalse($document);
        $document->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
        self::assertSame('Patrol & <P-0016>', (string) ($document->xpath('//g:trk/g:name')[0] ?? ''));
        self::assertSame(
            'Note with </desc> & an ampersand.',
            (string) ($document->xpath('//g:wpt/g:desc')[0] ?? ''),
        );
    }

    public function testATrackThatIsNotALineStringIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        new GpxWriter()->write('Patrol P-0016', '{"type":"Point","coordinates":[-30.0,-1.0]}');
    }
}
