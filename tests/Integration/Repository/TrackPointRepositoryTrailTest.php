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

namespace UhifadhiLabs\Patrol\Tests\Integration\Repository;

use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Entity\TrackBatch;
use UhifadhiLabs\Patrol\Entity\TrackPoint;
use UhifadhiLabs\Patrol\Enum\PatrolStatusEnum;
use UhifadhiLabs\Patrol\Repository\TrackPointRepository;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * THE LAST PING, WHICH IS NOT A COLUMN.
 *
 * "Out right now" turns on how long ago a patrol was last heard from, and this
 * module stores no last-seen field: a patrol is heard from when a track point
 * arrives, so the answer is MAX(recorded_at) per patrol and the ping itself is
 * that row's position. Deriving it — rather than adding a column the sync would
 * have to remember to write — means a ping can never be stale in one place and
 * fresh in another.
 *
 * The same pass returns the trail, because a live patrol drawn on the plate is
 * the line its points make: a RECORDING patrol's `track` is deliberately null
 * until it closes (a half-finished line is not a claim about ground covered),
 * so the live layer has nothing else to draw.
 */
final class TrackPointRepositoryTrailTest extends IntegrationTestCase
{
    private function repository(): TrackPointRepository
    {
        $repository = $this->em->getRepository(TrackPoint::class);
        \assert($repository instanceof TrackPointRepository);

        return $repository;
    }

    private function makePatrol(): Patrol
    {
        $area = new AreaOfInterest()->setName('Example square');
        $this->em->persist($area);
        $patrol = new Patrol($area, 'walk')
            ->setStatus(PatrolStatusEnum::Recording)
            ->setStartedAt(new \DateTimeImmutable('2026-03-22T06:00:00Z'));
        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }

    /** @param list<array{string, float, float}> $points [recordedAt, lon, lat] */
    private function ping(Patrol $patrol, array $points): void
    {
        $batch = new TrackBatch($patrol, 'batch-'.uniqid());
        $this->em->persist($batch);
        foreach ($points as [$at, $lon, $lat]) {
            $this->em->persist(new TrackPoint(
                $patrol,
                $batch,
                \sprintf('{"type":"Point","coordinates":[%s,%s]}', $lon, $lat),
                new \DateTimeImmutable($at),
            ));
        }
        $this->em->flush();
    }

    public function testTheLastPingIsTheLatestPointAndItsPosition(): void
    {
        $patrol = $this->makePatrol();
        $this->ping($patrol, [
            ['2026-03-22T06:10:00Z', 35.00, -2.95],
            ['2026-03-22T07:40:00Z', 35.02, -2.95],
            ['2026-03-22T06:55:00Z', 35.01, -2.95],
        ]);

        $trails = $this->repository()->trailsForPatrols([(int) $patrol->getId()]);

        $trail = $trails[(int) $patrol->getId()];
        self::assertSame('2026-03-22 07:40', $trail['lastAt']->format('Y-m-d H:i'));
        /** @var array{coordinates: array{float, float}} $point */
        $point = json_decode($trail['lastPoint'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertEqualsWithDelta(35.02, $point['coordinates'][0], 0.0001);
    }

    public function testTheTrailIsTheLineTheSPointsMakeInTimeOrder(): void
    {
        $patrol = $this->makePatrol();
        $this->ping($patrol, [
            ['2026-03-22T07:40:00Z', 35.02, -2.95],
            ['2026-03-22T06:10:00Z', 35.00, -2.95],
            ['2026-03-22T06:55:00Z', 35.01, -2.95],
        ]);

        $trail = $this->repository()->trailsForPatrols([(int) $patrol->getId()])[(int) $patrol->getId()];

        self::assertNotNull($trail['line']);
        /** @var array{type: string, coordinates: list<array{float, float}>} $line */
        $line = json_decode($trail['line'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('LineString', $line['type']);
        self::assertCount(3, $line['coordinates']);
        // Ordered by when they were recorded, not by when they arrived.
        self::assertEqualsWithDelta(35.00, $line['coordinates'][0][0], 0.0001);
        self::assertEqualsWithDelta(35.02, $line['coordinates'][2][0], 0.0001);
    }

    public function testOnePingIsAPingAndNotALine(): void
    {
        $patrol = $this->makePatrol();
        $this->ping($patrol, [['2026-03-22T06:10:00Z', 35.00, -2.95]]);

        $trail = $this->repository()->trailsForPatrols([(int) $patrol->getId()])[(int) $patrol->getId()];

        self::assertStringContainsString('Point', $trail['lastPoint']);
        // A single point is not a route, and drawing it as one would claim a
        // direction of travel nobody recorded.
        self::assertNull($trail['line']);
    }

    public function testAPatrolThatHasNeverPingedIsSimplyAbsent(): void
    {
        $patrol = $this->makePatrol();

        self::assertSame([], $this->repository()->trailsForPatrols([(int) $patrol->getId()]));
    }

    public function testAskingAboutNoPatrolsAsksTheDatabaseNothing(): void
    {
        self::assertSame([], $this->repository()->trailsForPatrols([]));
    }
}
