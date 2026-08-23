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
 * Writes a GPX 1.1 document — the exact inverse of {@see GpxParser}: the stored
 * GeoJSON LineString becomes one <trk>/<trkseg> of <trkpt> in recorded order,
 * and the patrol's observations become <wpt> waypoints. What the parser reads,
 * this writes; what this writes, the parser reads back into the same points
 * (GpxWriterTest asserts that round trip).
 *
 * Honest about what is NOT stored: the bundle keeps a route geometry, not a
 * per-point stream, so a <trkpt> carries no <time> or <ele> — inventing either
 * would hand the field a fabricated recording. The recorded start goes in
 * <metadata><time>, which is the one time this document truly knows.
 *
 * Assembled with XMLWriter rather than string concatenation so every name, note
 * and label is escaped by the encoder, never by hand.
 */
final class GpxWriter
{
    public const string NAMESPACE_URI = 'http://www.topografix.com/GPX/1/1';
    public const string CREATOR = 'uhifadhilabs/patrol-module';

    private readonly GeoService $geo;

    public function __construct(?GeoService $geo = null)
    {
        $this->geo = $geo ?? new GeoService();
    }

    /**
     * @param string                                                                                       $name        the track's name ("Patrol P-0016")
     * @param string                                                                                       $lineString  the stored GeoJSON LineString
     * @param list<array{position: string, name: string, description: ?string, time: ?\DateTimeImmutable}> $waypoints   observations, each with a GeoJSON Point
     * @param ?\DateTimeImmutable                                                                          $recordedAt  when the patrol started, if known
     * @param ?string                                                                                      $description the track's <desc>, if any
     */
    public function write(
        string $name,
        string $lineString,
        array $waypoints = [],
        ?\DateTimeImmutable $recordedAt = null,
        ?string $description = null,
    ): string {
        $points = $this->geo->lineCoordinates($lineString);

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('gpx');
        $xml->writeAttribute('version', '1.1');
        $xml->writeAttribute('creator', self::CREATOR);
        $xml->writeAttribute('xmlns', self::NAMESPACE_URI);
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute(
            'xsi:schemaLocation',
            self::NAMESPACE_URI.' http://www.topografix.com/GPX/1/1/gpx.xsd',
        );

        // GPX 1.1 fixes the order of a <gpx> body: metadata, wpt*, rte*, trk*.
        $xml->startElement('metadata');
        $xml->writeElement('name', $name);
        if (null !== $description) {
            $xml->writeElement('desc', $description);
        }
        if (null !== $recordedAt) {
            $xml->writeElement('time', self::stamp($recordedAt));
        }
        $xml->endElement();

        foreach ($waypoints as $waypoint) {
            [$lon, $lat] = $this->geo->coordinates($waypoint['position']);
            $xml->startElement('wpt');
            $xml->writeAttribute('lat', self::degrees($lat));
            $xml->writeAttribute('lon', self::degrees($lon));
            // Inside a <wpt> the schema orders time before the labels.
            if (null !== $waypoint['time']) {
                $xml->writeElement('time', self::stamp($waypoint['time']));
            }
            $xml->writeElement('name', $waypoint['name']);
            if (null !== $waypoint['description'] && '' !== $waypoint['description']) {
                $xml->writeElement('desc', $waypoint['description']);
            }
            $xml->endElement();
        }

        $xml->startElement('trk');
        $xml->writeElement('name', $name);
        if (null !== $description) {
            $xml->writeElement('desc', $description);
        }
        $xml->startElement('trkseg');
        foreach ($points as [$lon, $lat]) {
            $xml->startElement('trkpt');
            $xml->writeAttribute('lat', self::degrees($lat));
            $xml->writeAttribute('lon', self::degrees($lon));
            $xml->endElement();
        }
        $xml->endElement(); // trkseg
        $xml->endElement(); // trk

        $xml->endElement(); // gpx
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /** UTC, to the second — what every GPX consumer expects in a <time>. */
    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Seven decimals — about a centimetre, well past any handheld's accuracy —
     * with no trailing zero noise and never PHP's locale-dependent float cast.
     */
    private static function degrees(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');

        return '' === $formatted || '-' === $formatted ? '0' : $formatted;
    }
}
