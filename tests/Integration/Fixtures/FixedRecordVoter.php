<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Controller\PatrolRecordController;

/**
 * Test stand-in for the HOST's permission voter: the bundle only DECLARES
 * "patrols.record" (PatrolRecordController::RECORD_PERMISSION); deciding who
 * holds it is the host's job. Here that decision is fixed — exactly one email
 * may record — so tests can exercise both the granted and the denied path.
 *
 * @extends Voter<string, mixed>
 */
final class FixedRecordVoter extends Voter
{
    public const string RECORDER_EMAIL = 'recorder@example.test';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return PatrolRecordController::RECORD_PERMISSION === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && self::RECORDER_EMAIL === $user->getEmail();
    }
}
