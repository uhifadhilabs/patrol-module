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

use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\Boundary;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerStyle;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;

/**
 * PATROL'S PLATES, STATED IN PHP.
 *
 * Two maps: the coverage map, which is every route recorded this month over the
 * area it covers, and the detail plate, which is one patrol's route with the
 * observations logged along it.
 *
 * Both are drawn by the atlas. This module holds no opinion about what
 * satellite imagery looks like, how a boundary is cased, where the zoom buttons
 * sit, what fullscreen does or how a legend is laid out; it says what is on its
 * maps and the platform draws them the one way it draws every map.
 *
 * A TYPE IS A LAYER. The coverage map used to filter its tracks in the browser
 * from the chip row; the legend is where that lives now — one layer per patrol
 * type, in the deployment's own colour for that type, each with a legend row
 * that switches it. The chip row keeps driving the log and the lists.
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/docs/components.md
 */
final readonly class PatrolMapService
{
    /** The legend heading the patrol layers sit under. */
    public const string PATROLS_GROUP = 'patrols';

    /** The heading for what the area itself contributes. */
    public const string AREA_GROUP = 'the area';

    /**
     * Fixed hexes, not theme tokens: these are drawn over imagery, which is dark
     * in both themes. The track colours are the deployment's own, from
     * {@see PatrolDashboardService::typeColors()}, so PHP and the legend cannot
     * disagree about them.
     */
    public const string OBSERVATION_SWATCH = '#DBA33F';
    public const string STATION_SWATCH = '#B9C8BD';

    /**
     * The feature property a track's hover label is read from — "ref · type",
     * composed once here so the words a reader sees on a line are the words of
     * the legend row beside it.
     *
     * NOT `label`: that property is the atlas's PERMANENT halo, and a hundred
     * routes each wearing one is a field of text with a map somewhere behind it.
     * This is stated as the layer's `tooltip`, which the plate reads on hover
     * and nowhere else.
     */
    public const string TOOLTIP_PROPERTY = 'tip';

    /** The covered-ground layer's id, and what its legend row switches. */
    public const string COVERAGE_LAYER = 'patrol.coverage';

    /** What that row says; the distance is PL·03's own, in the KPI's words. */
    public const string COVERAGE_LABEL = '2 km coverage buffer';

    /** The quiet green the covered ground is drawn in — the atlas's own line colour. */
    public const string COVERAGE_SWATCH = '#3ED9A8';

    /**
     * The pane the covered ground is drawn in. Leaflet's overlay pane is 400, so
     * a lower number puts this beneath every route, endpoint and station on the
     * plate — whatever order the layers were added in, and however many layers a
     * deployment's types add above it. Which is what "the ground those routes
     * covered" has to look like: under them, never over them.
     *
     * @see https://leafletjs.com/reference.html#map-pane
     */
    public const int COVERAGE_Z_INDEX = 390;

    /** The fallback for a patrol whose type the deployment has since dropped. */
    private const string DEFAULT_SWATCH = '#3ED9A8';

    public function __construct(
        private MapBuilderInterface $maps,
    ) {
    }

    /**
     * The coverage map: the area, then every route recorded in the window,
     * grouped by the type it was patrolled as.
     *
     * @param array{boundary: string|null, patrols: list<array{uuid: string, ref: string, type: string, station: string, zone: string, color: string, track: string}>, stations: list<array{name: string, lon: float, lat: float}>} $payload
     * @param array<string, array{label: string}>                                                                                                                                                                                   $types
     * @param array<string, string>                                                                                                                                                                                                 $typeColor
     * @param string|null                                                                                                                                                                                                           $coverage  the covered ground as GeoJSON text, from {@see \Uhifadhi\Patrol\Repository\PatrolRepository::coverageBufferGeoJson()}; null where the month recorded no track
     */
    public function coverage(array $payload, array $types, array $typeColor, ?string $coverage = null): AtlasMap
    {
        $map = $this->maps->createMap();
        $this->drawBoundary($map, $payload['boundary'], scrim: true);
        $this->drawCoverage($map, $coverage);

        // Grouped before anything is drawn, so a type the deployment configured
        // but nobody patrolled still reaches the legend and still says zero.
        $byType = array_fill_keys(array_keys($types), []);
        $ends = [];
        foreach ($payload['patrols'] as $patrol) {
            $geometry = self::decode($patrol['track']);
            if (null === $geometry) {
                continue;
            }

            $byType[$patrol['type']][] = self::feature($geometry, [
                'ref' => $patrol['ref'],
                'color' => $patrol['color'],
                // What a hover says, composed here so the line and the legend
                // row use the same word for the same type.
                self::TOOLTIP_PROPERTY => \sprintf('%s · %s', $patrol['ref'], mb_strtolower($types[$patrol['type']]['label'] ?? $patrol['type'])),
            ]);

            foreach (self::endpoints($geometry) as $end) {
                $ends[] = self::feature(['type' => 'Point', 'coordinates' => $end], ['color' => $patrol['color']]);
            }
        }

        foreach ($byType as $key => $features) {
            $map->addLayer(new GeoJsonLayer(
                id: 'patrol.tracks.'.$key,
                label: mb_strtolower($types[$key]['label'] ?? $key),
                features: self::collection($features),
                swatch: $typeColor[$key] ?? self::DEFAULT_SWATCH,
                shape: LayerShape::Line,
                visible: [] !== $features,
                count: \count($features),
                group: self::PATROLS_GROUP,
                // A hover names the route; a row in the log beside the map
                // spotlights it by the same reference the row prints.
                tooltip: self::TOOLTIP_PROPERTY,
                featureId: 'ref',
            ));
        }

        $map->addLayer(new GeoJsonLayer(
            id: 'patrol.endpoints',
            label: 'start & end',
            features: self::collection($ends),
            swatch: self::DEFAULT_SWATCH,
            shape: LayerShape::Point,
            visible: [] !== $ends,
            group: self::PATROLS_GROUP,
        ));

        $stations = [];
        foreach ($payload['stations'] as $station) {
            $stations[] = self::feature(
                ['type' => 'Point', 'coordinates' => [$station['lon'], $station['lat']]],
                ['label' => $station['name']],
            );
        }

        $map->addLayer(new GeoJsonLayer(
            id: 'patrol.stations',
            label: 'stations',
            features: self::collection($stations),
            swatch: self::STATION_SWATCH,
            shape: LayerShape::Point,
            visible: [] !== $stations,
            count: \count($stations),
            group: self::AREA_GROUP,
        ));

        return $map;
    }

    /**
     * A detail plate: one route, the observations logged along it, and the area
     * underneath as context.
     *
     * One `observation` in the payload means the screen is about that
     * observation rather than about the patrol: the route becomes context, so it
     * is drawn without its ends.
     *
     * @param array{
     *     boundary: string|null,
     *     track?: string|null,
     *     color?: string|null,
     *     observation?: array{n: int, position: string|null, category?: string|null}|null,
     *     observations?: list<array{n: int, position: string|null, category?: string|null, url?: string|null, current?: bool}>,
     * } $payload
     */
    public function track(array $payload): AtlasMap
    {
        $map = $this->maps->createMap();

        // A detail plate opens deep inside the area, where dimming "outside"
        // darkens imagery with no edge in frame to explain it. The control is
        // built either way, so it can still be switched on.
        $this->drawBoundary($map, $payload['boundary'], scrim: false);

        $single = $payload['observation'] ?? null;
        $colour = $payload['color'] ?? self::DEFAULT_SWATCH;
        $geometry = self::decode($payload['track'] ?? null);

        $map->addLayer(new GeoJsonLayer(
            id: 'patrol.track',
            label: 'track',
            features: self::collection(null === $geometry ? [] : [self::feature($geometry)]),
            swatch: $colour,
            shape: LayerShape::Line,
            visible: null !== $geometry,
            group: self::PATROLS_GROUP,
        ));

        if (null === $single && null !== $geometry) {
            $ends = [];
            foreach (self::endpoints($geometry) as $end) {
                $ends[] = self::feature(['type' => 'Point', 'coordinates' => $end]);
            }

            $map->addLayer(new GeoJsonLayer(
                id: 'patrol.endpoints',
                label: 'start & end',
                features: self::collection($ends),
                swatch: $colour,
                shape: LayerShape::Point,
                group: self::PATROLS_GROUP,
            ));
        }

        $this->drawObservations($map, $payload, $single);

        return $map;
    }

    /**
     * The observations, as MARKERS rather than as a layer's features: each one
     * opens its own page, and a marker is the one element that carries a window
     * with a link in it.
     *
     * An observation recorded without a fix holds its number in the list beside
     * the map and is not drawn — an invented position would be worse than none.
     *
     * @param array<string, mixed>                                              $payload
     * @param array{n: int, position: string|null, category?: string|null}|null $single
     */
    private function drawObservations(AtlasMap $map, array $payload, ?array $single): void
    {
        $observations = $payload['observations'] ?? [];
        if (!\is_array($observations) || [] === $observations) {
            $observations = null !== $single ? [$single + ['current' => true, 'url' => null]] : [];
        }

        $drawn = 0;
        foreach ($observations as $observation) {
            if (!\is_array($observation)) {
                continue;
            }
            $position = self::decode(\is_string($observation['position'] ?? null) ? $observation['position'] : null);
            if (null === $position || 'Point' !== ($position['type'] ?? null) || !\is_array($position['coordinates'] ?? null)) {
                continue;
            }

            $number = is_numeric($observation['n'] ?? null) ? (int) $observation['n'] : 0;
            $category = \is_string($observation['category'] ?? null) ? $observation['category'] : null;
            $url = \is_string($observation['url'] ?? null) ? $observation['url'] : null;
            // On the patrol screen every ring is the subject; on the observation
            // screen only the one being read is, and its siblings are drawn back.
            $current = null === $single || true === ($observation['current'] ?? false);

            $map->ux()->addMarker(new Marker(
                position: new Point(self::coordinate($position['coordinates'][1] ?? null), self::coordinate($position['coordinates'][0] ?? null)),
                title: null !== $category ? \sprintf('obs %d · %s', $number, $category) : \sprintf('obs %d', $number),
                infoWindow: null === $url ? null : new InfoWindow(
                    headerContent: \sprintf('obs %d', $number),
                    content: \sprintf('<a href="%s">Open the observation &rarr;</a>', htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')),
                ),
                icon: self::ring($number, $current),
            ));
            ++$drawn;
        }

        if ($drawn > 0) {
            $map->addLegendItem(new LegendItem(
                label: 'observations',
                swatch: self::OBSERVATION_SWATCH,
                shape: LayerShape::Point,
                group: self::PATROLS_GROUP,
                count: $drawn,
            ));
        }
    }

    /**
     * The dashed amber ring with the observation's number in it. A sibling on
     * the observation screen is drawn back: present and reachable, plainly not
     * the one being read.
     */
    private static function ring(int $number, bool $current): Icon
    {
        $size = $current ? 22 : 16;

        return Icon::svg(\sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 22 22" opacity="%2$s">'
            .'<circle cx="11" cy="11" r="8" fill="rgba(10,14,11,.55)" stroke="%3$s" stroke-width="1.6" stroke-dasharray="3 2"/>'
            .'<text x="11" y="14" text-anchor="middle" font-family="monospace" font-size="9" font-weight="700" fill="%3$s">%4$d</text>'
            .'</svg>',
            $size,
            $current ? '1' : '.55',
            self::OBSERVATION_SWATCH,
            $number,
        ));
    }

    /**
     * THE GROUND THE MONTH'S ROUTES COVERED — PL·03's number, drawn.
     *
     * The KPI states the share of the area within 2 km of a track; this is the
     * very same set operation as a shape, so a reader can see WHERE that share
     * is instead of only how large it was. The map-legend contract is why it is
     * a real layer with a real row: a legend entry with nothing behind it is a
     * legend nobody can rely on.
     *
     * It is the MONTH's coverage, never the filtered view's, exactly as the KPI
     * beside it is — the shape on the plate and the number in the strip must be
     * the same measurement or one of them is lying.
     *
     * A month that recorded no track still gets the layer and still gets the
     * row, holding nothing: that is how "no coverage recorded" is said without
     * it being mistaken for "not measured".
     */
    private function drawCoverage(AtlasMap $map, ?string $coverage): void
    {
        $geometry = self::decode($coverage);

        $map->addLayer(new GeoJsonLayer(
            id: self::COVERAGE_LAYER,
            label: self::COVERAGE_LABEL,
            features: self::collection(null === $geometry ? [] : [self::feature($geometry)]),
            swatch: self::COVERAGE_SWATCH,
            shape: LayerShape::Fill,
            visible: null !== $geometry,
            // A count would be the number of polygons the union happened to
            // come out as, which says nothing about coverage.
            group: self::PATROLS_GROUP,
            style: new LayerStyle(weight: 0.0, fillOpacity: 0.16, zIndex: self::COVERAGE_Z_INDEX),
        ));
    }

    private function drawBoundary(AtlasMap $map, ?string $boundary, bool $scrim): void
    {
        $geometry = self::decode($boundary);
        if (null === $geometry) {
            return;
        }

        $map->boundary(new Boundary($geometry, $scrim));
        $map->addLegendItem(new LegendItem(
            label: 'boundary',
            swatch: self::DEFAULT_SWATCH,
            shape: LayerShape::Line,
            group: self::AREA_GROUP,
            layerId: AtlasMap::BOUNDARY_LAYER_ID,
        ));
    }

    /**
     * The first and last vertex of a (Multi)LineString — the design's ● start
     * and ○ end. Empty for anything that is not a line.
     *
     * @param array<string, mixed> $geometry
     *
     * @return list<list<float>>
     */
    private static function endpoints(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            return [];
        }

        $line = match ($geometry['type'] ?? null) {
            'LineString' => $coordinates,
            // A multi-line's vertices are one route in several pieces: the first
            // vertex of the first piece and the last of the last are its ends.
            'MultiLineString' => array_merge(...array_values(array_filter($coordinates, is_array(...)))),
            default => [],
        };

        if ([] === $line) {
            return [];
        }

        $first = reset($line);
        $last = end($line);

        return \is_array($first) && \is_array($last)
            ? [self::position($first), self::position($last)]
            : [];
    }

    /**
     * @param array<array-key, mixed> $vertex
     *
     * @return list<float>
     */
    private static function position(array $vertex): array
    {
        return array_map(self::coordinate(...), array_values($vertex));
    }

    private static function coordinate(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string, mixed> $geometry
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function feature(array $geometry, array $properties = []): array
    {
        return ['type' => 'Feature', 'properties' => $properties, 'geometry' => $geometry];
    }

    /**
     * @param list<array<string, mixed>> $features
     *
     * @return array<string, mixed>
     */
    private static function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $geoJson): ?array
    {
        if (null === $geoJson || '' === $geoJson) {
            return null;
        }

        try {
            $decoded = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_string($decoded['type'] ?? null)) {
            return null;
        }

        $geometry = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $geometry[$key] = $value;
            }
        }

        return $geometry;
    }
}
