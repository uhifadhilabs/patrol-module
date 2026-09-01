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

namespace UhifadhiLabs\Patrol\Tests\Integration\Overview;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use Uhifadhi\Entity\Zone;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Entity\TrackBatch;
use UhifadhiLabs\Patrol\Entity\TrackPoint;
use UhifadhiLabs\Patrol\Enum\PatrolStatusEnum;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * ONE MORNING, BUILT ONCE, read by every test of the module's five overview
 * contributions.
 *
 * The saturday the design describes, in the test kernel's own synthetic
 * vocabulary (walk / boat, never a client's words): two patrols out — one
 * pinging, one silent for over two hours — three closed since midnight, an
 * observation logged, and two zones of which one has not been entered for a
 * fortnight.
 *
 * SHARED BECAUSE THE POINT IS THAT THEY AGREE. The strip, the live card, the
 * attention list and the map plate all describe this same morning, and a fixture
 * per test would let four of them be right about four different days.
 */
abstract class PatrolOverviewTestCase extends IntegrationTestCase
{
    protected const string NOW = '2026-03-21T11:42:00+00:00';

    protected AreaOfInterest $area;

    /** @var array<string, Patrol> */
    protected array $patrols = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setName('Example square');
        $this->area->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.0],[35.1,-3.0],[35.1,-2.9],[35.0,-2.9],[35.0,-3.0]]]]}');
        $this->em->persist($this->area);
        $this->em->flush();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    protected function makeUser(string $first, string $last): User
    {
        $user = new User()
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function makeZone(string $name, float $southLat, float $northLat): Zone
    {
        $zone = new Zone()
            ->setName($name)
            ->setArea($this->area)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[35.0,%1$s],[35.1,%1$s],[35.1,%2$s],[35.0,%2$s],[35.0,%1$s]]]]}',
                $southLat,
                $northLat,
            ));
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    protected function makePatrol(string $key, string $type, ?string $startedAt, ?string $endedAt = null, PatrolStatusEnum $status = PatrolStatusEnum::Complete): Patrol
    {
        $patrol = new Patrol($this->area, $type)
            ->setStatus($status)
            ->setStation(ucfirst($key))
            ->setStartedAt(null === $startedAt ? null : new \DateTimeImmutable($startedAt))
            ->setEndedAt(null === $endedAt ? null : new \DateTimeImmutable($endedAt));
        $this->em->persist($patrol);
        $this->em->flush();

        return $this->patrols[$key] = $patrol;
    }

    /** @param list<array{string, float, float}> $points [recordedAt, lon, lat] */
    protected function ping(Patrol $patrol, array $points): void
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

    protected function makeObservation(Patrol $patrol, string $loggedAt, ?string $position = null, string $note = 'Fence line down'): Observation
    {
        $observation = new Observation($patrol, 'maintenance')
            ->setNote($note)
            ->setPosition($position)
            ->setLoggedAt(new \DateTimeImmutable($loggedAt));
        $this->em->persist($observation);
        $this->em->flush();

        return $observation;
    }
}
