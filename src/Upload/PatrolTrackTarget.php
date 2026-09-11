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
use Uhifadhi\Patrol\Model\ParsedTrack;
use Uhifadhi\Patrol\Service\PatrolDraftService;
use Uhifadhi\Patrol\Service\TrackIngestService;
use Uhifadhi\Storage\Model\EvidenceConstraints;
use Uhifadhi\Storage\Model\StoredFile;
use Uhifadhi\Storage\Model\UploadConstraints;
use Uhifadhi\Storage\Model\UploadReceipt;

/**
 * PL·01 — HOW A GPX TRACK GETS ONTO A PATROL BEING WRITTEN.
 *
 * This module's half of the platform's one upload component, for the first of
 * its two doors. There is no bespoke file input anywhere in the entry flow and
 * no dropzone of this module's own: storage owns the box, the endpoint, the
 * progress, the refusal sentences and the removal question, and this answers the
 * four things storage cannot know.
 *
 *   WHICH RECORD — the draft the page opened. A track arrives before the patrol
 *   it describes exists, which is what a draft is for; see {@see PatrolDraft}.
 *
 *   WHO MAY — "patrols.record" on the draft's AREA, and the draft's own owner.
 *   Both in {@see AbstractPatrolDraftTarget}, with both targets.
 *
 *   WHAT AND HOW BIG — one GPX. Narrower than the deployment's photograph
 *   allowlist in both directions: only the track types, and never larger than
 *   the installation accepts, because a target that widened past the storage
 *   would be promising what the storage then refuses.
 *
 *   WHAT IT BECAME — a parsed track, and the chip on the finished row says so in
 *   the module's own words: "parsed · 14.2 km · 2 h 05 · 3 gaps", which is the
 *   design's own sentence.
 *
 * THE BYTES ARE KEPT. The receipt is {@see UploadReceipt::stored()}, not
 * `parsed()`, even though everything the module needs is read here and now: the
 * GPX is the SOURCE FILE of a field record, the one artefact that can be handed
 * to somebody who disputes what a coverage figure says. A boundary import that
 * turns a file into geometry has no such duty and says `parsed`; a patrol's
 * track does.
 *
 * ONE INGEST SERVICE, TWO DOORS. The parse is {@see TrackIngestService}'s, the
 * same one the tracker app's API POST goes through. Nothing about reading a GPX
 * is written twice.
 */
final readonly class PatrolTrackTarget extends AbstractPatrolDraftTarget
{
    /** The target key prefix, and the first segment of every key it stores under. */
    public const string KIND = 'patrol-track';

    /**
     * The types a GPX document is detected as. `application/gpx+xml` is the
     * registered one; most platforms' fileinfo reads a GPX as generic XML,
     * because that is what it is, so both spellings are named rather than
     * refusing a valid track over a detector's honesty.
     */
    public const array MIME_TYPES = ['application/gpx+xml', 'application/xml', 'text/xml'];

    /** The design's figure. An installation that accepts less still wins. */
    public const int MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        PatrolDraftService $drafts,
        AuthorizationCheckerInterface $authorization,
        private TrackIngestService $ingest,
        private EvidenceConstraints $deployment,
    ) {
        parent::__construct($drafts, $authorization);
    }

    public function kind(): string
    {
        return self::KIND;
    }

    /**
     * ONE FILE, ONE GPX, AND NEVER BIGGER THAN THE DEPLOYMENT TAKES.
     *
     * `maxFiles` is 1 because a patrol has one track; dropping a folder of them
     * is a mistake worth refusing before it is drawn rather than after.
     */
    public function constraints(object $record): UploadConstraints
    {
        return new UploadConstraints(
            self::MIME_TYPES,
            min(self::MAX_BYTES, $this->deployment->maxBytes),
            maxFiles: 1,
        );
    }

    public function received(object $record, StoredFile $file, UserInterface $user): UploadReceipt
    {
        if (!$record instanceof PatrolDraft) {
            throw new \LogicException('A track reached the patrol target for something that is not a draft.');
        }

        // Read here and now, because a receipt is the last moment the module is
        // told anything about these bytes. A file that will not parse throws,
        // which fails the upload and takes the stored blob away again — nothing
        // is left behind for a track nobody can read.
        $track = $this->ingest->preview($this->drafts->bytesAt($file->key));

        $outcome = self::outcomeOf($track);

        $this->drafts->receive(
            $record,
            PatrolDraftFile::TRACK_SLOT,
            $file,
            $file->clientName ?? 'track.gpx',
            $outcome,
            only: true,
        );

        // No href: the file's own page is the Files hub's, which an installation
        // may not run, and the patrol it will belong to does not exist yet.
        return UploadReceipt::stored($file->clientName ?? 'track.gpx', null, $outcome);
    }

    /**
     * THE CHIP THE DESIGN PUTS ON A FINISHED TRACK ROW — what the module made of
     * the file, in the module's own words.
     *
     * A track with no timestamps is a real GPX ({@see \Uhifadhi\Patrol\Service\GpxParser}
     * says so), so the duration and the gaps are simply left off rather than
     * printed as zero: "no clock on this track" and "a track with no silences"
     * are different facts.
     */
    public static function outcomeOf(ParsedTrack $track): string
    {
        $parts = ['parsed', number_format($track->distanceKm, 1).' km'];

        if (null !== $track->startedAt && null !== $track->endedAt) {
            $minutes = intdiv($track->endedAt->getTimestamp() - $track->startedAt->getTimestamp(), 60);
            $parts[] = \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
            $parts[] = $track->gapCount.' '.(1 === $track->gapCount ? 'gap' : 'gaps');
        }

        return implode(' · ', $parts);
    }

    protected function claimsSlot(string $slot): bool
    {
        return PatrolDraftFile::TRACK_SLOT === $slot;
    }

    protected function draftIdOf(string $targetId): string
    {
        return $targetId;
    }
}
