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

namespace Uhifadhi\Patrol\Upload;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Contracts\Entity\UserInterface as PersonInterface;
use Uhifadhi\Patrol\Controller\PatrolRecordController;
use Uhifadhi\Patrol\Entity\PatrolDraft;
use Uhifadhi\Patrol\Entity\PatrolDraftFile;
use Uhifadhi\Patrol\Service\PatrolDraftService;
use Uhifadhi\Storage\Upload\UploadTargetInterface;

/**
 * THE HALF OF THE UPLOAD CONTRACT BOTH OF THIS MODULE'S TARGETS ANSWER THE SAME
 * WAY — which record, who may, and who may take a file back off.
 *
 * Both targets file against a {@see PatrolDraft}, and the three security
 * questions have one answer between them. Written once, here, because the
 * failure mode of writing them twice is silent: two targets that drift apart on
 * "who may" means one of them is wrong and neither says so.
 *
 * WHO MAY IS ASKED ON THE AREA. "patrols.record" is a permission about a place —
 * a person who records patrols in one area is not thereby recording in another —
 * and the draft is the only thing on the way in that knows which area a file is
 * for. That is the whole reason a draft is a ROW rather than an id with nothing
 * behind it; see {@see PatrolDraft}.
 *
 * AND ON THE DRAFT'S OWN OWNER. A draft is one person's half-written form, not a
 * shared inbox: two recorders working the same area at the same time must not be
 * able to drop files into each other's page. A draft whose owner is gone takes
 * nothing from anybody.
 *
 * TWO USER TYPES MEET HERE, as they do in every module that touches the platform's
 * people: the contract speaks Symfony's `UserInterface`, because the endpoint
 * authorises a request, while this module's records point at
 * `Uhifadhi\Contracts\Entity\UserInterface`, because a draft belongs to a person
 * on the team. Narrowing between them is this class's job.
 */
abstract readonly class AbstractPatrolDraftTarget implements UploadTargetInterface
{
    public function __construct(
        protected PatrolDraftService $drafts,
        protected AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * $targetId is the half of the target string after the FIRST colon, and is
     * attacker-controlled text. Anything that does not name a live draft is the
     * same fact as "no such record", and never "you may not".
     */
    public function accepts(string $targetId): ?object
    {
        return $this->drafts->findByUuid($this->draftIdOf($targetId));
    }

    public function mayUpload(object $record, UserInterface $user): bool
    {
        return $record instanceof PatrolDraft && $this->mayWrite($record, $user);
    }

    /**
     * A DIFFERENT QUESTION FROM mayUpload(), asked by key because that is all a
     * removal has. A key under this module's prefixes that no draft row holds
     * answers false: nothing here can authorise removing bytes nothing here
     * admits to owning.
     */
    public function mayRemove(string $key, UserInterface $user): bool
    {
        $file = $this->drafts->findFileByKey($key);

        return $file instanceof PatrolDraftFile
            && $this->claimsSlot($file->getSlot())
            && $this->mayWrite($file->getDraft(), $user);
    }

    public function removed(string $key, UserInterface $user): void
    {
        $this->drafts->released($key);
    }

    /** Which of a draft's slots this target is answering for. */
    abstract protected function claimsSlot(string $slot): bool;

    /**
     * The draft a target id names — the whole of it for the track, its first
     * five uuid groups for an observation, which appends its own ordinal.
     */
    abstract protected function draftIdOf(string $targetId): string;

    private function mayWrite(PatrolDraft $draft, UserInterface $user): bool
    {
        $owner = $draft->getOwner();

        return $owner instanceof PersonInterface
            && $user instanceof PersonInterface
            && null !== $owner->getId()
            && $owner->getId() === $user->getId()
            && $this->authorization->isGranted(
                PatrolRecordController::RECORD_PERMISSION,
                $draft->getArea(),
            );
    }
}
