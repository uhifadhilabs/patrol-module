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

namespace Uhifadhi\Patrol\Tests\Integration\Repository;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * What PL·A4 reads: the area's observations by when they were logged, and the
 * host ZONE each one fell in.
 *
 * An observation carries a point and no zone, exactly as a patrol carries a
 * free-text station and no zone — so "which zone was this seen in" is a spatial
 * question against the host's polygons, asked once for a page of rows rather
 * than once per row.
 *
 * An observation with no position has no zone, and gets none. The field app is
 * allowed to sync an unpositioned observation (its honest word about a sighting
 * it could not fix), and inventing a zone for it would turn that honesty into a
 * location nobody recorded.
 */
final class ObservationRepositoryOverviewTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Patrol $patrol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new AreaOfInterest()->setName('Example square');
        $this->em->persist($this->area);
        $this->patrol = new Patrol($this->area, 'walk')
            ->setStartedAt(new \DateTimeImmutable('2026-03-20T06:00:00Z'));
        $this->em->persist($this->patrol);
        $this->em->flush();
    }

    private function repository(): ObservationRepository
    {
        $repository = $this->em->getRepository(Observation::class);
        \assert($repository instanceof ObservationRepository);

        return $repository;
    }

    private function makeObservation(?string $loggedAt, ?string $position = null, ?Patrol $patrol = null): Observation
    {
        $observation = new Observation($patrol ?? $this->patrol, 'maintenance')
            ->setNote('Fence line down')
            ->setPosition($position)
            ->setLoggedAt(null === $loggedAt ? null : new \DateTimeImmutable($loggedAt));
        $this->em->persist($observation);
        $this->em->flush();

        return $observation;
    }

    private function makeZone(string $name, float $southLat, float $northLat): Zone
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

    public function testTheAreasObservationsAreReadNewestFirst(): void
    {
        $older = $this->makeObservation('2026-03-20T08:00:00Z');
        $newer = $this->makeObservation('2026-03-21T08:00:00Z');

        $rows = $this->repository()->findByAreaLatestFirst($this->area, 10);

        self::assertSame(
            [$newer->getId(), $older->getId()],
            array_map(static fn (Observation $o): ?int => $o->getId(), $rows),
        );
    }

    public function testAnotherAreasObservationsAreNotThisAreas(): void
    {
        $elsewhere = new AreaOfInterest()->setName('Elsewhere');
        $this->em->persist($elsewhere);
        $otherPatrol = new Patrol($elsewhere, 'walk')->setStartedAt(new \DateTimeImmutable('2026-03-20T06:00:00Z'));
        $this->em->persist($otherPatrol);
        $this->em->flush();
        $this->makeObservation('2026-03-21T08:00:00Z', null, $otherPatrol);

        self::assertSame([], $this->repository()->findByAreaLatestFirst($this->area, 10));
    }

    public function testALoggedWindowIsHalfOpen(): void
    {
        $this->makeObservation('2026-03-21T23:59:59Z');
        $inside = $this->makeObservation('2026-03-22T00:00:00Z');
        $this->makeObservation('2026-03-23T00:00:00Z');

        $rows = $this->repository()->findByAreaLoggedBetween(
            $this->area,
            new \DateTimeImmutable('2026-03-22T00:00:00Z'),
            new \DateTimeImmutable('2026-03-23T00:00:00Z'),
        );

        self::assertSame([$inside->getId()], array_map(static fn (Observation $o): ?int => $o->getId(), $rows));
    }

    public function testAnObservationWithNoLoggedTimeSitsInNoDay(): void
    {
        $this->makeObservation(null);

        self::assertSame([], $this->repository()->findByAreaLoggedBetween(
            $this->area,
            new \DateTimeImmutable('2026-03-01T00:00:00Z'),
            new \DateTimeImmutable('2026-04-01T00:00:00Z'),
        ));
    }

    public function testAnObservationIsNamedByTheZoneItsPointFellIn(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $this->makeZone('South', -3.0, -2.95);
        $north = $this->makeObservation('2026-03-21T08:00:00Z', '{"type":"Point","coordinates":[35.05,-2.92]}');
        $south = $this->makeObservation('2026-03-21T09:00:00Z', '{"type":"Point","coordinates":[35.05,-2.98]}');

        $zones = $this->repository()->zoneNamesFor([$north, $south]);

        self::assertSame('North', $zones[(int) $north->getId()]);
        self::assertSame('South', $zones[(int) $south->getId()]);
    }

    public function testAnUnpositionedObservationIsGivenNoZone(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $unpositioned = $this->makeObservation('2026-03-21T08:00:00Z');

        self::assertSame([], $this->repository()->zoneNamesFor([$unpositioned]));
    }

    public function testAPointOutsideEveryZoneIsGivenNoZone(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $outside = $this->makeObservation('2026-03-21T08:00:00Z', '{"type":"Point","coordinates":[36.5,-2.92]}');

        self::assertSame([], $this->repository()->zoneNamesFor([$outside]));
    }

    public function testAskingAboutNoObservationsAsksTheDatabaseNothing(): void
    {
        self::assertSame([], $this->repository()->zoneNamesFor([]));
    }
}
