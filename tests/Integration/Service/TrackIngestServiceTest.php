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

namespace Uhifadhi\Patrol\Tests\Integration\Service;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Service\TrackIngestService;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

final class TrackIngestServiceTest extends IntegrationTestCase
{
    private function gpx(): string
    {
        $xml = file_get_contents(\dirname(__DIR__, 2).'/Fixtures/gpx/short_track.gpx');
        \assert(false !== $xml);

        return $xml;
    }

    private function ingest(): TrackIngestService
    {
        $service = $this->service(TrackIngestService::class);
        \assert($service instanceof TrackIngestService);

        return $service;
    }

    private function makeArea(): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName('Example reserve');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    public function testIngestsAGpxFileIntoAStoredPatrol(): void
    {
        $lead = new User()->setEmail('lead@example.test')->setFirstName('Alex')->setLastName('Example');
        $this->em->persist($lead);
        $area = $this->makeArea();

        $patrol = $this->ingest()->ingest(
            $this->gpx(),
            $area,
            type: 'walk',
            station: 'North post',
            lead: $lead,
            team: 'B. Example, C. Example',
        );

        // Reload from the database — assertions are about what was stored.
        $this->em->clear();
        $stored = $this->em->find(Patrol::class, $patrol->getId());
        self::assertInstanceOf(Patrol::class, $stored);

        self::assertSame('walk', $stored->getType());
        self::assertSame('North post', $stored->getStation());
        self::assertSame(PatrolSourceEnum::Gpx, $stored->getSource());
        self::assertSame(4, $stored->getPointCount());
        self::assertSame(1, $stored->getGapCount());
        self::assertNotNull($stored->getDistanceKm());
        self::assertEqualsWithDelta(0.5293, $stored->getDistanceKm(), 0.001);
        self::assertEquals(new \DateTimeImmutable('2026-03-01T06:00:00Z'), $stored->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-03-01T06:25:00Z'), $stored->getEndedAt());
        self::assertNotNull($stored->getCreatedAt());
        self::assertSame('lead@example.test', $stored->getLead()?->getEmail());

        // The track round-trips through PostGIS as a GeoJSON LineString.
        $trackJson = $stored->getTrack();
        self::assertNotNull($trackJson);
        /** @var array{type: string, coordinates: list<list<float>>} $geo */
        $geo = json_decode($trackJson, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('LineString', $geo['type']);
        self::assertCount(4, $geo['coordinates']);
        self::assertEqualsWithDelta([-30.0, -1.0], $geo['coordinates'][0], 1e-9);
    }

    public function testObservationsPersistWithTheirPatrol(): void
    {
        $area = $this->makeArea();
        $patrol = $this->ingest()->ingest($this->gpx(), $area, type: 'boat');

        $observation = new Observation($patrol, 'maintenance');
        $observation->setNote('Jetty ladder broken.')
            ->setPosition('{"type":"Point","coordinates":[-30.001,-1.0005]}')
            ->setLoggedAt(new \DateTimeImmutable('2026-03-01T06:07:00Z'));
        $this->em->persist($observation);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->find(Patrol::class, $patrol->getId());
        self::assertInstanceOf(Patrol::class, $stored);
        self::assertCount(1, $stored->getObservations());
        $first = $stored->getObservations()->first();
        self::assertInstanceOf(Observation::class, $first);
        self::assertSame('maintenance', $first->getCategory());
        self::assertSame('Jetty ladder broken.', $first->getNote());
        $position = $first->getPosition();
        self::assertNotNull($position);
        /** @var array{type: string, coordinates: list<float>} $point */
        $point = json_decode($position, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Point', $point['type']);
        self::assertEqualsWithDelta([-30.001, -1.0005], $point['coordinates'], 1e-9);
    }
}
