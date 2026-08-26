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

use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Service\PhotoEvidenceKey;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;
use UhifadhiLabs\Storage\Service\EvidenceKey;

/**
 * patrol:photos:backfill-thumbs — the previews for photographs that arrived
 * before this module adopted storage-module.
 *
 * The fixtures use the LEGACY key shape on purpose (`patrol-<uuid>/<uuid>.jpg`),
 * because that is the only shape the backfill will ever meet in a real
 * deployment: everything stored since adoption already had a preview made at
 * upload time. The test therefore also pins the cut-over promise — that pointing
 * the evidence storage at the old directory makes yesterday's paths today's
 * keys, unchanged.
 */
final class BackfillPhotoThumbsCommandTest extends IntegrationTestCase
{
    private Observation $observation;

    protected function setUp(): void
    {
        parent::setUp();

        $area = new AreaOfInterest()->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($area);

        $patrol = new Patrol($area, 'walk')
            ->setClientUuid(Uuid::fromString('8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111'));
        $this->em->persist($patrol);

        $this->observation = new Observation($patrol, 'maintenance');
        $this->em->persist($this->observation);
        $this->em->flush();
    }

    public function testItGeneratesThePreviewAPreAdoptionPhotoNeverHad(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000001', withBytes: true);

        $tester = $this->backfill();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 photo without a preview', $tester->getDisplay());

        $this->em->refresh($photo);
        $thumbKey = $photo->getThumbKey();
        self::assertIsString($thumbKey, 'A JPEG must get a preview where GD is available.');
        self::assertSame(EvidenceKey::thumb($photo->getStoragePath()), $thumbKey);
        self::assertTrue($this->evidence()->fileExists($thumbKey));

        // A preview is genuinely smaller than what it previews — the whole point
        // of making one.
        self::assertLessThan(
            $this->evidence()->fileSize($photo->getStoragePath()),
            $this->evidence()->fileSize($thumbKey),
        );
    }

    /** The legacy path is a valid evidence key exactly as it stands. */
    public function testALegacyPathIsClaimedByThisModule(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000002', withBytes: true);

        self::assertStringStartsWith(PhotoEvidenceKey::LEGACY_PREFIX, $photo->getStoragePath());
        self::assertTrue(PhotoEvidenceKey::claims($photo->getStoragePath()));
        self::assertTrue(EvidenceKey::isValid($photo->getStoragePath()));
    }

    public function testASecondRunDoesNothing(): void
    {
        $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000003', withBytes: true);

        $this->backfill();
        $second = $this->backfill();

        self::assertSame(0, $second->getStatusCode());
        self::assertStringContainsString('already has a preview', $second->getDisplay());
    }

    /**
     * A row whose bytes are gone. Reported and skipped — deciding what a
     * photograph with no file means is not a thumbnail command's call, and
     * failing the run would leave every later photo unprocessed.
     */
    public function testAPhotoWhoseBytesAreMissingIsReportedAndSkipped(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000004', withBytes: false);

        $tester = $this->backfill();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('missing', $tester->getDisplay());
        $this->em->refresh($photo);
        self::assertNull($photo->getThumbKey());
    }

    public function testDryRunWritesNothing(): void
    {
        $photo = $this->legacyPhoto('a1b2c3d4-0000-4000-8000-000000000005', withBytes: true);

        $tester = $this->backfill(['--dry-run' => true]);

        self::assertStringContainsString('Dry run', $tester->getDisplay());
        $this->em->refresh($photo);
        self::assertNull($photo->getThumbKey());
        self::assertFalse($this->evidence()->fileExists(EvidenceKey::thumb($photo->getStoragePath())));
    }

    /**
     * A photograph filed the way this module filed them BEFORE storage-module:
     * `patrol-<uuid>/<clientUuid>.jpg`, and no preview.
     */
    private function legacyPhoto(string $clientUuid, bool $withBytes): ObservationPhoto
    {
        $patrol = $this->observation->getPatrol();
        $key = PhotoEvidenceKey::LEGACY_PREFIX.$patrol->getClientUuid()?->toRfc4122().'/'.$clientUuid.'.jpg';

        if ($withBytes) {
            $this->evidence()->write($key, $this->jpegBytes());
        }

        $photo = new ObservationPhoto($this->observation, Uuid::fromString($clientUuid), $key)
            ->setMimeType('image/jpeg');
        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    /** Real bytes, big enough that a 400px preview is genuinely a reduction. */
    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(1200, 900);
        self::assertNotFalse($image);
        for ($x = 0; $x < 1200; $x += 40) {
            $colour = imagecolorallocate($image, $x % 255, (2 * $x) % 255, 200);
            self::assertNotFalse($colour);
            imagefilledrectangle($image, $x, 0, $x + 20, 900, $colour);
        }

        ob_start();
        imagejpeg($image, null, 92);

        return (string) ob_get_clean();
    }

    /** @param array<string, bool|string> $input */
    private function backfill(array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('patrol:photos:backfill-thumbs'));
        $tester->execute($input);

        return $tester;
    }

    private function evidence(): FilesystemOperator
    {
        $storage = static::getContainer()->get('storage.evidence');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }
}
