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

namespace UhifadhiLabs\Patrol\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use UhifadhiLabs\Patrol\Repository\ObservationPhotoRepository;
use UhifadhiLabs\Patrol\Service\PhotoEvidenceKey;
use UhifadhiLabs\Storage\Security\EvidenceAccessVoterInterface;

/**
 * Who may read a patrol photograph — the module half of storage-module's
 * permission seam.
 *
 * The storage bundle stores bytes and refuses to guess: a key that NO module
 * claims is denied, so until this class existed patrol's own photographs were
 * (correctly) invisible. It claims the keys patrol writes and answers for them.
 *
 * THE RULE IS THE OBSERVATION PAGE'S RULE, deliberately and to the letter. A
 * photograph is shown on the observation detail screen; that screen is reached
 * by any signed-in member of the authority (the host's access_control puts every
 * non-API path behind ROLE_USER) and is gated further only by area nesting,
 * which is a routing rule and not a permission. So the answer here is: a
 * signed-in user, and a key this module actually holds. Inventing a stricter
 * rule for the bytes than for the page they appear on would not be safer — it
 * would be a broken image on a page the reader is entitled to.
 *
 * REVISIT WHEN the host grows per-area permissions. Then this becomes "may the
 * user view the patrol's area", resolved through the photo → observation →
 * patrol → area chain the lookup below already walks; the seam does not change,
 * only the question asked at the end of it.
 */
final class PatrolEvidenceVoter implements EvidenceAccessVoterInterface
{
    public function __construct(
        private readonly ObservationPhotoRepository $photos,
    ) {
    }

    public function claimsKey(string $key): bool
    {
        return PhotoEvidenceKey::claims($key);
    }

    public function mayRead(string $key, ?UserInterface $user): bool
    {
        // A visitor who is not signed in. The host's firewall would normally
        // have stopped them before this, but the seam is asked anyway and must
        // answer for itself: a deployment that ever exposes the serving route
        // more loosely must not thereby expose field evidence.
        if (null === $user) {
            return false;
        }

        // Decided on the ORIGINAL, so a preview is never readable by someone who
        // may not read the photograph it was made from.
        return null !== $this->photos->findOneByStoragePath(PhotoEvidenceKey::original($key));
    }
}
