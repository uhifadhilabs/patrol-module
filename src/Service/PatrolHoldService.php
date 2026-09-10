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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Exception\PatrolNotDiscardedException;

/**
 * THE BRAKE ON THE RETENTION CLOCK, and the only thing that touches it.
 *
 * A discarded patrol is on its way to real deletion (`patrol:purge-discarded`),
 * and a hold is what somebody pulls when that patrol turns out to matter after
 * all — an investigation, a disputed shift, a discard that looks like a mistake.
 * It stops the clock and has no expiry: only a person clears it.
 *
 * SCOPE, DELIBERATELY NARROW. Two columns and nothing else. This does not
 * un-discard a patrol, it is not an edit, and it never touches `webEditedAt` —
 * that flag means "a human corrected this record" and shuts the field door. A
 * hold corrects nothing; a patrol held while its handset is still syncing must
 * keep accepting the parts it was promised, or holding one would silently
 * destroy the evidence somebody held it to look at.
 *
 * ONLY A DISCARDED PATROL HAS A CLOCK TO STOP, and that is enforced here rather
 * than at the screen, because it is a fact about the record.
 *
 * IT DECIDES NOTHING ABOUT WHO IS ASKING. Whether the caller holds
 * "patrols.record" and whether the form carried a valid token are questions
 * about the request; the screen settles them and hands this the person's name.
 */
final readonly class PatrolHoldService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param ?UserInterface $by who pulled the brake, so the page can name them;
     *                           null where the installation runs no security
     *
     * @throws PatrolNotDiscardedException
     */
    public function hold(Patrol $patrol, ?UserInterface $by): void
    {
        $this->denyUnlessDiscarded($patrol);

        $patrol->hold($by);

        $this->entityManager->flush();
    }

    /** @throws PatrolNotDiscardedException */
    public function release(Patrol $patrol): void
    {
        $this->denyUnlessDiscarded($patrol);

        $patrol->release();

        $this->entityManager->flush();
    }

    /** @throws PatrolNotDiscardedException */
    private function denyUnlessDiscarded(Patrol $patrol): void
    {
        if (!$patrol->isDiscarded()) {
            throw new PatrolNotDiscardedException($patrol);
        }
    }
}
