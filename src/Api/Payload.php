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

namespace UhifadhiLabs\Patrol\Api;

use Symfony\Component\Uid\Uuid;

/**
 * Reads the field app's JSON without trusting a single field of it.
 *
 * Every accessor states what it wants and refuses anything else with an error
 * the app has a rule for. That is the point: a phone that has been offline for
 * a week may be running a build nobody here remembers, and the alternative to
 * checking is storing `null` where a coordinate should be and discovering it
 * months later on a map.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class Payload
{
    /** Latitude bounds. Outside these it is not a place on Earth. */
    private const float LAT_MIN = -90.0;
    private const float LAT_MAX = 90.0;
    private const float LON_MIN = -180.0;
    private const float LON_MAX = 180.0;

    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws PatrolApiException
     */
    public static function requiredString(array $data, string $key): string
    {
        return self::string($data, $key)
            ?? throw PatrolApiException::invalidPayload(\sprintf('"%s" is required.', $key), ['field' => $key]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A client-generated UUID. Rejected loudly when malformed: the UUID IS the
     * idempotency key, so an unparseable one means the phone and the server can
     * never agree on whether this record already exists.
     *
     * @param array<string, mixed> $data
     *
     * @throws PatrolApiException
     */
    public static function uuid(array $data, string $key): Uuid
    {
        $raw = self::requiredString($data, $key);

        if (!Uuid::isValid($raw)) {
            throw PatrolApiException::invalidPayload(\sprintf('"%s" is not a valid UUID.', $key), ['field' => $key, 'value' => $raw]);
        }

        return Uuid::fromString($raw);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws PatrolApiException
     */
    public static function optionalUuid(array $data, string $key): ?Uuid
    {
        return null === self::string($data, $key) ? null : self::uuid($data, $key);
    }

    /**
     * A client timestamp, kept exactly as the phone meant it. The handset may
     * have been offline for hours and the contract (§1) forbids substituting
     * our receive time, so this only normalises the zone to UTC.
     *
     * @param array<string, mixed> $data
     *
     * @throws PatrolApiException
     */
    public static function timestamp(array $data, string $key): ?\DateTimeImmutable
    {
        $raw = self::string($data, $key);

        if (null === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw PatrolApiException::invalidPayload(\sprintf('"%s" is not an ISO-8601 timestamp.', $key), ['field' => $key, 'value' => $raw]);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws PatrolApiException
     */
    public static function requiredTimestamp(array $data, string $key): \DateTimeImmutable
    {
        return self::timestamp($data, $key)
            ?? throw PatrolApiException::invalidPayload(\sprintf('"%s" is required.', $key), ['field' => $key]);
    }

    /**
     * A list of objects under $key — the shape every batch part uses.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (\is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * A list of plain strings under $key (a patrol's `team`, say).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * A `{lat, lon}` pair as GeoJSON Point TEXT — lon first, which is RFC 7946's
     * order and PostGIS's, and the single most common way to store a map upside
     * down. The bounds check is not paranoia: a swapped pair is usually still
     * numerically valid, and the only ones this can catch are the ones where
     * latitude landed outside ±90.
     *
     * @param array<string, mixed> $data the object CONTAINING lat/lon
     *
     * @throws PatrolApiException
     */
    public static function geoJsonPoint(array $data): string
    {
        $lat = self::float($data, 'lat');
        $lon = self::float($data, 'lon');

        if (null === $lat || null === $lon) {
            throw PatrolApiException::invalidGeometry('A position needs both "lat" and "lon".');
        }

        if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lon < self::LON_MIN || $lon > self::LON_MAX) {
            throw PatrolApiException::invalidGeometry('That position is not on Earth.', ['lat' => $lat, 'lon' => $lon]);
        }

        return json_encode(['type' => 'Point', 'coordinates' => [$lon, $lat]], \JSON_THROW_ON_ERROR);
    }

    /**
     * A GeoJSON Polygon passed straight through from the phone (a drone
     * sector). Only the envelope is checked — type and a coordinates array —
     * because re-deriving the ring here would be re-implementing PostGIS badly;
     * the geometry column is what ultimately refuses nonsense.
     *
     * @param array<string, mixed> $data
     *
     * @throws PatrolApiException
     */
    public static function geoJsonPolygon(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            return null;
        }

        $type = $value['type'] ?? null;
        $coordinates = $value['coordinates'] ?? null;

        if ('Polygon' !== $type || !\is_array($coordinates) || [] === $coordinates) {
            throw PatrolApiException::invalidGeometry(\sprintf('"%s" must be a GeoJSON Polygon.', $key), ['field' => $key]);
        }

        return json_encode(['type' => 'Polygon', 'coordinates' => $coordinates], \JSON_THROW_ON_ERROR);
    }
}
