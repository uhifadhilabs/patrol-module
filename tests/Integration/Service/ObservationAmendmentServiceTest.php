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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationAmendment;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\ObservationAmendmentKindEnum;
use Uhifadhi\Patrol\Service\ObservationAmendmentService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * APPENDING ONE CORRECTION, asserted on the rows it left behind.
 *
 * The write is a persistence write with a file store on the side — the
 * photograph goes through the platform's evidence storage — so the honest first
 * test is this one and not a unit test around a mocked filesystem: what has to
 * be true is that the amendment, its author's name, the value it supersedes and
 * the stored photograph are all in the database, and that the original is
 * exactly as it was.
 */
final class ObservationAmendmentServiceTest extends IntegrationTestCase
{
    private const string ORIGINAL_NOTE = 'Two sets of tracks, likely adult and subadult.';

    public function testItAppendsASignedCorrectionAndLeavesTheOriginalAlone(): void
    {
        $observation = $this->anObservation();
        $author = $this->aPerson();

        $amendment = $this->amendments()->append(
            $observation,
            ObservationAmendmentKindEnum::Note,
            'Three sets, not two.',
            $author,
            'Two sets, likely adult and subadult.',
        );

        $this->em->clear();
        $stored = $this->em->find(ObservationAmendment::class, $amendment->getId());
        self::assertInstanceOf(ObservationAmendment::class, $stored);

        self::assertSame('Three sets, not two.', $stored->getBody());
        self::assertSame(ObservationAmendmentKindEnum::Note, $stored->getKind());
        self::assertSame('Two sets, likely adult and subadult.', $stored->getSupersededValue());
        self::assertSame($author->getId(), $stored->getAuthor()?->getId());

        // The name is COPIED at the time of writing, so the trail can still be
        // read after the person has left the service.
        self::assertSame($author->getFullName(), $stored->getAuthorName());

        // PL·09: the original is never edited.
        $original = $this->em->find(Observation::class, $observation->getId());
        self::assertInstanceOf(Observation::class, $original);
        self::assertSame(self::ORIGINAL_NOTE, $original->getNote());
    }

    /**
     * THE PHOTOGRAPH GOES THROUGH THE EVIDENCE PATH and comes back marked as an
     * amendment attachment — which is what keeps it out of the observation's own
     * evidence strip and out of its completeness count.
     */
    public function testAnAttachedPhotographIsStoredAndMarkedAsAnAttachment(): void
    {
        $observation = $this->anObservation();

        $amendment = $this->amendments()->append(
            $observation,
            ObservationAmendmentKindEnum::Photo,
            'The photograph shows the far side of the drainage.',
            $this->aPerson(),
            null,
            $this->aJpeg(),
        );

        $this->em->clear();
        $stored = $this->em->find(ObservationAmendment::class, $amendment->getId());
        self::assertInstanceOf(ObservationAmendment::class, $stored);

        $photo = $stored->getPhoto();
        self::assertInstanceOf(ObservationPhoto::class, $photo);
        self::assertTrue($photo->isAmendmentAttachment());
        self::assertSame('image/jpeg', $photo->getMimeType());
        self::assertNotSame('', $photo->getStoragePath());
        self::assertGreaterThan(0, $photo->getByteSize());
    }

    /** No file is the ordinary case: most corrections are words. */
    public function testACorrectionWithoutAPhotographStoresNoPhoto(): void
    {
        $amendment = $this->amendments()->append(
            $this->anObservation(),
            ObservationAmendmentKindEnum::Note,
            'A correction.',
            $this->aPerson(),
        );

        $this->em->clear();
        $stored = $this->em->find(ObservationAmendment::class, $amendment->getId());
        self::assertInstanceOf(ObservationAmendment::class, $stored);
        self::assertNull($stored->getPhoto());
        self::assertSame(0, $this->em->getRepository(ObservationPhoto::class)->count([]));
    }

    private function amendments(): ObservationAmendmentService
    {
        $service = $this->service(ObservationAmendmentService::class);
        \assert($service instanceof ObservationAmendmentService);

        return $service;
    }

    private function anObservation(): Observation
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Example reserve')->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);

        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))->setStartedAt(new \DateTimeImmutable('2026-03-01 06:00:00'));
        $this->em->persist($patrol);

        $observation = new Observation($patrol, 'wildlife')
            ->setNote(self::ORIGINAL_NOTE)
            ->setLoggedAt(new \DateTimeImmutable('2026-03-01 06:20:00'));
        $this->em->persist($observation);
        $this->em->flush();

        return $observation;
    }

    private function aPerson(): User
    {
        $user = new User()->setPassword('x')->setEmail('amender@example.test')->setFirstName('Sara')->setLastName('Example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function aJpeg(): \SplFileInfo
    {
        $image = imagecreatetruecolor(48, 36);
        \assert(false !== $image);
        $path = (string) tempnam(sys_get_temp_dir(), 'patrol_amend_photo_');
        imagejpeg($image, $path);

        return new \SplFileInfo($path);
    }
}
