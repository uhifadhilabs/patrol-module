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

namespace Uhifadhi\Patrol\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\SectorTypeEnum;

/**
 * The drone branch — API-CONTRACT.md §5 and §7.
 *
 * Its defining rule is a refusal: a drone patrol has no track. The phone in the
 * operator's hand is on the ground, so recording its fixes as the patrol's route
 * would claim coverage of a strip of road while the aircraft was working a
 * sector two kilometres away. Coverage for a drone patrol is the DECLARED
 * sectors, and nothing else.
 */
final class FieldSyncDroneTest extends FieldSyncTestCase
{
    private const string PATROL_UUID = '9a2f4e02-6b1a-4f34-8f8f-1a0f19a1c222';
    private const string LAUNCH_POINT_UUID = 'c11f0000-0000-4000-8000-000000000001';
    private const string FLIGHT_UUID = 'd42a0000-0000-4000-8000-000000000001';

    #[Test]
    public function aDronePatrolRefusesATrack(): void
    {
        $this->actingAs($this->recorder);
        $this->droneParol();

        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/track', [
            'batchUuid' => self::PATROL_UUID.':track:0',
            'points' => [['lat' => -3.2014, 'lon' => 35.4623, 'recordedAt' => '2026-08-23T06:44:17Z']],
        ]);

        self::assertResponseStatusCodeSame(422);
        $error = $this->payload();
        self::assertSame('invalid_track_for_drone_patrol', $error['code']);
        self::assertFalse($error['retryable']);

        $details = $error['details'];
        self::assertIsArray($details);
        self::assertSame(self::PATROL_UUID.':track:0', $details['batchUuid']);

        self::assertNull(
            $this->reloadPatrol()->getTrack(),
            'The operator\'s own positions were stored as coverage.',
        );
    }

    #[Test]
    public function launchPointsAndFlightsAreStoredAsDeclaredCoverage(): void
    {
        $this->actingAs($this->recorder);
        $this->droneParol();

        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/flights', [
            'launchPoints' => [[
                'clientUuid' => self::LAUNCH_POINT_UUID,
                'label' => 'LP-1',
                'name' => 'North Gate LP-1',
                'position' => ['lat' => -3.1966, 'lon' => 35.4339, 'accuracyM' => 3.0, 'satellites' => 10],
                'establishedAt' => '2026-08-23T06:47:10Z',
                'sectorType' => 'radius',
                'sectorRadiusM' => 2000.0,
                'sectorPolygon' => null,
            ]],
            'flights' => [[
                'clientUuid' => self::FLIGHT_UUID,
                'launchPointUuid' => self::LAUNCH_POINT_UUID,
                'sequence' => 1,
                'startedAt' => '2026-08-23T06:52:00Z',
                'endedAt' => '2026-08-23T07:10:00Z',
            ]],
        ]);

        self::assertResponseIsSuccessful();
        $ack = $this->payload();
        self::assertTrue($ack['accepted']);
        self::assertSame([self::LAUNCH_POINT_UUID, self::FLIGHT_UUID], $ack['acceptedUuids']);
        self::assertFalse($ack['duplicate']);

        $this->em->clear();
        $patrol = $this->reloadPatrol();

        self::assertCount(1, $patrol->getLaunchPoints());
        $launchPoint = $patrol->getLaunchPoints()->first();
        self::assertNotFalse($launchPoint);
        self::assertSame(SectorTypeEnum::Radius, $launchPoint->getSectorType());
        self::assertSame(2000.0, $launchPoint->getSectorRadiusM());
        self::assertNull($launchPoint->getSectorPolygon(), 'A radius sector must not also carry a polygon.');

        self::assertCount(1, $patrol->getFlights());
        $flight = $patrol->getFlights()->first();
        self::assertNotFalse($flight);
        self::assertSame(1, $flight->getSequence());
        self::assertFalse($flight->isAirborne());
        self::assertSame($launchPoint->getId(), $flight->getLaunchPoint()?->getId());
    }

    #[Test]
    public function anObservationSentBeforeItsFlightIsLinkedOnceTheFlightArrives(): void
    {
        $this->actingAs($this->recorder);
        $this->droneParol();

        // §11 uploads observations BEFORE flights, so this observation names a
        // flight the server has never heard of yet.
        $observationUuid = 'b23f0e77-0000-4000-8000-0000000000aa';
        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/observations', [
            'observations' => [[
                'clientUuid' => $observationUuid,
                'category' => 'maintenance',
                'position' => ['lat' => -3.1970, 'lon' => 35.4350],
                'positionSource' => 'operator_marked',
                'loggedAt' => '2026-08-23T06:58:00Z',
                'launchPointUuid' => self::LAUNCH_POINT_UUID,
                'flightUuid' => self::FLIGHT_UUID,
                'photoCount' => 0,
            ]],
        ]);
        self::assertResponseIsSuccessful();

        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/flights', [
            'launchPoints' => [[
                'clientUuid' => self::LAUNCH_POINT_UUID,
                'label' => 'LP-1',
                'position' => ['lat' => -3.1966, 'lon' => 35.4339],
                'establishedAt' => '2026-08-23T06:47:10Z',
                'sectorRadiusM' => 2000.0,
            ]],
            'flights' => [[
                'clientUuid' => self::FLIGHT_UUID,
                'launchPointUuid' => self::LAUNCH_POINT_UUID,
                'sequence' => 1,
                'startedAt' => '2026-08-23T06:52:00Z',
                // Still airborne when the phone uploaded (§7).
                'endedAt' => null,
            ]],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $observation = $this->reloadPatrol()->getObservations()->first();
        self::assertNotFalse($observation);

        self::assertNotNull(
            $observation->getFlight(),
            'The observation lost its sortie because the parts arrived in the contract\'s own order.',
        );
        $flight = $observation->getFlight();
        self::assertSame(self::FLIGHT_UUID, $flight->getClientUuid()->toRfc4122());
        self::assertSame(self::LAUNCH_POINT_UUID, $observation->getLaunchPoint()?->getClientUuid()->toRfc4122());
        self::assertTrue($flight->isAirborne(), 'endedAt: null means the aircraft was still up at upload time.');
    }

    #[Test]
    public function aPolygonSectorIsStoredAsDrawn(): void
    {
        $this->actingAs($this->recorder);
        $this->droneParol();

        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/flights', [
            'launchPoints' => [[
                'clientUuid' => self::LAUNCH_POINT_UUID,
                'label' => 'LP-2',
                'position' => ['lat' => -3.1966, 'lon' => 35.4339],
                'sectorType' => 'polygon',
                'sectorPolygon' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[35.41, -3.25], [35.52, -3.25], [35.52, -3.15], [35.41, -3.15], [35.41, -3.25]]],
                ],
            ]],
            'flights' => [],
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $launchPoint = $this->reloadPatrol()->getLaunchPoints()->first();
        self::assertNotFalse($launchPoint);
        self::assertSame(SectorTypeEnum::Polygon, $launchPoint->getSectorType());
        self::assertNull($launchPoint->getSectorRadiusM(), 'A polygon sector must not also carry a radius.');
        self::assertNotNull($launchPoint->getSectorPolygon());
    }

    #[Test]
    public function aReSentFlightsPartAddsNothing(): void
    {
        $this->actingAs($this->recorder);
        $this->droneParol();

        $part = [
            'launchPoints' => [[
                'clientUuid' => self::LAUNCH_POINT_UUID,
                'label' => 'LP-1',
                'position' => ['lat' => -3.1966, 'lon' => 35.4339],
                'sectorRadiusM' => 2000.0,
            ]],
            'flights' => [[
                'clientUuid' => self::FLIGHT_UUID,
                'launchPointUuid' => self::LAUNCH_POINT_UUID,
                'sequence' => 1,
                'startedAt' => '2026-08-23T06:52:00Z',
            ]],
        ];

        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/flights', $part);
        self::assertFalse($this->payload()['duplicate']);

        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/flights', $part);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate']);

        $this->em->clear();
        $patrol = $this->reloadPatrol();
        self::assertCount(1, $patrol->getLaunchPoints());
        self::assertCount(1, $patrol->getFlights());
    }

    #[Test]
    public function aLaunchPointWithNeitherRadiusNorPolygonIsRefused(): void
    {
        $this->actingAs($this->recorder);
        $this->droneParol();

        $this->postJson('/api/patrols/'.self::PATROL_UUID.'/flights', [
            'launchPoints' => [[
                'clientUuid' => self::LAUNCH_POINT_UUID,
                'label' => 'LP-1',
                'position' => ['lat' => -3.1966, 'lon' => 35.4339],
            ]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_geometry', $this->payload()['code']);
    }

    private function droneParol(): void
    {
        $this->createPatrol([
            'clientUuid' => self::PATROL_UUID,
            'type' => 'drone',
            'droneId' => 'DJI-01',
            'mission' => 'Sector sweep',
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    private function reloadPatrol(): Patrol
    {
        $patrol = $this->em->getRepository(Patrol::class)
            ->findOneBy(['clientUuid' => Uuid::fromString(self::PATROL_UUID)]);

        self::assertInstanceOf(Patrol::class, $patrol);

        return $patrol;
    }
}
