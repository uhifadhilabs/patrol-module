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

namespace Uhifadhi\Patrol\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Controller\PatrolTaxonomyController;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;

/**
 * Test stand-in for the HOST's permission voter: the bundle only DECLARES
 * "patrols.record" (PatrolModuleProvider::RECORD_PERMISSION) and
 * "patrols.manage" (PatrolTaxonomyController::MANAGE_PERMISSION); deciding who
 * holds them is the host's job.
 *
 * Here that decision is fixed and DIFFERENT FOR THE TWO TIERS, on purpose: one
 * account may record and not manage, another may manage. That is the split the
 * taxonomy admin rests on — logging a patrol (`patrols.record`) is not enough to
 * name the words everybody else must use (`patrols.manage`) — so the tests have
 * to be able to exercise a person who has one and not the other, which a single
 * blanket "may do everything" stub could never show.
 *
 * @extends Voter<string, mixed>
 */
final class FixedRecordVoter extends Voter
{
    /** May record a patrol, and may NOT manage the taxonomy. */
    public const string RECORDER_EMAIL = 'recorder@example.test';

    /** May manage this area's observation taxonomy. */
    public const string MANAGER_EMAIL = 'manager@example.test';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [
            PatrolModuleProvider::RECORD_PERMISSION,
            PatrolTaxonomyController::MANAGE_PERMISSION,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            PatrolModuleProvider::RECORD_PERMISSION => self::RECORDER_EMAIL === $user->getEmail(),
            PatrolTaxonomyController::MANAGE_PERMISSION => self::MANAGER_EMAIL === $user->getEmail(),
            default => false,
        };
    }
}
