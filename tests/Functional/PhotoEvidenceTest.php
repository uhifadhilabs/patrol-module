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

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;
use UhifadhiLabs\Patrol\Service\PhotoEvidenceKey;
use UhifadhiLabs\Storage\Service\EvidenceKey;

/**
 * A photograph's whole life, after the module adopted storage-module: the phone
 * uploads it, the platform's private evidence storage holds it under a key this
 * module owns, a preview is made beside it, and it comes back out only through
 * the authenticated serving route and only for someone entitled to the page it
 * appears on.
 *
 * Real bytes throughout — a mocked filesystem would only prove the mock, and the
 * one thing that must be true here is that a ranger's photograph is genuinely on
 * disk and genuinely readable back.
 */
final class PhotoEvidenceTest extends FieldSyncTestCase
{
    public function testAnUploadedPhotoIsStoredUnderAPatrolOwnedEvidenceKey(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();
        $observationUuid = $this->logObservation($patrolUuid);

        $photoUuid = 'e77c0000-0000-4000-8000-0000000000a1';
        $this->uploadPhoto($observationUuid, $photoUuid);

        self::assertResponseIsSuccessful();

        $photo = $this->photo($photoUuid);
        // The key is RELATIVE and prefixed with the segment this module's voter
        // claims — that pairing is the whole permission story.
        self::assertSame(PhotoEvidenceKey::PREFIX.'/'.$patrolUuid.'/'.$photoUuid.'.jpg', $photo->getStoragePath());
        self::assertTrue(PhotoEvidenceKey::claims($photo->getStoragePath()));

        // The bytes really landed, and the storage is the platform's, not a
        // directory this module invented.
        self::assertTrue($this->evidence()->fileExists($photo->getStoragePath()));

        // The DETECTED type — the deliberate behaviour change of the adoption.
        // The upload announced itself as image/jpeg and is one; what matters is
        // that the column now records what the BYTES are.
        self::assertSame('image/jpeg', $photo->getMimeType());
        self::assertNotNull($photo->getByteSize());
        self::assertGreaterThan(0, (int) $photo->getByteSize());
    }

    public function testAPreviewIsWrittenBesideTheOriginal(): void
    {
        $this->actingAs($this->recorder);
        $observationUuid = $this->logObservation($this->createPatrol());

        $photoUuid = 'e77c0000-0000-4000-8000-0000000000a2';
        $this->uploadPhoto($observationUuid, $photoUuid);

        $photo = $this->photo($photoUuid);
        $thumbKey = $photo->getThumbKey();

        // GD is present wherever these tests run (the fixture JPEG is drawn with
        // it), so a JPEG must produce a preview here. A null would be legitimate
        // for HEIC and is why the column is nullable — but not for this.
        self::assertIsString($thumbKey);
        self::assertSame(EvidenceKey::thumb($photo->getStoragePath()), $thumbKey);
        self::assertTrue($this->evidence()->fileExists($thumbKey));
        // What the page asks the serving route for.
        self::assertSame($thumbKey, $photo->getDisplayKey());
    }

    /**
     * The rule the phone depends on: it does not delete its copy until the
     * upload is acknowledged, so it WILL re-send. Preserved across the rewire —
     * it is patrol's rule, about patrol's unique index, and did not move.
     */
    public function testAReUploadedPhotoIsAcceptedButStoredOnlyOnce(): void
    {
        $this->actingAs($this->recorder);
        $observationUuid = $this->logObservation($this->createPatrol());

        $photoUuid = 'e77c0000-0000-4000-8000-0000000000a3';
        $this->uploadPhoto($observationUuid, $photoUuid);
        self::assertFalse($this->payload()['duplicate']);

        $this->uploadPhoto($observationUuid, $photoUuid);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate'], 'A re-sent photo must be acknowledged as a duplicate.');
        self::assertSame([$photoUuid], $this->payload()['acceptedUuids']);

        self::assertCount(
            1,
            $this->em->getRepository(ObservationPhoto::class)->findBy(['clientUuid' => Uuid::fromString($photoUuid)]),
            'The photo was stored twice.',
        );
    }

    /** Not a photograph: refused, and — the point of validating first — nothing left behind. */
    public function testAFileThatIsNotAPhotographIsRefusedAndStoresNothing(): void
    {
        $this->actingAs($this->recorder);
        $observationUuid = $this->logObservation($this->createPatrol());

        $photoUuid = 'e77c0000-0000-4000-8000-0000000000a4';
        $path = tempnam(sys_get_temp_dir(), 'patrol-notaphoto').'.jpg';
        file_put_contents($path, "<?php echo 'not a photograph';");

        $this->client->request(
            'POST',
            "/api/observations/{$observationUuid}/photos",
            parameters: ['clientUuid' => $photoUuid],
            files: ['file' => new UploadedFile($path, $photoUuid.'.jpg', 'image/jpeg', null, true)],
            server: $this->apiHeaders(),
        );

        self::assertResponseStatusCodeSame(422);
        $body = $this->payload();
        self::assertSame('invalid_payload', $body['code'] ?? null);
        self::assertNull(
            $this->em->getRepository(ObservationPhoto::class)->findOneBy(['clientUuid' => Uuid::fromString($photoUuid)]),
        );
    }

    public function testTheServingRouteReturnsThePhotoToASignedInReader(): void
    {
        $this->actingAs($this->recorder);
        $observationUuid = $this->logObservation($this->createPatrol());
        $photoUuid = 'e77c0000-0000-4000-8000-0000000000a5';
        $this->uploadPhoto($observationUuid, $photoUuid);
        $photo = $this->photo($photoUuid);

        $this->client->loginUser($this->bystander);
        $this->client->request('GET', '/storage/evidence/'.$photo->getStoragePath());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        // Evidence never sits in a shared cache and is never offered as a
        // download something could then run.
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // And the preview, which is what the page actually draws.
        $this->client->request('GET', '/storage/evidence/'.$photo->getDisplayKey());
        self::assertResponseIsSuccessful();
    }

    public function testTheServingRouteRefusesAReaderWhoIsNotSignedIn(): void
    {
        $this->actingAs($this->recorder);
        $observationUuid = $this->logObservation($this->createPatrol());
        $photoUuid = 'e77c0000-0000-4000-8000-0000000000a6';
        $this->uploadPhoto($observationUuid, $photoUuid);
        $key = $this->photo($photoUuid)->getStoragePath();

        // A fresh client, carrying nobody's session.
        self::ensureKernelShutdown();
        $anonymous = self::createClient();
        $anonymous->request('GET', '/storage/evidence/'.$key);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A key shaped like patrol's but naming nothing this module holds. Refused —
     * claiming a key commits the module to deciding it, and it decides no.
     */
    public function testAKeyThisModuleDoesNotHoldIsRefusedEvenToASignedInReader(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request(
            'GET',
            '/storage/evidence/patrol/8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111/deadbeef-0000-4000-8000-000000000000.jpg',
        );

        self::assertResponseStatusCodeSame(403);
    }

    /** A key belonging to nobody at all: denied by default, before existence is even asked. */
    public function testAnUnclaimedPrefixIsDenied(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request('GET', '/storage/evidence/incident/abc/def.jpg');

        self::assertResponseStatusCodeSame(403);
    }

    private function evidence(): FilesystemOperator
    {
        $storage = static::getContainer()->get('storage.evidence');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }

    private function photo(string $clientUuid): ObservationPhoto
    {
        $this->em->clear();
        $photo = $this->em->getRepository(ObservationPhoto::class)
            ->findOneBy(['clientUuid' => Uuid::fromString($clientUuid)]);
        self::assertInstanceOf(ObservationPhoto::class, $photo);

        return $photo;
    }

    /** One observation on a patrol, the way the app logs one. */
    private function logObservation(string $patrolUuid): string
    {
        $observationUuid = 'c0de0000-0000-4000-8000-0000000000b1';

        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => $observationUuid,
                'category' => 'maintenance',
                'note' => 'Fence line down.',
                'position' => ['lat' => -3.2014, 'lon' => 35.4623, 'accuracyM' => 4.0, 'satellites' => 9],
                'positionSource' => 'gps',
                'loggedAt' => '2026-08-23T08:31:02Z',
                'photoCount' => 1,
            ]],
        ]);
        self::assertResponseIsSuccessful();

        return $observationUuid;
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
}
