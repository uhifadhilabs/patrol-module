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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Patrol\Service\PatrolMap;

/**
 * THE MODULE'S PLATES, STATED IN PHP. Patrol writes no map JavaScript: it says
 * what is on a map and the atlas draws it, with the platform's imagery, chrome,
 * legend and fullscreen.
 *
 * The geometry arrives as the text the geometry columns hold. Anything that
 * will not parse is simply not drawn — a bad track is a plate without that
 * track, never a screen that fails.
 */
final class PatrolMapTest extends TestCase
{
    private const string BOUNDARY = '{"type":"Polygon","coordinates":[[[-29.5,-3.2],[-29.4,-3.2],[-29.4,-3.1],[-29.5,-3.1],[-29.5,-3.2]]]}';
    private const string TRACK = '{"type":"LineString","coordinates":[[-29.48,-3.18],[-29.46,-3.16],[-29.44,-3.14]]}';
    private const string POINT = '{"type":"Point","coordinates":[-29.47,-3.17]}';

    public function testEachPatrolTypeIsItsOwnLayerInTheDeploymentsColour(): void
    {
        $map = self::map()->coverage(self::coveragePayload(), self::types(), self::colors());

        $layers = $map->toArray()['layers'];
        self::assertSame(
            ['patrol.tracks.foot', 'patrol.tracks.vehicle', 'patrol.endpoints', 'patrol.stations'],
            array_column($layers, 'id'),
        );
        self::assertSame('#3ED9A8', $layers[0]['swatch']);
        self::assertSame('#5FA8E0', $layers[1]['swatch']);
        self::assertSame('line', $layers[0]['shape']);
    }

    /**
     * A TYPE ROW IS A SWITCH. The map's own type filtering is the legend now,
     * so a row states the type's label and how many of its tracks are drawn.
     */
    public function testATypeRowSwitchesItsTracksAndCountsThem(): void
    {
        $row = self::legendRow(self::map()->coverage(self::coveragePayload(), self::types(), self::colors()), 'foot');

        self::assertSame('patrol.tracks.foot', $row->layerId);
        self::assertSame(2, $row->count);
        self::assertSame(PatrolMap::PATROLS_GROUP, $row->group);
    }

    /**
     * A type the deployment configured but nobody patrolled keeps its row, and
     * the row says zero: a legend that comes and goes with the data is a legend
     * nobody can read.
     */
    public function testATypeWithNoTracksKeepsItsRowAndSaysZero(): void
    {
        $map = self::map()->coverage(
            ['boundary' => self::BOUNDARY, 'patrols' => [], 'stations' => []],
            self::types(),
            self::colors(),
        );

        $row = self::legendRow($map, 'vehicle');
        self::assertSame(0, $row->count);
        self::assertFalse($row->visible);
    }

    /**
     * Every track carries the patrol it belongs to, so the plate can colour a
     * feature individually and a reader can tell one route from another.
     */
    public function testATrackFeatureCarriesItsPatrolsReferenceAndColour(): void
    {
        $features = self::features(self::map()->coverage(self::coveragePayload(), self::types(), self::colors()), 0);

        self::assertSame(
            ['ref' => 'PT-0001', 'color' => '#3ED9A8'],
            \is_array($features[0]) ? $features[0]['properties'] : null,
        );
    }

    /** The design's ● start and ○ end, as points the plate draws in the track's colour. */
    public function testEveryTrackContributesItsStartAndItsEnd(): void
    {
        $map = self::map()->coverage(self::coveragePayload(), self::types(), self::colors());

        $ends = self::features($map, 2);

        self::assertCount(6, $ends);
        self::assertSame(
            ['type' => 'Point', 'coordinates' => [-29.48, -3.18]],
            \is_array($ends[0]) ? $ends[0]['geometry'] : null,
        );
    }

    /**
     * A station has no coordinates of its own, so it is drawn where its patrols
     * set out — and it wears its name, which the plate draws as a halo label.
     */
    public function testAStationIsDrawnWhereItsPatrolsSetOutAndWearsItsName(): void
    {
        $features = self::features(self::map()->coverage(self::coveragePayload(), self::types(), self::colors()), 3);

        self::assertCount(1, $features);
        self::assertSame(['label' => 'North gate'], \is_array($features[0]) ? $features[0]['properties'] : null);
    }

    public function testTheBoundaryIsDrawnWithItsScrimAndSwitchableFromTheLegend(): void
    {
        $map = self::map()->coverage(self::coveragePayload(), self::types(), self::colors());

        $boundary = $map->toArray()['boundary'];
        self::assertIsArray($boundary);
        self::assertTrue($boundary['scrim']);
        self::assertSame(AtlasMap::BOUNDARY_LAYER_ID, self::legendRow($map, 'boundary')->layerId);
    }

    public function testATrackThatWillNotParseIsSimplyNotDrawn(): void
    {
        $map = self::map()->coverage(
            ['boundary' => 'not json', 'patrols' => [['uuid' => 'u', 'ref' => 'PT-0003', 'type' => 'foot', 'station' => '', 'zone' => '', 'color' => '#3ED9A8', 'track' => '{']], 'stations' => []],
            self::types(),
            self::colors(),
        );

        self::assertNull($map->toArray()['boundary']);
        self::assertSame([], self::features($map, 0));
    }

    /* ---- the detail plate ------------------------------------------------ */

    public function testTheDetailPlateDrawsTheTrackAndItsEnds(): void
    {
        $map = self::map()->track(['boundary' => self::BOUNDARY, 'track' => self::TRACK, 'color' => '#3ED9A8']);

        self::assertSame(['patrol.track', 'patrol.endpoints'], array_column($map->toArray()['layers'], 'id'));
        self::assertCount(2, self::features($map, 1));
    }

    /**
     * A DETAIL PLATE OPENS DEEP INSIDE THE AREA, so the scrim starts off: dimming
     * "outside" darkens imagery with no edge in frame to explain it. The control
     * is still built, so it can be switched on.
     */
    public function testTheDetailPlatesScrimStartsOff(): void
    {
        $map = self::map()->track(['boundary' => self::BOUNDARY, 'track' => self::TRACK]);

        $boundary = $map->toArray()['boundary'];
        self::assertIsArray($boundary);
        self::assertFalse($boundary['scrim']);
    }

    /**
     * An observation is a marker, not a layer feature, because it opens its own
     * page: the link rides in the window the marker opens.
     */
    public function testEveryPositionedObservationIsAMarkerThatOpensItsPage(): void
    {
        $map = self::map()->track([
            'boundary' => self::BOUNDARY,
            'track' => self::TRACK,
            'observations' => [
                ['n' => 1, 'position' => self::POINT, 'category' => 'snare', 'url' => '/patrols/x/observations/1', 'current' => false],
                ['n' => 2, 'position' => null, 'category' => 'tracks', 'url' => null, 'current' => false],
            ],
        ]);

        $markers = $map->toUxMap()->toArray()['markers'];
        self::assertIsArray($markers);
        // The observation with no fix holds its number in the list and is not drawn.
        self::assertCount(1, $markers);
        self::assertIsArray($markers[0]);
        $window = $markers[0]['infoWindow'];
        self::assertIsArray($window);
        self::assertIsString($window['content']);
        self::assertStringContainsString('/patrols/x/observations/1', $window['content']);
    }

    /**
     * ON THE OBSERVATION SCREEN THE TRACK IS CONTEXT, not the subject, so it is
     * drawn back and its ends are not drawn at all.
     */
    public function testTheObservationScreenDrawsTheTrackBack(): void
    {
        $map = self::map()->track([
            'boundary' => self::BOUNDARY,
            'track' => self::TRACK,
            'observation' => ['n' => 3, 'position' => self::POINT, 'category' => 'snare'],
        ]);

        self::assertSame(['patrol.track'], array_column($map->toArray()['layers'], 'id'));
    }

    public function testAHandLoggedPatrolIsAPlateWithNoTrack(): void
    {
        $map = self::map()->track(['boundary' => self::BOUNDARY, 'track' => null]);

        self::assertSame([], self::features($map, 0));
        self::assertNotNull($map->toArray()['boundary']);
    }

    /**
     * @return list<mixed>
     */
    private static function features(AtlasMap $map, int $index): array
    {
        $collection = $map->toArray()['layers'][$index]['features'];

        self::assertIsArray($collection);
        self::assertIsList($collection['features'] ?? null);

        return $collection['features'];
    }

    private static function legendRow(AtlasMap $map, string $label): LegendItem
    {
        foreach ($map->legend() as $item) {
            if ($item->label === $label) {
                return $item;
            }
        }

        self::fail(\sprintf('The legend has no row labelled "%s".', $label));
    }

    /**
     * @return array{boundary: string|null, patrols: list<array{uuid: string, ref: string, type: string, station: string, zone: string, color: string, track: string}>, stations: list<array{name: string, lon: float, lat: float}>}
     */
    private static function coveragePayload(): array
    {
        return [
            'boundary' => self::BOUNDARY,
            'patrols' => [
                ['uuid' => 'a', 'ref' => 'PT-0001', 'type' => 'foot', 'station' => 'North gate', 'zone' => '', 'color' => '#3ED9A8', 'track' => self::TRACK],
                ['uuid' => 'b', 'ref' => 'PT-0002', 'type' => 'foot', 'station' => 'North gate', 'zone' => '', 'color' => '#3ED9A8', 'track' => self::TRACK],
                ['uuid' => 'c', 'ref' => 'PT-0003', 'type' => 'vehicle', 'station' => '', 'zone' => '', 'color' => '#5FA8E0', 'track' => self::TRACK],
            ],
            'stations' => [['name' => 'North gate', 'lon' => -29.48, 'lat' => -3.18]],
        ];
    }

    /**
     * @return array<string, array{label: string}>
     */
    private static function types(): array
    {
        return ['foot' => ['label' => 'Foot'], 'vehicle' => ['label' => 'Vehicle']];
    }

    /**
     * @return array<string, string>
     */
    private static function colors(): array
    {
        return ['foot' => '#3ED9A8', 'vehicle' => '#5FA8E0'];
    }

    private static function map(): PatrolMap
    {
        return new PatrolMap(new MapBuilder());
    }
}
