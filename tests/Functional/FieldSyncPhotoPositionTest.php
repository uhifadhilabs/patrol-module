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
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;

/**
 * WHERE THE SHUTTER FIRED (API-CONTRACT.md §8).
 *
 * The handset has been sending `lat` / `lng` / `accuracyM` as multipart parts on
 * every photo upload since the app shipped; the server accepted the request and
 * dropped them on the floor, because unknown parts are simply not read. So a
 * photograph taken two kilometres from the observation it was filed under was
 * stored as if it had no place at all, and the only copy of that fact was the
 * JPEG's own EXIF block — which nothing on the web ever opens.
 *
 * OMITTED, NEVER ZERO. The contract is explicit that a photograph taken with no
 * fix arrives with all three parts absent rather than zeroed, because `lat=0,
 * lng=0` is a real place in the Gulf of Guinea and storing it would put every
 * unpositioned photograph on the same island.
 */
final class FieldSyncPhotoPositionTest extends FieldSyncTestCase
{
    private const string OBSERVATION = 'c3a10000-0000-4000-8000-0000000000a1';

    #[Test]
    public function aPhotographKeepsWhereTheShutterFired(): void
    {
        $this->anObservation();

        $this->upload('0a000000-0000-4000-8000-000000000001', [
            'lat' => '-3.2014',
            'lng' => '35.4623',
            'accuracyM' => '6.0',
        ]);

        self::assertResponseIsSuccessful();
        $photo = $this->reloadPhoto('0a000000-0000-4000-8000-000000000001');

        // Stored as GeoJSON with LON FIRST — RFC 7946's order and PostGIS's, and
        // the single most common way to store a map upside down.
        self::assertSame(
            '{"type":"Point","coordinates":[35.4623,-3.2014]}',
            $photo->getPosition(),
        );
        self::assertSame(6.0, $photo->getAccuracyM());
    }

    /** No fix, no place — and emphatically not 0,0. */
    #[Test]
    public function aPhotographTakenWithNoFixIsStoredWithNoPlace(): void
    {
        $this->anObservation();

        $this->upload('0a000000-0000-4000-8000-000000000002', []);

        self::assertResponseIsSuccessful();
        $photo = $this->reloadPhoto('0a000000-0000-4000-8000-000000000002');
        self::assertNull($photo->getPosition(), 'A photograph with no fix must not be given one.');
        self::assertNull($photo->getAccuracyM());
    }

    /**
     * HALF A COORDINATE IS A BUG, NOT AN ABSENCE. The contract says the three
     * parts are omitted together; one of a pair means something upstream went
     * wrong, and silently storing "no position" would hide it.
     */
    #[Test]
    public function halfACoordinateIsRefused(): void
    {
        $this->anObservation();

        $this->upload('0a000000-0000-4000-8000-000000000003', ['lat' => '-3.2014']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_payload', $this->payload()['code']);
    }

    /**
     * AN ACCURACY IS A PROPERTY OF A FIX. Without one there is nothing for it to
     * describe, so it is refused rather than stored as a number about nothing.
     */
    #[Test]
    public function anAccuracyWithoutAPositionIsRefused(): void
    {
        $this->anObservation();

        $this->upload('0a000000-0000-4000-8000-000000000004', ['accuracyM' => '6.0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_payload', $this->payload()['code']);
    }

    /** The same bounds check every other position on this API goes through. */
    #[Test]
    public function aPositionThatIsNotOnEarthIsRefused(): void
    {
        $this->anObservation();

        $this->upload('0a000000-0000-4000-8000-000000000005', ['lat' => '99.0', 'lng' => '35.4623']);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * A RE-UPLOAD STILL STORES NOTHING TWICE — including the position. The
     * second request names a place the first did not; the held photograph is
     * returned untouched, because idempotency means the second call changes
     * nothing at all, not "nothing except the new fields".
     */
    #[Test]
    public function aReUploadDoesNotRewriteThePositionAlreadyHeld(): void
    {
        $this->anObservation();
        $uuid = '0a000000-0000-4000-8000-000000000006';

        $this->upload($uuid, ['lat' => '-3.2014', 'lng' => '35.4623', 'accuracyM' => '6.0']);
        self::assertResponseIsSuccessful();

        $this->upload($uuid, ['lat' => '-1.0', 'lng' => '30.0', 'accuracyM' => '99.0']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate']);

        $photo = $this->reloadPhoto($uuid);
        self::assertSame('{"type":"Point","coordinates":[35.4623,-3.2014]}', $photo->getPosition());
        self::assertSame(6.0, $photo->getAccuracyM());
    }

    /**
     * AND IT IS SAID ON THE PAGE. A fact stored and never shown is a fact
     * nobody can act on, and the observation screen is where a photograph is
     * read.
     */
    #[Test]
    public function theObservationPageSaysWhereThePhotographWasTaken(): void
    {
        $this->anObservation();
        $this->upload('0a000000-0000-4000-8000-000000000007', [
            'lat' => '-3.2014',
            'lng' => '35.4623',
            'accuracyM' => '6.0',
        ]);

        $photo = $this->reloadPhoto('0a000000-0000-4000-8000-000000000007');
        $observation = $photo->getObservation();
        $patrol = $observation->getPatrol();

        $this->client->request('GET', \sprintf(
            '/areas/%s/modules/patrols/%s/observations/%s',
            $this->area->getUuid()->toRfc4122(),
            $patrol->getUuid()->toRfc4122(),
            $observation->getUuid()->toRfc4122(),
        ));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        // The module's own DMS, the same reading the observation's position gets
        // — never raw decimals, which nobody reads off a map.
        self::assertStringContainsString('3°12', $html, 'The photograph does not say where it was taken.');
    }

    /**
     * A photograph with no fix says so, rather than borrowing the observation's
     * place. They are different facts: a ranger may walk on and photograph
     * something from somewhere else.
     */
    #[Test]
    public function aPhotographWithNoFixSaysSoRatherThanBorrowingTheObservations(): void
    {
        $this->anObservation();
        $this->upload('0a000000-0000-4000-8000-000000000008', []);

        $photo = $this->reloadPhoto('0a000000-0000-4000-8000-000000000008');
        $observation = $photo->getObservation();

        $this->client->request('GET', \sprintf(
            '/areas/%s/modules/patrols/%s/observations/%s',
            $this->area->getUuid()->toRfc4122(),
            $observation->getPatrol()->getUuid()->toRfc4122(),
            $observation->getUuid()->toRfc4122(),
        ));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'no position recorded',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** One observation on one patrol, uploaded the way the app uploads it. */
    private function anObservation(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => self::OBSERVATION,
                'category' => 'maintenance',
                'note' => 'Culvert blocked.',
                'loggedAt' => '2026-08-23T08:31:02Z',
                'photoCount' => 1,
                'position' => ['lat' => -3.2014, 'lon' => 35.4623, 'accuracyM' => 4.0],
            ]],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @param array<string, string> $position the lat/lng/accuracyM parts, exactly as sent */
    private function upload(string $photoUuid, array $position): void
    {
        $this->client->request(
            'POST',
            '/api/observations/'.self::OBSERVATION.'/photos',
            parameters: [
                'clientUuid' => $photoUuid,
                'takenAt' => '2026-08-23T08:31:02Z',
                ...$position,
            ],
            files: ['file' => $this->jpegUpload($photoUuid.'.jpg')],
            server: $this->apiHeaders(),
        );
    }

    private function reloadPhoto(string $clientUuid): ObservationPhoto
    {
        $this->em->clear();
        $photo = $this->em->getRepository(ObservationPhoto::class)
            ->findOneBy(['clientUuid' => Uuid::fromString($clientUuid)]);

        self::assertInstanceOf(ObservationPhoto::class, $photo);

        return $photo;
    }
}
