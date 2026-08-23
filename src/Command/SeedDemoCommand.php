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

namespace UhifadhiLabs\Patrol\Command;

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
use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Service\GeoService;

/**
 * Fills one area with invented-but-plausible patrol history, so a fresh install
 * shows a living dashboard instead of empty widgets — no hand-written SQL.
 *
 * Everything here is FICTION: made-up ranger names, generic station names, a
 * random walk around the area's own centroid. It never names a deployment, and
 * it is dev tooling (patrol.dev_tools), never registered in production.
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

    /** Used when the area has no boundary geometry to take a centroid from. */
    private const float FALLBACK_LON = 0.0;
    private const float FALLBACK_LAT = 0.0;

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

        [$lon, $lat] = $this->centroid($area);
        $randomizer = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $now = new \DateTimeImmutable();

        $recorded = 0;
        $sketched = 0;
        $observations = 0;
        for ($i = 0; $i < $count; ++$i) {
            // Every fourth patrol is hand-entered: real rosters are never all GPS.
            if (3 === $i % 4) {
                $this->sketchedPatrol($area, $randomizer, $now);
                ++$sketched;
                continue;
            }
            $observations += $this->recordedPatrol($area, $randomizer, $now, $lon, $lat);
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

        return Command::SUCCESS;
    }

    /** Observations logged en route on the patrol just created. */
    private function recordedPatrol(
        AreaOfInterest $area,
        Randomizer $randomizer,
        \DateTimeImmutable $now,
        float $lon,
        float $lat,
    ): int {
        $points = $this->walk($randomizer, $lon, $lat, $randomizer->getInt(self::MIN_TRACK_POINTS, self::MAX_TRACK_POINTS));
        $distanceKm = $this->trackDistanceKm($points);
        $startedAt = $this->startOfDuty($randomizer, $now);
        // Pace, not a clock: a field hour covers roughly 3.5–5.5 km.
        $endedAt = $startedAt->modify(\sprintf('+%d seconds', (int) round($distanceKm / $randomizer->getFloat(3.5, 5.5) * 3600)));

        $patrol = new Patrol($area, $this->pick($randomizer, $this->typeKeys()))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStation($this->pick($randomizer, self::STATIONS))
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
    private function sketchedPatrol(AreaOfInterest $area, Randomizer $randomizer, \DateTimeImmutable $now): void
    {
        $startedAt = $this->startOfDuty($randomizer, $now);
        $patrol = new Patrol($area, $this->pick($randomizer, $this->typeKeys()))
            ->setSource(PatrolSourceEnum::Manual)
            ->setStation($this->pick($randomizer, self::STATIONS))
            ->setTeam($this->team($randomizer))
            ->setNote('Written up from the duty log — route not recorded.')
            ->setStartedAt($startedAt)
            ->setEndedAt($startedAt->modify(\sprintf('+%d minutes', $randomizer->getInt(70, 260))))
            ->setDistanceKm(round($randomizer->getFloat(2.0, 11.0), 1));
        $this->em->persist($patrol);
    }

    /**
     * A random walk in degrees: heading wanders, steps stay a few hundred metres.
     *
     * @return non-empty-list<array{0: float, 1: float}>
     */
    private function walk(Randomizer $randomizer, float $lon, float $lat, int $points): array
    {
        // Start away from the centroid so the demo patrols cover the area, not one spot.
        $currentLon = $lon + $randomizer->getFloat(-0.05, 0.05);
        $currentLat = $lat + $randomizer->getFloat(-0.05, 0.05);
        $heading = $randomizer->getFloat(0.0, 2 * \M_PI);

        $track = [[round($currentLon, 6), round($currentLat, 6)]];
        for ($i = 1; $i < $points; ++$i) {
            $heading += $randomizer->getFloat(-0.45, 0.45);
            $step = $randomizer->getFloat(0.0004, 0.0016);
            // A degree of longitude shrinks with latitude; keep steps roughly equal on the ground.
            $currentLon += sin($heading) * $step / max(0.2, cos(deg2rad($currentLat)));
            $currentLat += cos($heading) * $step;
            $track[] = [round($currentLon, 6), round($currentLat, 6)];
        }

        return $track;
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

    /** @return list<string> */
    private function typeKeys(): array
    {
        $keys = array_keys($this->types);

        return [] !== $keys ? $keys : ['foot'];
    }

    /** @return list<string> */
    private function categoryKeys(): array
    {
        $keys = array_keys($this->categories);

        return [] !== $keys ? $keys : ['wildlife'];
    }

    /**
     * @param list<string> $candidates
     */
    private function pick(Randomizer $randomizer, array $candidates): string
    {
        return $candidates[$randomizer->getInt(0, \count($candidates) - 1)];
    }
}
