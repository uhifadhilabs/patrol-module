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
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Service\PatrolRecordingService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase;

/**
 * The hand-written patrol, against a real database: what the log screen calls in
 * to, asserted on the row that comes back rather than on the object that went
 * in.
 */
final class PatrolRecordingServiceTest extends IntegrationTestCase
{
    public function testAHandWrittenPatrolIsStoredAsASketch(): void
    {
        $area = $this->makeArea();
        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Alex')->setLastName('Example');
        $this->em->persist($lead);
        $this->em->flush();

        $patrol = $this->recording()->record(
            $area,
            Vocabulary::type($this->em, $area, 'walk'),
            new \DateTimeImmutable('2026-03-01 06:00:00'),
            new \DateTimeImmutable('2026-03-01 09:30:00'),
            Vocabulary::station($this->em, $area, 'North post'),
            $lead,
            'B. Example, C. Example',
            'Written up from the duty log.',
            8.4,
        );

        $this->em->clear();
        $stored = $this->em->find(Patrol::class, $patrol->getId());
        self::assertInstanceOf(Patrol::class, $stored);

        self::assertSame(PatrolSourceEnum::Manual, $stored->getSource());
        self::assertSame('walk', $stored->getType());
        self::assertSame('North post', $stored->getStation());
        self::assertSame('B. Example, C. Example', $stored->getTeam());
        self::assertSame('Written up from the duty log.', $stored->getNote());
        self::assertSame(8.4, $stored->getDistanceKm());
        self::assertEquals(new \DateTimeImmutable('2026-03-01 06:00:00'), $stored->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-03-01 09:30:00'), $stored->getEndedAt());
        self::assertSame('lead@example.test', $stored->getLead()?->getEmail());
        self::assertSame($area->getId(), $stored->getArea()->getId());

        // The columns a measured patrol fills, empty — the row itself says this
        // shift was written up rather than recorded.
        self::assertNull($stored->getTrack());
        self::assertNull($stored->getPointCount());
    }

    /** The reference every screen prints is minted by the store, so a stored patrol has one. */
    public function testTheStoredPatrolCarriesAReference(): void
    {
        $patrol = $this->recording()->record(
            $area = $this->makeArea(),
            Vocabulary::type($this->em, $area, 'walk'),
            new \DateTimeImmutable('2026-03-01 06:00:00'),
        );

        $this->em->clear();
        $stored = $this->em->find(Patrol::class, $patrol->getId());
        self::assertInstanceOf(Patrol::class, $stored);
        self::assertNotSame('', $stored->getRef());
    }

    private function recording(): PatrolRecordingService
    {
        $service = $this->service(PatrolRecordingService::class);
        \assert($service instanceof PatrolRecordingService);

        return $service;
    }

    private function makeArea(): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Example reserve')->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.0],[-29.9,-3.0],[-29.9,-2.9],[-30.0,-2.9],[-30.0,-3.0]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }
}
