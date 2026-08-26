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

namespace UhifadhiLabs\Patrol\Tests\Integration\Storage;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Security\PatrolEvidenceVoter;
use UhifadhiLabs\Patrol\Storage\PatrolFileSource;
use UhifadhiLabs\Patrol\Tests\Integration\IntegrationTestCase;
use UhifadhiLabs\Storage\Enum\GuardStateEnum;
use UhifadhiLabs\Storage\Registry\FileRegistry;

/**
 * PATROL REACHES THE FILES HUB — through the tag, not through a template.
 *
 * A source that is written but not tagged fails invisibly: the hub grows by
 * MODULES, so a module whose source was never collected looks exactly like a
 * module nobody installed. Nothing throws and nothing is logged; there is simply
 * one fewer heading on /files. That is why this test asks the REGISTRY — the
 * same tagged iterator the hub reads — rather than the source directly.
 */
final class PatrolFileSourceRegistrationTest extends IntegrationTestCase
{
    public function testTheSourceIsCollectedByTheRegistryTheHubReads(): void
    {
        $sources = [];
        foreach ($this->registry()->modules() as $module) {
            $sources[] = $module['slug'];
        }

        self::assertContains('patrols', $sources);
    }

    /**
     * A module that ships a source but holds nothing is still LISTED — "we have
     * that and it is empty" is a different fact from "we do not have it".
     */
    public function testAModuleHoldingNoPhotographsStillAppears(): void
    {
        self::assertSame([], $this->registry()->all());

        $modules = $this->registry()->modules();
        self::assertCount(1, $modules);
        self::assertSame(0, $modules[0]['files']);
        self::assertSame('Patrols', $modules[0]['label']);
        self::assertNotSame('', $modules[0]['attachesTo']);
    }

    public function testAStoredPhotographReachesTheHubCarryingItsObservationAndItsArea(): void
    {
        $photo = $this->storedPhoto();

        $files = $this->registry()->all();
        self::assertCount(1, $files);

        $entry = $files[0];
        self::assertSame($photo->getStoragePath(), $entry->key);
        self::assertSame($photo->getObservation()->getRef(), $entry->ownerLabel);
        self::assertSame('Ndovu Sector', $entry->areaLabel);
        self::assertIsString($entry->ownerUrl);
        self::assertStringContainsString(
            $photo->getObservation()->getUuid()->toRfc4122(),
            (string) $entry->ownerUrl,
        );
    }

    /** Evidence is never removed from the web — the hub is told so, by patrol. */
    public function testTheHubIsToldThePhotographIsLockedToItsObservation(): void
    {
        $photo = $this->storedPhoto();

        $guard = $this->registry()->guard($photo->getStoragePath(), null);

        self::assertSame(GuardStateEnum::Locked, $guard->state);
        self::assertFalse($guard->offersRemoval());
    }

    /**
     * THE ALIGNMENT. The source and the voter answer two different questions —
     * may you read these bytes, may you take this file off its record — but they
     * must claim exactly the same KEYS. A disagreement is a tile on the hub
     * whose image the serving route refuses, or a photograph the hub silently
     * drops. Both read PhotoEvidenceKey; this proves the wiring did not drift.
     */
    public function testTheSourceAndTheEvidenceVoterClaimTheSameKeys(): void
    {
        /** @var PatrolFileSource $source */
        $source = $this->service(PatrolFileSource::class);
        /** @var PatrolEvidenceVoter $voter */
        $voter = $this->service(PatrolEvidenceVoter::class);

        foreach ([
            'patrol/1f0aa/e77c.jpg',
            'patrol/1f0aa/e77c.jpg.thumb.jpg',
            'patrol-1f0aa/e77c.jpg',
            'incident/1f0aa/e77c.jpg',
            'patrols/1f0aa/e77c.jpg',
            '',
        ] as $key) {
            self::assertSame(
                $voter->claimsKey($key),
                $source->claimsKey($key),
                \sprintf('The source and the voter disagree about "%s".', $key),
            );
        }
    }

    private function registry(): FileRegistry
    {
        /** @var FileRegistry $registry */
        $registry = $this->service(FileRegistry::class);

        return $registry;
    }

    private function storedPhoto(): ObservationPhoto
    {
        $area = new AreaOfInterest();
        $area->setName('Ndovu Sector');
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[36.0,-3.0],[36.1,-3.0],[36.1,-2.9],[36.0,-2.9],[36.0,-3.0]]]]}');
        $this->em->persist($area);

        $patrol = new Patrol($area, 'walk');
        $patrol->setStartedAt(new \DateTimeImmutable('2026-08-19 05:30:00'));
        $this->em->persist($patrol);

        $observation = new Observation($patrol, 'maintenance');
        $this->em->persist($observation);

        $photo = new ObservationPhoto(
            $observation,
            Uuid::fromString('e77c0000-0000-4000-8000-000000000001'),
            'patrol/'.$patrol->getUuid()->toRfc4122().'/e77c.jpg',
        );
        $photo
            ->setMimeType('image/jpeg')
            ->setByteSize(204_800)
            ->setTakenAt(new \DateTimeImmutable('2026-08-19 06:41:00'));
        $this->em->persist($photo);

        $this->em->flush();

        return $photo;
    }
}
