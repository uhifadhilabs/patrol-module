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

namespace UhifadhiLabs\Patrol\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolStatusEnum;

/**
 * The whole upload the field app performs, end to end, in the order it performs
 * it (API-CONTRACT.md §11): record → track batches → observations → photos →
 * complete. Then the thing that actually keeps a patrol honest — sending it all
 * again.
 *
 * Every assertion here is about the LITERAL contract: the field names, the
 * status codes, and the promise that a retry costs nothing.
 */
final class FieldSyncFlowTest extends FieldSyncTestCase
{
    #[Test]
    public function theFullMobileUploadProducesOneCompletePatrol(): void
    {
        $this->actingAs($this->recorder);

        // ── record ───────────────────────────────────────────────────────────
        $patrolUuid = $this->createPatrol();

        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();
        self::assertSame($patrolUuid, $created['uuid']);
        self::assertFalse($created['duplicate']);
        self::assertSame('recording', $created['status']);
        // The server assigns the reference; the app never invents one.
        self::assertIsString($created['reference']);
        self::assertMatchesRegularExpression('/^P-\d{4}$/', $created['reference']);

        // ── track batches, two of them ───────────────────────────────────────
        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:0",
            'points' => [
                ['lat' => -3.2014, 'lon' => 35.4623, 'recordedAt' => '2026-08-23T06:44:17Z', 'accuracyM' => 4.0, 'satellites' => 9, 'elevationM' => 1544.2, 'speedMs' => 1.3],
                ['lat' => -3.2020, 'lon' => 35.4630, 'recordedAt' => '2026-08-23T06:45:17Z', 'accuracyM' => 5.0, 'satellites' => 8],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $batch = $this->payload();
        self::assertTrue($batch['accepted']);
        self::assertSame(["{$patrolUuid}:track:0"], $batch['acceptedUuids']);
        self::assertFalse($batch['duplicate']);

        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:1",
            'points' => [
                ['lat' => -3.2031, 'lon' => 35.4644, 'recordedAt' => '2026-08-23T06:46:17Z', 'accuracyM' => 6.0],
            ],
        ]);
        self::assertResponseIsSuccessful();

        // ── observations ─────────────────────────────────────────────────────
        $observationUuid = 'b23f0e77-0000-4000-8000-000000000001';
        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => $observationUuid,
                'category' => 'maintenance',
                'note' => 'Wire snare on the game trail 40 m east of the dip.',
                'position' => ['lat' => -3.2014, 'lon' => 35.4623, 'accuracyM' => 4.0, 'satellites' => 9],
                'positionSource' => 'gps',
                'loggedAt' => '2026-08-23T08:31:02Z',
                'launchPointUuid' => null,
                'flightUuid' => null,
                'photoCount' => 1,
            ]],
        ]);

        self::assertResponseIsSuccessful();
        $observations = $this->payload();
        self::assertSame([$observationUuid], $observations['acceptedUuids']);
        self::assertFalse($observations['duplicate']);

        // ── the one photo that observation promised ──────────────────────────
        $photoUuid = 'e77c0000-0000-4000-8000-000000000001';
        $this->uploadPhoto($observationUuid, $photoUuid);

        self::assertResponseIsSuccessful();
        $photo = $this->payload();
        self::assertSame([$photoUuid], $photo['acceptedUuids']);
        self::assertFalse($photo['duplicate']);

        // ── complete ─────────────────────────────────────────────────────────
        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);

        self::assertResponseIsSuccessful();
        $completed = $this->payload();
        self::assertSame($patrolUuid, $completed['uuid']);
        self::assertSame('complete', $completed['status']);
        self::assertFalse($completed['duplicate']);

        // ── what was actually stored ─────────────────────────────────────────
        $patrol = $this->reloadPatrol($patrolUuid);
        self::assertSame(PatrolStatusEnum::Complete, $patrol->getStatus());
        self::assertSame(3, $patrol->getPointCount(), 'Both batches contributed their fixes.');
        self::assertCount(2, $patrol->getTrackBatches());
        self::assertCount(1, $patrol->getObservations());
        self::assertNotNull($patrol->getTrack(), 'Three fixes make a route.');
        self::assertSame(['sl-0142', 'nk-0088'], $patrol->getTeamRangerIds());
        self::assertSame('Rita Recorder, Ben Bystander', $patrol->getTeam(), 'The ids resolved to real people.');
        self::assertSame('north-gate', $patrol->getStation());
    }

    #[Test]
    public function reSendingTheCreateReturnsTheSamePatrolAndNeverASecondOne(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol();
        self::assertResponseStatusCodeSame(201);
        $first = $this->payload();

        // The phone could not tell whether the first response arrived, so it
        // sends exactly the same request again.
        $this->createPatrol();

        // Success, NOT a conflict — 409 is never the right answer to a re-sent
        // uuid (§1).
        self::assertResponseStatusCodeSame(200);
        $second = $this->payload();

        self::assertTrue($second['duplicate']);
        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame($first['reference'], $second['reference'], 'A retry must not renumber the patrol.');

        self::assertCount(
            1,
            $this->em->getRepository(Patrol::class)->findAll(),
            'A retried create produced a second patrol.',
        );
    }

    #[Test]
    public function aReSentTrackBatchDoesNotDoubleTheTrack(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $batch = [
            'batchUuid' => "{$patrolUuid}:track:0",
            'points' => [
                ['lat' => -3.2014, 'lon' => 35.4623, 'recordedAt' => '2026-08-23T06:44:17Z'],
                ['lat' => -3.2020, 'lon' => 35.4630, 'recordedAt' => '2026-08-23T06:45:17Z'],
            ],
        ];

        $this->postJson("/api/patrols/{$patrolUuid}/track", $batch);
        self::assertFalse($this->payload()['duplicate']);

        $this->postJson("/api/patrols/{$patrolUuid}/track", $batch);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate']);

        $patrol = $this->reloadPatrol($patrolUuid);
        self::assertSame(2, $patrol->getPointCount(), 'The re-sent batch was stored twice.');
        self::assertCount(1, $patrol->getTrackBatches());
    }

    #[Test]
    public function batchesArrivingOutOfOrderStillBuildOneForwardRoute(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        // Batch 1 lands before batch 0 — a dropped connection, retried later.
        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:1",
            'points' => [['lat' => -3.2031, 'lon' => 35.4644, 'recordedAt' => '2026-08-23T06:46:17Z']],
        ]);
        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:0",
            'points' => [['lat' => -3.2014, 'lon' => 35.4623, 'recordedAt' => '2026-08-23T06:44:17Z']],
        ]);
        self::assertResponseIsSuccessful();

        $track = json_decode((string) $this->reloadPatrol($patrolUuid)->getTrack(), true);
        self::assertIsArray($track);

        // Ordered by when the PHONE recorded them, not by when they arrived.
        self::assertSame(
            [[35.4623, -3.2014], [35.4644, -3.2031]],
            $track['coordinates'],
            'The route was stitched in upload order instead of recorded order.',
        );
    }

    #[Test]
    public function completeRefusesWhileAPromisedPhotoIsStillMissing(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $observationUuid = 'b23f0e77-0000-4000-8000-000000000002';
        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => $observationUuid,
                'category' => 'maintenance',
                'loggedAt' => '2026-08-23T08:31:02Z',
                // Two promised; none sent.
                'photoCount' => 2,
            ]],
        ]);
        self::assertResponseIsSuccessful();

        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);

        self::assertResponseStatusCodeSame(409);
        $error = $this->payload();
        self::assertSame('incomplete_patrol', $error['code']);
        self::assertFalse($error['retryable'], 'Re-sending complete unchanged would fail identically.');

        $details = $error['details'];
        self::assertIsArray($details);
        self::assertSame(
            [['observationUuid' => $observationUuid, 'expectedPhotos' => 2, 'heldPhotos' => 0]],
            $details['missingPhotos'],
            'The app re-queues exactly the parts named here.',
        );

        self::assertSame(
            PatrolStatusEnum::Recording,
            $this->reloadPatrol($patrolUuid)->getStatus(),
            'A patrol missing its evidence must not be published.',
        );
    }

    #[Test]
    public function completingTwiceIsSuccessAndSaysSo(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['duplicate']);

        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate']);
        self::assertSame('complete', $this->payload()['status']);
    }

    #[Test]
    public function aReUploadedPhotoIsAcceptedButStoredOnlyOnce(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $observationUuid = 'b23f0e77-0000-4000-8000-000000000003';
        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => $observationUuid,
                'category' => 'maintenance',
                'loggedAt' => '2026-08-23T08:31:02Z',
                'photoCount' => 1,
            ]],
        ]);

        $photoUuid = 'e77c0000-0000-4000-8000-000000000009';
        $this->uploadPhoto($observationUuid, $photoUuid);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['duplicate']);

        // The phone did not see the acknowledgement, so it sends it again.
        $this->uploadPhoto($observationUuid, $photoUuid);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate']);

        $this->em->clear();
        $patrol = $this->reloadPatrol($patrolUuid);
        $observation = $patrol->getObservations()->first();
        self::assertNotFalse($observation);
        self::assertCount(1, $observation->getPhotos(), 'The photo was stored twice.');
    }

    #[Test]
    public function anOperatorMarkedPositionIsNeverRecordedAsAGpsFix(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol(['type' => 'drone', 'droneId' => 'DJI-01', 'mission' => 'Sector sweep']);

        $observationUuid = 'b23f0e77-0000-4000-8000-000000000004';
        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => $observationUuid,
                'category' => 'maintenance',
                'position' => ['lat' => -3.1966, 'lon' => 35.4339],
                'positionSource' => 'operator_marked',
                'loggedAt' => '2026-08-23T07:02:00Z',
                'photoCount' => 0,
            ]],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $observation = $this->reloadPatrol($patrolUuid)->getObservations()->first();
        self::assertNotFalse($observation);
        self::assertSame('operator_marked', $observation->getPositionSource()->value);
        self::assertFalse(
            $observation->getPositionSource()->isMeasured(),
            'An operator-marked point must not claim to be a measurement.',
        );
    }

    #[Test]
    public function anUnsupportedObservationCategoryIsRefused(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => 'b23f0e77-0000-4000-8000-000000000005',
                'category' => 'unicorn',
                'loggedAt' => '2026-08-23T08:31:02Z',
            ]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported_category', $this->payload()['code']);
    }

    #[Test]
    public function aPatrolEditedInTheWebModuleStopsAcceptingUploads(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        // Somebody corrects it in the web module.
        $patrol = $this->reloadPatrol($patrolUuid);
        $patrol->markWebEdited();
        $this->em->flush();
        $this->em->clear();

        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:7",
            'points' => [['lat' => -3.2014, 'lon' => 35.4623, 'recordedAt' => '2026-08-23T06:44:17Z']],
        ]);

        self::assertResponseStatusCodeSame(409);
        $error = $this->payload();
        self::assertSame('patrol_immutable', $error['code']);
        self::assertFalse($error['retryable'], 'The app must stop trying, not loop.');
    }

    #[Test]
    public function postingAPartToAnUnknownPatrolIsRefused(): void
    {
        $this->actingAs($this->recorder);

        $this->postJson('/api/patrols/1e1f4e02-6b1a-4f34-8f8f-1a0f19a1cfff/complete', []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('unknown_patrol', $this->payload()['code']);
    }

    private function uploadPhoto(string $observationUuid, string $photoUuid): void
    {
        $this->client->request(
            'POST',
            "/api/observations/{$observationUuid}/photos",
            parameters: ['clientUuid' => $photoUuid, 'takenAt' => '2026-08-23T08:31:02Z'],
            files: ['file' => $this->jpegUpload($photoUuid.'.jpg')],
            server: $this->apiHeaders(),
        );
    }

    private function reloadPatrol(string $clientUuid): Patrol
    {
        $patrol = $this->em->getRepository(Patrol::class)
            ->findOneBy(['clientUuid' => Uuid::fromString($clientUuid)]);

        self::assertInstanceOf(Patrol::class, $patrol);

        return $patrol;
    }
}
