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

namespace Uhifadhi\Patrol\Command;

use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Service\GeoService;

/**
 * Fills one area with invented-but-plausible patrol history, so a fresh install
 * shows a living dashboard instead of empty widgets — no hand-written SQL.
 *
 * Everything here is FICTION: made-up ranger names, generic station names,
 * invented routes. It never names a deployment, and it is dev tooling
 * (patrol.dev_tools), never registered in production.
 *
 * The history is spatially realistic, because a coverage map of patrols all
 * looping around one centroid is a lie about how patrolling works:
 *
 *  - PostGIS samples the area itself — a square grid laid over the boundary,
 *    one interior point per clipped cell (ST_SquareGrid + ST_PointOnSurface),
 *    falling back to seeded ST_GeneratePoints for shapes too small to grid.
 *  - Stations are chosen from those samples by farthest-point sampling, so the
 *    five fictional posts sit genuinely far apart; every recorded patrol STARTS
 *    at its own station's position, so a station name on a row means a place.
 *  - Each patrol type moves the way that type moves: foot patrols make short
 *    meandering loops home to their station, vehicle patrols run long smooth
 *    routes between stations, drones transit out and sweep a distant sector in
 *    straight lawnmower legs. Durations follow the profile's speed.
 *  - Steps that would leave the area are refused: the route turns instead, so
 *    tracks stay inside the boundary (with a margin kept clear of the edge).
 *
 * Two product rules hold even for demo data:
 *  - the area is never guessed — --area is required, and an unknown uuid fails;
 *  - a hand-entered (Manual) patrol carries NO track, point count or gap count,
 *    so a sketch can never be read as a recorded one ({@see PatrolSourceEnum}).
 */
#[AsCommand(
    name: 'patrol:seed:demo',
    description: 'Seed one area with fictional demo patrols, tracks and observations (dev only).',
)]
final class SeedDemoCommand extends Command
{
    private const int DEFAULT_PATROLS = 12;
    private const int SPREAD_DAYS = 35;
    private const int MIN_TRACK_POINTS = 30;
    private const int MAX_TRACK_POINTS = 120;

    /** Fixed seed: the same options always produce the same demo history. */
    private const int RANDOM_SEED = 20260823;

    /** Cells per side of the sampling grid laid over the area's bounding box. */
    private const int GRID_DIVISIONS = 6;

    /** How many seeded points the ST_GeneratePoints fallback scatters. */
    private const int FALLBACK_SAMPLES = 24;

    /** Fewer usable grid cells than this and the fallback sampler takes over. */
    private const int MIN_SAMPLES = 4;

    /** Kept clear of the boundary, as a fraction of the area's narrow side. */
    private const float INTERIOR_MARGIN = 0.05;

    /** Used when the area has no boundary geometry to sample. */
    private const float FALLBACK_LON = 0.0;
    private const float FALLBACK_LAT = 0.0;
    private const float FALLBACK_SPREAD_DEG = 0.08;

    /** Kilometres in a degree of latitude — good enough for demo dead reckoning. */
    private const float KM_PER_DEGREE = 111.195;

    /**
     * Turns tried, in radians, when a step would leave the area.
     *
     * @var list<float>
     */
    private const array TURNS = [\M_PI_2, -\M_PI_2, \M_PI, 2.356194, -2.356194];

    /**
     * The interior geometry the demo is allowed to use: the boundary eroded by
     * INTERIOR_MARGIN of its narrow side, so tracks keep clear of the edge. An
     * area too narrow to erode keeps its own outline. Every sampling query
     * below builds on this common table expression.
     */
    private const string INTERIOR_CTE = <<<'SQL'
        WITH raw AS (
            SELECT ST_MakeValid(geom) AS geom FROM area_of_interest WHERE id = :id AND geom IS NOT NULL
        ), sized AS (
            SELECT geom,
                   GREATEST(ST_XMax(geom) - ST_XMin(geom), ST_YMax(geom) - ST_YMin(geom)) AS span,
                   LEAST(ST_XMax(geom) - ST_XMin(geom), ST_YMax(geom) - ST_YMin(geom)) AS narrow
            FROM raw
        ), interior AS (
            SELECT CASE
                       WHEN ST_IsEmpty(ST_Buffer(geom, -narrow * %1$F)) THEN geom
                       ELSE ST_Buffer(geom, -narrow * %1$F)
                   END AS geom,
                   span
            FROM sized
        )
        SQL;

    /** @var list<string> */
    private const array PROFILES = ['foot', 'vehicle', 'drone'];

    /**
     * How a deployment's own type vocabulary maps onto a movement profile: the
     * words are host config ("walk", "boat", "ndege"…), the physics are not.
     *
     * @var array<string, list<string>>
     */
    private const array PROFILE_KEYWORDS = [
        'foot' => ['foot', 'walk', 'hike', 'ground', 'ranger'],
        'vehicle' => ['vehicle', 'car', 'truck', 'bike', 'moto', 'horse', 'boat', 'vessel', 'river', 'lake'],
        'drone' => ['drone', 'uav', 'air', 'aerial', 'flight', 'plane', 'fly'],
    ];

    /** @var list<string> */
    private const array STATIONS = ['North Gate', 'River Post', 'South Gate', 'Ridge Camp', 'Lake Post'];

    /** @var list<string> */
    private const array RANGERS = [
        'Neema Kileo', 'Baraka Mushi', 'Asha Ndosi', 'Juma Wema',
        'Zawadi Massawe', 'Hamisi Lyimo', 'Rehema Sway', 'Tumaini Kessy',
    ];

    /** @var list<string> */
    private const array OBSERVATION_NOTES = [
        'Fresh tracks crossing the path, heading west.',
        'Boundary marker leaning, needs resetting.',
        'Cut branches piled beside the trail.',
        'Group of grazers moving along the ridge.',
        'Culvert blocked after the rains.',
        'Old fire scar, no active burning.',
        'Snare wire found and removed.',
        'Water point dry, livestock signs around it.',
    ];

    /** @var list<string> */
    private const array PATROL_NOTES = [
        'Routine round, nothing out of place.',
        'Quiet shift; radio check on the hour.',
        'Slow going after the rain.',
        'Met the neighbouring team at the boundary.',
    ];

    /**
     * The area's rings (outer and holes alike), as sampled for this run. A step
     * is inside when it crosses an odd number of them — so containment costs no
     * database round trip per vertex.
     *
     * @var list<list<array{0: float, 1: float}>>
     */
    private array $rings = [];

    /** @var non-empty-list<array{0: float, 1: float}> */
    private array $samples = [[self::FALLBACK_LON, self::FALLBACK_LAT]];

    /** @var non-empty-list<array{name: string, lon: float, lat: float}> */
    private array $stations = [['name' => self::STATIONS[0], 'lon' => self::FALLBACK_LON, 'lat' => self::FALLBACK_LAT]];

    /**
     * @param array<string, array{label: string}> $types      the deployment's patrol.types
     * @param array<string, array{label: string}> $categories the deployment's patrol.observation_categories
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PatrolRepository $patrols,
        private readonly GeoService $geo,
        private readonly array $types,
        private readonly array $categories,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Uuid of the area to seed (required — never guessed)')
            ->addOption('patrols', null, InputOption::VALUE_REQUIRED, 'How many patrols to invent', (string) self::DEFAULT_PATROLS)
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Delete the area\'s existing patrols first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $areaUuid = $input->getOption('area');
        if (!\is_string($areaUuid) || '' === $areaUuid || !Uuid::isValid($areaUuid)) {
            $io->error('The --area option is required and must be an area uuid.');

            return Command::FAILURE;
        }

        $area = $this->em->getRepository(AreaOfInterest::class)
            ->findOneBy(['uuid' => Uuid::fromString($areaUuid)]);
        if (!$area instanceof AreaOfInterest) {
            $io->error(\sprintf('No area with uuid %s.', $areaUuid));

            return Command::FAILURE;
        }

        $wanted = $input->getOption('patrols');
        $count = is_numeric($wanted) ? (int) $wanted : self::DEFAULT_PATROLS;
        if ($count < 1) {
            $io->error('--patrols must be at least 1.');

            return Command::FAILURE;
        }

        $existing = $this->patrols->count(['area' => $area]);
        if (true === $input->getOption('fresh')) {
            $this->deleteExisting($area);
            $io->note(\sprintf('Deleted %d existing patrol(s) for this area.', $existing));
        } elseif ($existing > 0) {
            $io->note(\sprintf(
                '%s already has %d patrol(s) — nothing seeded. Pass --fresh to replace them.',
                $area->getName() ?? $areaUuid,
                $existing,
            ));

            return Command::SUCCESS;
        }

        $this->rings = $this->boundaryRings($area);
        $this->samples = $this->samplePoints($area);
        $this->stations = $this->chooseStations($this->samples);

        $randomizer = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $now = new \DateTimeImmutable();

        $recorded = 0;
        $sketched = 0;
        $observations = 0;
        for ($i = 0; $i < $count; ++$i) {
            // Every fourth patrol is hand-entered: real rosters are never all GPS.
            if (3 === $i % 4) {
                $this->sketchedPatrol($area, $randomizer, $now, $sketched);
                ++$sketched;
                continue;
            }
            $observations += $this->recordedPatrol($area, $randomizer, $now, $recorded);
            ++$recorded;
        }
        $this->em->flush();

        $io->success(\sprintf(
            'Seeded %d patrols (%d recorded, %d hand-entered) with %d observations for "%s", spread over the last %d days.',
            $count,
            $recorded,
            $sketched,
            $observations,
            $area->getName() ?? $areaUuid,
            self::SPREAD_DAYS,
        ));

        $io->table(
            ['Station', 'Position'],
            array_map(
                fn (array $station): array => [$station['name'], $this->geo->formatDms($station['lon'], $station['lat'])],
                $this->stations,
            ),
        );

        return Command::SUCCESS;
    }

    /**
     * One recorded patrol, starting at its own station. Returns the number of
     * observations logged en route on it.
     */
    private function recordedPatrol(
        AreaOfInterest $area,
        Randomizer $randomizer,
        \DateTimeImmutable $now,
        int $index,
    ): int {
        // Round robin over the stations: every post gets its own patrols, so the
        // map shows effort across the area rather than one busy corner.
        $station = $this->stations[$index % \count($this->stations)];
        $type = $this->pick($randomizer, $this->typeKeys());
        $profile = $this->profileFor($type);

        $points = match ($profile) {
            'vehicle' => $this->vehicleRoute($randomizer, $station),
            'drone' => $this->droneSweep($randomizer, $station),
            default => $this->footLoop($randomizer, $station),
        };
        $distanceKm = $this->trackDistanceKm($points);

        $startedAt = $this->startOfDuty($randomizer, $now);
        $speedKmh = match ($profile) {
            'vehicle' => $randomizer->getFloat(20.0, 30.0),
            'drone' => $randomizer->getFloat(40.0, 60.0),
            default => $randomizer->getFloat(3.5, 4.8),
        };
        $endedAt = $startedAt->modify(\sprintf('+%d seconds', max(600, (int) round($distanceKm / $speedKmh * 3600))));

        $patrol = new Patrol($area, $type)
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStation($station['name'])
            ->setTeam($this->team($randomizer))
            ->setNote(0 === $randomizer->getInt(0, 2) ? $this->pick($randomizer, self::PATROL_NOTES) : null)
            ->setStartedAt($startedAt)
            ->setEndedAt($endedAt)
            ->setDistanceKm(round($distanceKm, 2))
            ->setTrack((string) json_encode(['type' => 'LineString', 'coordinates' => $points], \JSON_THROW_ON_ERROR))
            ->setPointCount(\count($points))
            ->setGapCount($randomizer->getInt(0, 5) > 3 ? $randomizer->getInt(1, 2) : 0);
        $this->em->persist($patrol);

        $span = max(1, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        $wanted = $randomizer->getInt(0, 4);
        for ($n = 0; $n < $wanted; ++$n) {
            $at = $randomizer->getInt(0, \count($points) - 1);
            $observation = new Observation($patrol, $this->pick($randomizer, $this->categoryKeys()))
                ->setNote($this->pick($randomizer, self::OBSERVATION_NOTES))
                ->setPosition((string) json_encode(['type' => 'Point', 'coordinates' => $points[$at]], \JSON_THROW_ON_ERROR))
                ->setLoggedAt($startedAt->modify(\sprintf('+%d seconds', (int) round($span * $at / max(1, \count($points) - 1)))));
            $this->em->persist($observation);
        }

        return $wanted;
    }

    /**
     * A patrol written up from the duty log: distance and times are the team's
     * own estimate and there is NO geometry — a sketch never carries a track,
     * point count or gap count.
     */
    private function sketchedPatrol(
        AreaOfInterest $area,
        Randomizer $randomizer,
        \DateTimeImmutable $now,
        int $index,
    ): void {
        $startedAt = $this->startOfDuty($randomizer, $now);
        $patrol = new Patrol($area, $this->pick($randomizer, $this->typeKeys()))
            ->setSource(PatrolSourceEnum::Manual)
            ->setStation($this->stations[$index % \count($this->stations)]['name'])
            ->setTeam($this->team($randomizer))
            ->setNote('Written up from the duty log — route not recorded.')
            ->setStartedAt($startedAt)
            ->setEndedAt($startedAt->modify(\sprintf('+%d minutes', $randomizer->getInt(70, 260))))
            ->setDistanceKm(round($randomizer->getFloat(2.0, 11.0), 1));
        $this->em->persist($patrol);
    }

    /**
     * Foot patrol: a short meandering round near its station that turns for home
     * halfway through, so it starts and ends at the post.
     *
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function footLoop(Randomizer $randomizer, array $station): array
    {
        $points = $this->clampPoints($randomizer->getInt(40, 110));
        $stepKm = $randomizer->getFloat(4.0, 12.0) / ($points - 1);
        $heading = $randomizer->getFloat(0.0, 2 * \M_PI);
        $lon = $station['lon'];
        $lat = $station['lat'];

        $track = [[round($lon, 6), round($lat, 6)]];
        for ($i = 1; $i < $points; ++$i) {
            $heading += $randomizer->getFloat(-0.55, 0.55);
            if ($i > intdiv($points, 2)) {
                // Homeward half: lean on the bearing back to the post.
                $heading = $this->towards($heading, $this->bearing($lon, $lat, $station['lon'], $station['lat']), 0.3);
            }
            [$lon, $lat, $heading] = $this->stepInside($lon, $lat, $heading, $stepKm);
            $track[] = [round($lon, 6), round($lat, 6)];
        }

        return $track;
    }

    /**
     * Vehicle patrol: a long, smooth run that leaves its station for another
     * post, so vehicle effort links the area up instead of circling one place.
     *
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function vehicleRoute(Randomizer $randomizer, array $station): array
    {
        $points = $this->clampPoints($randomizer->getInt(60, 120));
        $stepKm = $randomizer->getFloat(20.0, 60.0) / ($points - 1);
        $lon = $station['lon'];
        $lat = $station['lat'];
        $destination = $this->otherStation($randomizer, $station);
        $heading = $this->bearing($lon, $lat, $destination['lon'], $destination['lat']);

        $track = [[round($lon, 6), round($lat, 6)]];
        for ($i = 1; $i < $points; ++$i) {
            if ($this->geo->haversineKm($lat, $lon, $destination['lat'], $destination['lon']) < 2 * $stepKm) {
                // Arrived: carry on to the next post rather than stopping short.
                $destination = $this->otherStation($randomizer, $destination);
            }
            // Enough drift to follow a road, enough pull to arrive: a vehicle
            // route bends, it does not wander like a foot round.
            $heading = $this->towards($heading, $this->bearing($lon, $lat, $destination['lon'], $destination['lat']), 0.14)
                + $randomizer->getFloat(-0.3, 0.3);
            [$lon, $lat, $heading] = $this->stepInside($lon, $lat, $heading, $stepKm);
            $track[] = [round($lon, 6), round($lat, 6)];
        }

        return $track;
    }

    /**
     * Drone flight: a straight transit from the launch station out to a sector,
     * then a lawnmower sweep of it — fast, straight lines, often far from any
     * post.
     *
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function droneSweep(Randomizer $randomizer, array $station): array
    {
        $points = $this->clampPoints($randomizer->getInt(30, 90));
        $stepKm = $randomizer->getFloat(12.0, 35.0) / ($points - 1);
        $lon = $station['lon'];
        $lat = $station['lat'];
        $sector = $this->samples[$randomizer->getInt(0, \count($this->samples) - 1)];
        $heading = $this->bearing($lon, $lat, $sector[0], $sector[1]);
        $transit = intdiv($points, 3);
        $legSteps = max(3, intdiv($points - $transit, 5));
        $sweep = $heading + \M_PI_2;

        $track = [[round($lon, 6), round($lat, 6)]];
        for ($i = 1; $i < $points; ++$i) {
            if ($i <= $transit) {
                $heading = $this->bearing($lon, $lat, $sector[0], $sector[1]);
            } else {
                // Legs there and back, with one short shift onto the next line.
                $step = ($i - $transit - 1) % ($legSteps + 1);
                $leg = intdiv($i - $transit - 1, $legSteps + 1);
                $heading = $step === $legSteps
                    ? $sweep + \M_PI_2
                    : $sweep + (0 === $leg % 2 ? 0.0 : \M_PI);
            }
            [$lon, $lat, $heading] = $this->stepInside($lon, $lat, $heading, $stepKm);
            $track[] = [round($lon, 6), round($lat, 6)];
        }

        return $track;
    }

    /**
     * One step along a heading — refused if it would leave the area: the route
     * turns (a quarter, a half, three quarters) until it finds a way that stays
     * in, and holds position if nothing does.
     *
     * @return array{0: float, 1: float, 2: float} lon, lat and the heading taken
     */
    private function stepInside(float $lon, float $lat, float $heading, float $stepKm): array
    {
        foreach ([0.0, ...self::TURNS] as $turn) {
            $candidate = $heading + $turn;
            $nextLat = $lat + $stepKm / self::KM_PER_DEGREE * cos($candidate);
            $nextLon = $lon + $stepKm / (self::KM_PER_DEGREE * max(0.2, cos(deg2rad($lat)))) * sin($candidate);
            if ($this->inside($nextLon, $nextLat)) {
                return [$nextLon, $nextLat, $candidate];
            }
        }

        return [$lon, $lat, $heading + \M_PI];
    }

    /** Nudge a heading towards another one the short way round. */
    private function towards(float $heading, float $target, float $weight): float
    {
        return $heading + $weight * atan2(sin($target - $heading), cos($target - $heading));
    }

    /** Compass bearing in radians (0 = north), on the flat — demo scale. */
    private function bearing(float $lon, float $lat, float $toLon, float $toLat): float
    {
        return atan2(($toLon - $lon) * max(0.2, cos(deg2rad($lat))), $toLat - $lat);
    }

    /**
     * Even-odd ray casting against the sampled rings. Holes are rings too, so
     * an odd number of crossings means inside the land, outside the holes.
     */
    private function inside(float $lon, float $lat): bool
    {
        if ([] === $this->rings) {
            return true;
        }

        $crossings = 0;
        foreach ($this->rings as $ring) {
            for ($i = 0, $n = \count($ring), $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $ring[$i];
                [$xj, $yj] = $ring[$j];
                if (($yi > $lat) !== ($yj > $lat)
                    && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi
                ) {
                    ++$crossings;
                }
            }
        }

        return 1 === $crossings % 2;
    }

    /**
     * The fictional posts, pinned to well-separated sample points by greedy
     * farthest-point sampling: take the first point, then always the candidate
     * furthest from everything chosen so far.
     *
     * @param non-empty-list<array{0: float, 1: float}> $samples
     *
     * @return non-empty-list<array{name: string, lon: float, lat: float}>
     */
    private function chooseStations(array $samples): array
    {
        $chosen = [$samples[0]];
        $rest = \array_slice($samples, 1);
        while (\count($chosen) < \count(self::STATIONS) && [] !== $rest) {
            $bestIndex = 0;
            $bestDistance = -1.0;
            foreach ($rest as $index => [$lon, $lat]) {
                $nearest = \PHP_FLOAT_MAX;
                foreach ($chosen as [$takenLon, $takenLat]) {
                    $nearest = min($nearest, $this->geo->haversineKm($lat, $lon, $takenLat, $takenLon));
                }
                if ($nearest > $bestDistance) {
                    $bestDistance = $nearest;
                    $bestIndex = $index;
                }
            }
            $chosen[] = $rest[$bestIndex];
            unset($rest[$bestIndex]);
            $rest = array_values($rest);
        }

        $stations = [];
        foreach ($chosen as $index => [$lon, $lat]) {
            $stations[] = ['name' => self::STATIONS[$index], 'lon' => $lon, 'lat' => $lat];
        }

        return $stations;
    }

    /**
     * @param array{name: string, lon: float, lat: float} $station
     *
     * @return array{name: string, lon: float, lat: float}
     */
    private function otherStation(Randomizer $randomizer, array $station): array
    {
        $others = array_values(array_filter(
            $this->stations,
            static fn (array $candidate): bool => $candidate['name'] !== $station['name'],
        ));

        return [] === $others ? $station : $others[$randomizer->getInt(0, \count($others) - 1)];
    }

    /**
     * Points spread over the area, sampled by PostGIS: one interior point per
     * cell of a square grid clipped to the boundary. Deterministic by
     * construction; shapes that yield too few cells fall back to seeded
     * ST_GeneratePoints, which is deterministic through its seed argument.
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function samplePoints(AreaOfInterest $area): array
    {
        $grid = $this->fetchPoints($area, $this->interior().\sprintf(
            ', grid AS (
                 SELECT (ST_SquareGrid(interior.span / %d, interior.geom)).geom AS cell, interior.geom AS shape
                 FROM interior
             ), spot AS (
                 SELECT ST_PointOnSurface(ST_CollectionExtract(ST_Intersection(cell, shape), 3)) AS p
                 FROM grid WHERE ST_Intersects(cell, shape)
             )
             SELECT ST_X(p) AS lon, ST_Y(p) AS lat FROM spot
             WHERE p IS NOT NULL AND NOT ST_IsEmpty(p)
             ORDER BY ST_Y(p), ST_X(p)',
            self::GRID_DIVISIONS,
        ));
        if (\count($grid) >= self::MIN_SAMPLES) {
            return $grid;
        }

        $scattered = $this->fetchPoints($area, $this->interior().\sprintf(
            ' SELECT ST_X(p) AS lon, ST_Y(p) AS lat
             FROM (SELECT (ST_Dump(ST_GeneratePoints(interior.geom, %d, %d))).geom AS p FROM interior) d
             ORDER BY ST_Y(p), ST_X(p)',
            self::FALLBACK_SAMPLES,
            self::RANDOM_SEED,
        ));
        if ([] !== $scattered) {
            return $scattered;
        }

        // No boundary at all: a neutral ring, so the demo still has distinct posts.
        [$lon, $lat] = $this->centroid($area);
        $ring = [];
        foreach (array_keys(self::STATIONS) as $index) {
            $angle = 2 * \M_PI * $index / \count(self::STATIONS);
            $ring[] = [$lon + self::FALLBACK_SPREAD_DEG * sin($angle), $lat + self::FALLBACK_SPREAD_DEG * cos($angle)];
        }

        return $ring;
    }

    private function interior(): string
    {
        return \sprintf(self::INTERIOR_CTE, self::INTERIOR_MARGIN);
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function fetchPoints(AreaOfInterest $area, string $sql): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative($sql, ['id' => $area->getId()]);

        $points = [];
        foreach ($rows as $row) {
            if (is_numeric($row['lon'] ?? null) && is_numeric($row['lat'] ?? null)) {
                $points[] = [(float) $row['lon'], (float) $row['lat']];
            }
        }

        return $points;
    }

    /**
     * The rings of that same interior geometry, read once as GeoJSON so every
     * step can be tested in PHP instead of a query per vertex.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function boundaryRings(AreaOfInterest $area): array
    {
        $geoJson = $this->em->getConnection()->fetchOne(
            $this->interior().' SELECT ST_AsGeoJSON(geom) FROM interior',
            ['id' => $area->getId()],
        );
        if (!\is_string($geoJson)) {
            return [];
        }

        $decoded = json_decode($geoJson, true);
        $coordinates = \is_array($decoded) ? ($decoded['coordinates'] ?? null) : null;
        if (!\is_array($coordinates)) {
            return [];
        }

        $polygons = 'MultiPolygon' === ($decoded['type'] ?? null) ? $coordinates : [$coordinates];
        $rings = [];
        foreach ($polygons as $polygon) {
            if (!\is_array($polygon)) {
                continue;
            }
            foreach ($polygon as $ring) {
                if (!\is_array($ring)) {
                    continue;
                }
                $vertices = [];
                foreach ($ring as $pair) {
                    if (\is_array($pair) && is_numeric($pair[0] ?? null) && is_numeric($pair[1] ?? null)) {
                        $vertices[] = [(float) $pair[0], (float) $pair[1]];
                    }
                }
                if (\count($vertices) > 3) {
                    $rings[] = $vertices;
                }
            }
        }

        return $rings;
    }

    /** @param list<array{0: float, 1: float}> $points */
    private function trackDistanceKm(array $points): float
    {
        $km = 0.0;
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $km += $this->geo->haversineKm($points[$i - 1][1], $points[$i - 1][0], $points[$i][1], $points[$i][0]);
        }

        return $km;
    }

    /** Duty starts spread over the last SPREAD_DAYS days, at field hours. */
    private function startOfDuty(Randomizer $randomizer, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $daysAgo = $randomizer->getInt(0, self::SPREAD_DAYS - 1);
        $start = $now->modify(\sprintf('-%d days', $daysAgo))
            ->setTime($randomizer->getInt(5, 14), 5 * $randomizer->getInt(0, 11));

        // Never in the future — today's slot may land after "now".
        return $start > $now ? $now->modify('-1 day')->setTime(6, 30) : $start;
    }

    private function team(Randomizer $randomizer): string
    {
        /** @var list<string> $roster */
        $roster = $randomizer->shuffleArray(self::RANGERS);

        return implode(', ', \array_slice($roster, 0, $randomizer->getInt(2, 4)));
    }

    /**
     * The area's centroid, straight from PostGIS — the module stores boundaries
     * as geometry, so the database, not PHP, computes their middle. An area
     * without a boundary falls back to a neutral origin.
     *
     * @return array{0: float, 1: float}
     */
    private function centroid(AreaOfInterest $area): array
    {
        $geoJson = $this->em->getConnection()->fetchOne(
            'SELECT ST_AsGeoJSON(ST_Centroid(geom)) FROM area_of_interest WHERE id = :id AND geom IS NOT NULL',
            ['id' => $area->getId()],
        );

        return \is_string($geoJson) ? $this->geo->coordinates($geoJson) : [self::FALLBACK_LON, self::FALLBACK_LAT];
    }

    /** Observations ride the patrol's FK ON DELETE CASCADE. */
    private function deleteExisting(AreaOfInterest $area): void
    {
        $this->em->createQuery('DELETE FROM '.Patrol::class.' p WHERE p.area = :area')
            ->setParameter('area', $area)
            ->execute();
    }

    private function clampPoints(int $points): int
    {
        return max(self::MIN_TRACK_POINTS, min(self::MAX_TRACK_POINTS, $points));
    }

    /** The movement profile a deployment's own type word implies. */
    private function profileFor(string $type): string
    {
        $word = strtolower($type);
        foreach (self::PROFILE_KEYWORDS as $profile => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($word, $keyword)) {
                    return $profile;
                }
            }
        }

        // Unknown vocabulary still gets a stable profile, so a deployment naming
        // its types in another language keeps a varied demo.
        return self::PROFILES[crc32($word) % \count(self::PROFILES)];
    }

    /** @return non-empty-list<string> */
    private function typeKeys(): array
    {
        $keys = array_keys($this->types);

        return [] !== $keys ? $keys : ['foot'];
    }

    /** @return non-empty-list<string> */
    private function categoryKeys(): array
    {
        $keys = array_keys($this->categories);

        return [] !== $keys ? $keys : ['wildlife'];
    }

    /**
     * @param non-empty-list<string> $candidates
     */
    private function pick(Randomizer $randomizer, array $candidates): string
    {
        return $candidates[$randomizer->getInt(0, \count($candidates) - 1)];
    }
}
