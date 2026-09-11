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

namespace Uhifadhi\Patrol\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Exception\InvalidPatrolTimesException;
use Uhifadhi\Patrol\Service\PatrolRecordingService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * THE HAND-WRITTEN PATROL, AS A WRITE — the second door into this module's
 * records, beside {@see \Uhifadhi\Patrol\Service\TrackIngestService}.
 *
 * What is asserted here is the shape of the record: the source that separates a
 * written-up patrol from a recorded one for good, and the rule that a patrol
 * cannot end before it started. The persistence itself is asserted against a
 * real database in tests/Integration/Service.
 */
final class PatrolRecordingServiceTest extends TestCase
{
    public function testItStampsAHandWrittenPatrolAsManual(): void
    {
        $patrol = $this->service()->record(
            $area = $this->anArea(),
            Vocabulary::type(null, $area, 'foot'),
            new \DateTimeImmutable('2026-08-22 05:55'),
        );

        self::assertSame(PatrolSourceEnum::Manual, $patrol->getSource());
    }

    /**
     * A SKETCH CARRIES NO GEOMETRY. Nothing recorded the route, so there is no
     * track, no point count and no gap count to read — the fields a recorded
     * patrol fills and this one must leave empty, or a written-up shift could be
     * read back as a measured one.
     */
    public function testAHandWrittenPatrolCarriesNoTrack(): void
    {
        $patrol = $this->service()->record(
            $area = $this->anArea(),
            Vocabulary::type(null, $area, 'foot'),
            new \DateTimeImmutable('2026-08-22 05:55'),
        );

        self::assertNull($patrol->getTrack());
        self::assertNull($patrol->getPointCount());
        self::assertSame(0, $patrol->getGapCount());
    }

    public function testItKeepsEverythingTheFormContributed(): void
    {
        $patrol = $this->service()->record(
            $area = $this->anArea(),
            Vocabulary::type(null, $area, 'vehicle'),
            new \DateTimeImmutable('2026-08-22 05:55'),
            new \DateTimeImmutable('2026-08-22 09:10'),
            Vocabulary::station(null, $area, 'North Gate'),
            null,
            'A. Alpha, B. Bravo',
            'Quiet shift.',
            12.5,
        );

        self::assertSame('vehicle', $patrol->getType());
        self::assertSame('North Gate', $patrol->getStation());
        self::assertSame('A. Alpha, B. Bravo', $patrol->getTeam());
        self::assertSame('Quiet shift.', $patrol->getNote());
        self::assertSame(12.5, $patrol->getDistanceKm());
        self::assertEquals(new \DateTimeImmutable('2026-08-22 09:10'), $patrol->getEndedAt());
    }

    /** An open-ended patrol is a real state: the shift is written up before it is closed. */
    public function testAPatrolMayHaveNoEnd(): void
    {
        $patrol = $this->service()->record(
            $area = $this->anArea(),
            Vocabulary::type(null, $area, 'foot'),
            new \DateTimeImmutable('2026-08-22 05:55'),
        );

        self::assertNull($patrol->getEndedAt());
    }

    public function testItRefusesAPatrolThatEndsBeforeItStarted(): void
    {
        $this->expectException(InvalidPatrolTimesException::class);

        $this->service(writes: false)->record(
            $area = $this->anArea(),
            Vocabulary::type(null, $area, 'foot'),
            new \DateTimeImmutable('2026-08-22 09:10'),
            new \DateTimeImmutable('2026-08-22 05:55'),
        );
    }

    /** The same instant is not a patrol either — a shift with no duration was not walked. */
    public function testItRefusesAPatrolThatEndsTheInstantItStarted(): void
    {
        $this->expectException(InvalidPatrolTimesException::class);

        $this->service(writes: false)->record(
            $area = $this->anArea(),
            Vocabulary::type(null, $area, 'foot'),
            new \DateTimeImmutable('2026-08-22 05:55'),
            new \DateTimeImmutable('2026-08-22 05:55'),
        );
    }

    private function service(bool $writes = true): PatrolRecordingService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($writes ? self::once() : self::never())
            ->method('persist')
            ->with(self::isInstanceOf(Patrol::class));
        $entityManager->expects($writes ? self::once() : self::never())->method('flush');

        return new PatrolRecordingService($entityManager);
    }

    private function anArea(): AreaOfInterest
    {
        return new AreaOfInterest()->setName('Sample Area');
    }
}
