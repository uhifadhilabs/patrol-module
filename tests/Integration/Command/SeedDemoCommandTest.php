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

namespace UhifadhiLabs\Patrol\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * patrol:seed:demo against the REAL PostGIS database: the tracks it invents must
 * survive the geometry columns (valid LineStrings), the observations must sit on
 * the track they belong to, and a second run must not quietly double the data.
 */
final class SeedDemoCommandTest extends IntegrationTestCase
{
    private function makeArea(): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName('Example reserve')
            ->setGeom((string) json_encode([
                'type' => 'MultiPolygon',
                'coordinates' => [[[[5.0, -72.5], [15.0, -72.5], [15.0, -70.0], [5.0, -70.0], [5.0, -72.5]]]],
            ], \JSON_THROW_ON_ERROR));
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /** @param array<string, string|int|bool> $input */
    private function seed(array $input): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('patrol:seed:demo'));
        $tester->execute($input);
        $this->em->clear();

        return $tester;
    }

    /** @return list<Patrol> */
    private function storedPatrols(AreaOfInterest $area): array
    {
        $areaId = $area->getId();
        self::assertNotNull($areaId);
        $reloaded = $this->em->find(AreaOfInterest::class, $areaId);
        self::assertInstanceOf(AreaOfInterest::class, $reloaded);

        /** @var list<Patrol> $patrols */
        $patrols = $this->em->getRepository(Patrol::class)
            ->findBy(['area' => $reloaded], ['startedAt' => 'ASC']);

        return $patrols;
    }

    public function testItSeedsTheRequestedNumberOfPatrolsForTheArea(): void
    {
        $area = $this->makeArea();

        $tester = $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 6]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $patrols = $this->storedPatrols($area);
        self::assertCount(6, $patrols);

        $earliest = new \DateTimeImmutable('-36 days');
        $now = new \DateTimeImmutable();
        foreach ($patrols as $patrol) {
            $started = $patrol->getStartedAt();
            self::assertNotNull($started);
            self::assertGreaterThan($earliest, $started);
            self::assertLessThanOrEqual($now, $started);
            self::assertGreaterThan($started, $patrol->getEndedAt());
            // Vocabulary comes from config (TestKernel: walk, boat) — never invented.
            self::assertContains($patrol->getType(), ['walk', 'boat']);
            self::assertNotSame('', (string) $patrol->getStation());
            self::assertNotSame('', (string) $patrol->getTeam());
        }

        self::assertStringContainsString('6', $tester->getDisplay());
    }

    public function testRecordedTracksAreValidLineStringsPostgisAccepts(): void
    {
        $area = $this->makeArea();
        $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 6]);

        $recorded = array_values(array_filter(
            $this->storedPatrols($area),
            static fn (Patrol $p): bool => PatrolSourceEnum::Gpx === $p->getSource(),
        ));
        self::assertNotEmpty($recorded);

        $connection = $this->em->getConnection();
        foreach ($recorded as $patrol) {
            $trackJson = $patrol->getTrack();
            self::assertNotNull($trackJson);
            /** @var array{type: string, coordinates: list<list<float>>} $geo */
            $geo = json_decode($trackJson, true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('LineString', $geo['type']);
            self::assertGreaterThanOrEqual(30, \count($geo['coordinates']));
            self::assertLessThanOrEqual(120, \count($geo['coordinates']));
            self::assertSame(\count($geo['coordinates']), $patrol->getPointCount());

            // PostGIS is the judge of the geometry, not the JSON.
            /** @var array{npoints: int, valid: bool, srid: int} $row */
            $row = $connection->fetchAssociative(
                'SELECT ST_NumPoints(track) AS npoints, ST_IsValid(track) AS valid, ST_SRID(track) AS srid
                 FROM patrol_patrol WHERE id = :id',
                ['id' => $patrol->getId()],
            );
            self::assertSame(\count($geo['coordinates']), (int) $row['npoints']);
            self::assertTrue((bool) $row['valid']);
            self::assertSame(4326, (int) $row['srid']);

            $distanceKm = $patrol->getDistanceKm();
            self::assertNotNull($distanceKm);
            self::assertGreaterThan(0.0, $distanceKm);
        }
    }

    public function testSketchedManualPatrolsNeverPresentAsRecordedTracks(): void
    {
        $area = $this->makeArea();
        $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 12]);

        $manual = array_values(array_filter(
            $this->storedPatrols($area),
            static fn (Patrol $p): bool => PatrolSourceEnum::Manual === $p->getSource(),
        ));
        self::assertNotEmpty($manual, 'the demo data includes hand-entered patrols');

        foreach ($manual as $patrol) {
            self::assertNull($patrol->getTrack());
            self::assertNull($patrol->getPointCount());
            self::assertSame(0, $patrol->getGapCount());
        }
    }

    public function testObservationsBelongToTheirPatrolAndLieOnItsTrack(): void
    {
        $area = $this->makeArea();
        $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 12]);

        $observations = $this->em->getRepository(Observation::class)->findAll();
        self::assertNotEmpty($observations);

        $connection = $this->em->getConnection();
        foreach ($observations as $observation) {
            $patrol = $observation->getPatrol();
            self::assertSame($area->getId(), $patrol->getArea()->getId());
            self::assertNotNull($patrol->getTrack(), 'observations are only logged en route');
            self::assertContains($observation->getCategory(), ['maintenance']);
            self::assertNotNull($observation->getLoggedAt());
            self::assertGreaterThanOrEqual($patrol->getStartedAt(), $observation->getLoggedAt());
            self::assertLessThanOrEqual($patrol->getEndedAt(), $observation->getLoggedAt());

            /** @var array{metres: float|string} $row */
            $row = $connection->fetchAssociative(
                'SELECT ST_Distance(o.position::geography, p.track::geography) AS metres
                 FROM patrol_observation o JOIN patrol_patrol p ON p.id = o.patrol_id
                 WHERE o.id = :id',
                ['id' => $observation->getId()],
            );
            self::assertLessThan(1.0, (float) $row['metres'], 'the observation sits on its patrol track');
        }
    }

    public function testASecondRunWithoutFreshAddsNothing(): void
    {
        $area = $this->makeArea();
        $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 5]);
        $before = array_map(
            static fn (Patrol $p): string => $p->getUuid()->toRfc4122(),
            $this->storedPatrols($area),
        );

        $tester = $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 5]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('--fresh', $tester->getDisplay());
        $after = array_map(
            static fn (Patrol $p): string => $p->getUuid()->toRfc4122(),
            $this->storedPatrols($area),
        );
        self::assertSame($before, $after);
    }

    public function testFreshReplacesTheExistingPatrols(): void
    {
        $area = $this->makeArea();
        $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 5]);
        $before = array_map(static fn (Patrol $p): ?int => $p->getId(), $this->storedPatrols($area));

        $tester = $this->seed(['--area' => (string) $area->getUuidString(), '--patrols' => 4, '--fresh' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $after = $this->storedPatrols($area);
        self::assertCount(4, $after);
        foreach ($after as $patrol) {
            self::assertNotContains($patrol->getId(), $before);
        }

        // The replaced patrols took their observations with them.
        foreach ($this->em->getRepository(Observation::class)->findAll() as $observation) {
            self::assertNotContains($observation->getPatrol()->getId(), $before);
        }
    }

    public function testTheAreaMustBeNamedExplicitly(): void
    {
        $this->makeArea();

        $tester = $this->seed([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--area', $tester->getDisplay());
    }

    public function testAnUnknownAreaFailsLoudly(): void
    {
        $this->makeArea();

        $tester = $this->seed(['--area' => '11111111-2222-4333-8444-555555555555']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No area', $tester->getDisplay());
    }
}
