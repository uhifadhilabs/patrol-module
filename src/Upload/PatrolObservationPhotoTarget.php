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
use Uhifadhi\Patrol\Entity\PatrolDraft;
use Uhifadhi\Patrol\Entity\PatrolDraftFile;
use Uhifadhi\Patrol\Service\PatrolDraftService;
use Uhifadhi\Storage\Model\EvidenceConstraints;
use Uhifadhi\Storage\Model\StoredFile;
use Uhifadhi\Storage\Model\UploadConstraints;
use Uhifadhi\Storage\Model\UploadReceipt;

/**
 * PL·03 — HOW A PHOTOGRAPH GETS ONTO AN OBSERVATION BEING RECORDED.
 *
 * The second of the entry flow's two doors, and the same shape as the first: the
 * grid of evidence tiles is the platform's upload component in its `tile`
 * presentation, and this answers the four questions storage cannot.
 *
 *   WHICH RECORD — the draft, and WHICH BOX ON IT. A page may be recording six
 *   observations at once and each has its own grid, so the target carries an
 *   ordinal after the draft: `observation:<draft>-3`. The ordinal is part of the
 *   target ID and therefore part of the key, which is what keeps two grids'
 *   photographs apart before either observation exists.
 *
 *   WHO MAY — "patrols.record" on the draft's AREA, and the draft's own owner;
 *   see {@see AbstractPatrolDraftTarget}.
 *
 *   WHAT AND HOW BIG — the deployment's own, unnarrowed. Field photography is
 *   exactly what an installation configures its evidence allowlist for, and a
 *   module that hardcoded a shorter list would be overruling a decision that is
 *   the deployment's.
 *
 *   WHAT IT BECAME — evidence, and the chip on the finished tile says so. The
 *   same word the case-file target uses, because it is the same fact.
 *
 * WHY A SEPARATE KIND FROM `patrol`. The prefix is the key's first segment and
 * the thing a removal, a voter and the Files hub route on. A photograph on a
 * SAVED patrol lives under {@see \Uhifadhi\Patrol\Service\PhotoEvidenceKey::PREFIX},
 * where the observation it belongs to can be found from the key; one on a draft
 * cannot, because there is no observation yet. Two different facts, two
 * different prefixes — and the save is precisely the move from one to the other.
 */
final readonly class PatrolObservationPhotoTarget extends AbstractPatrolDraftTarget
{
    /** The target key prefix, and the first segment of every key it stores under. */
    public const string KIND = 'observation';

    /** What the chip on a finished tile says this module made of the file. */
    public const string KIND_WORD = 'evidence';

    public function __construct(
        PatrolDraftService $drafts,
        AuthorizationCheckerInterface $authorization,
        private EvidenceConstraints $deployment,
    ) {
        parent::__construct($drafts, $authorization);
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function constraints(object $record): UploadConstraints
    {
        return UploadConstraints::from($this->deployment);
    }

    public function received(object $record, StoredFile $file, UserInterface $user): UploadReceipt
    {
        if (!$record instanceof PatrolDraft) {
            throw new \LogicException('A photograph reached the observation target for something that is not a draft.');
        }

        $label = $file->clientName ?? 'photograph';

        $this->drafts->receive(
            $record,
            PatrolDraftFile::observationSlot($this->ordinalOf($file->key)),
            $file,
            $label,
            self::KIND_WORD,
        );

        return UploadReceipt::stored($label, null, self::KIND_WORD);
    }

    protected function claimsSlot(string $slot): bool
    {
        return str_starts_with($slot, PatrolDraftFile::OBSERVATION_SLOT_PREFIX);
    }

    protected function draftIdOf(string $targetId): string
    {
        $dash = strrpos($targetId, '-');

        return false === $dash ? $targetId : substr($targetId, 0, $dash);
    }

    /**
     * WHICH GRID THIS FILE CAME FROM, READ BACK OUT OF ITS OWN KEY.
     *
     * `received()` is handed the record and the stored file, never the target
     * string — so the ordinal is taken from the key the storage just built,
     * which is `<kind>/<targetId>/<uuid>.<ext>` by the contract's own rule. That
     * is the same trick the platform plays to route a removal: the key is the
     * one place this fact is written down, and reading it back beats remembering
     * it beside.
     *
     * Anything unreadable is the first grid — a file this module received is
     * better held against an observation than lost.
     */
    private function ordinalOf(string $key): int
    {
        $segments = explode('/', $key);
        $ordinal = isset($segments[1]) ? substr($segments[1], (int) strrpos($segments[1], '-') + 1) : '';

        return ctype_digit($ordinal) && (int) $ordinal > 0 ? (int) $ordinal : 1;
    }
}
