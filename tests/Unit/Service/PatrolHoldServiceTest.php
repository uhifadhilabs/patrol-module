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
use Uhifadhi\Patrol\Exception\PatrolNotDiscardedException;
use Uhifadhi\Patrol\Service\PatrolHoldService;

/**
 * THE BRAKE ON THE RETENTION CLOCK — and the one record it may be pulled on.
 *
 * A discarded patrol is on its way to real deletion; a hold is what somebody
 * pulls when it turns out to matter after all. Only a discarded patrol has a
 * clock to stop, and that rule is asserted here rather than at the screen,
 * because it is about the record.
 */
final class PatrolHoldServiceTest extends TestCase
{
    public function testHoldingStopsTheClockAndNamesWhoPulledIt(): void
    {
        $patrol = $this->aDiscardedPatrol();

        $this->service()->hold($patrol, null);

        self::assertNotNull($patrol->getHeldAt());
    }

    public function testReleasingLetsTheClockRunAgain(): void
    {
        $patrol = $this->aDiscardedPatrol();
        $this->service()->hold($patrol, null);

        $this->service()->release($patrol);

        self::assertNull($patrol->getHeldAt());
        self::assertNull($patrol->getHeldBy());
    }

    /** A control with no effect is worse than no control: holding a live patrol is refused. */
    public function testALivePatrolCannotBeHeld(): void
    {
        $this->expectException(PatrolNotDiscardedException::class);

        $this->service(writes: false)->hold($this->aLivePatrol(), null);
    }

    public function testALivePatrolCannotBeReleasedEither(): void
    {
        $this->expectException(PatrolNotDiscardedException::class);

        $this->service(writes: false)->release($this->aLivePatrol());
    }

    /**
     * A HOLD CORRECTS NOTHING. `webEditedAt` means "a human corrected this
     * record" and shuts the field door; a patrol held while its handset is still
     * syncing must keep accepting the parts it was promised, or holding one
     * would destroy the evidence somebody held it to look at.
     */
    public function testAHeldPatrolStillAcceptsWhatTheFieldIsStillSending(): void
    {
        $patrol = $this->aDiscardedPatrol();

        $this->service()->hold($patrol, null);

        self::assertTrue($patrol->acceptsFieldUploads());
    }

    private function service(bool $writes = true): PatrolHoldService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($writes ? self::once() : self::never())->method('flush');

        return new PatrolHoldService($entityManager);
    }

    private function aLivePatrol(): Patrol
    {
        return new Patrol(new AreaOfInterest()->setName('Sample Area'), 'foot');
    }

    private function aDiscardedPatrol(): Patrol
    {
        return $this->aLivePatrol()->discard('Duplicate of the morning round.');
    }
}
