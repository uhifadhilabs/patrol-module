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

use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Upload\PatrolObservationPhotoTarget;
use Uhifadhi\Patrol\Upload\PatrolTrackTarget;
use Uhifadhi\Storage\Service\EvidenceKey;

/**
 * Which evidence keys are PATROL's, in one place.
 *
 * Three collaborators need the same answer and must never disagree about it:
 * {@see Api\PhotoSyncService} writes keys under
 * this prefix, {@see \Uhifadhi\Patrol\Security\PatrolEvidenceVoter} claims
 * them back on the way out, and the thumbnail backfill walks them. A prefix
 * remembered in three places is a prefix that eventually differs in one, and the
 * failure mode there is silent: a photograph nobody is allowed to look at.
 *
 * Static, because there is nothing to configure — these rules are the same in
 * every deployment.
 */
final class PhotoEvidenceKey
{
    /**
     * The owning prefix of every key this module writes. It is the first
     * segment of the key, which is what {@see EvidenceKey::rootSegment()} reads
     * back and what the voter claims.
     */
    public const string PREFIX = 'patrol';

    /**
     * The prefix of photographs filed under this module's own `photo_dir`,
     * at `patrol-<uuid>/<clientUuid>.jpg`.
     *
     * Those rows are never rewritten. The deployment points
     * `storage.evidence.directory` at that same `var/patrol/photos`, which makes
     * every stored path a valid evidence key exactly as it stands, and this
     * prefix is claimed alongside the new one so the photographs already on disk
     * keep coming back out. A hyphen instead of a slash is the whole difference:
     * the legacy keys are one directory level flatter, and by being a distinct
     * root segment they can never be confused with the new ones.
     */
    public const string LEGACY_PREFIX = 'patrol-';

    private function __construct()
    {
    }

    /**
     * Where this observation's photographs are filed: under their PATROL, so a
     * patrol's evidence can be found, archived or handed over as one thing
     * rather than hunted for across a flat directory of a hundred thousand
     * files.
     *
     * The patrol is named by the phone's own uuid where it has one and by its
     * server uuid otherwise — the same identity the pre-adoption directory used.
     * Deliberately NOT Patrol::getRef(), which is presentation derived from the
     * database id and would move under a restore.
     */
    public static function prefixFor(Observation $observation): string
    {
        return self::prefixForPatrol($observation->getPatrol());
    }

    /**
     * The same prefix, addressed by the patrol itself — for the one file a
     * patrol holds that hangs off no observation: the GPX it was recorded from.
     *
     * A patrol's evidence is one thing whether it is a photograph or the source
     * track, so both sit in one folder and the sweep that takes a patrol away
     * takes all of it.
     */
    public static function prefixForPatrol(Patrol $patrol): string
    {
        return self::PREFIX.'/'.($patrol->getClientUuid()?->toRfc4122() ?? $patrol->getUuid()->toRfc4122());
    }

    /**
     * Is this key one of ours — any of the three shapes?
     *
     * `patrol-track/…` is a DRAFT's, not a patrol's: a track that arrived before
     * the patrol it describes. It already matches the legacy `patrol-` reading
     * below, which is an accident of spelling rather than a decision, so it is
     * named here explicitly — a key's owner should be readable from this method
     * rather than inferred from a prefix that happens to overlap.
     */
    public static function claims(string $key): bool
    {
        $root = EvidenceKey::rootSegment($key);

        return self::PREFIX === $root
            || PatrolTrackTarget::KIND === $root
            || PatrolObservationPhotoTarget::KIND === $root
            || str_starts_with($root, self::LEGACY_PREFIX);
    }

    /** Does this key belong to a patrol being written rather than to a saved one? */
    public static function isDraft(string $key): bool
    {
        $root = EvidenceKey::rootSegment($key);

        return PatrolTrackTarget::KIND === $root || PatrolObservationPhotoTarget::KIND === $root;
    }

    /**
     * The ORIGINAL a key names: itself, or — when it is a generated preview —
     * the photograph the preview was made from.
     *
     * Permission is decided on the original, because a thumbnail of restricted
     * evidence is restricted evidence. There is no ambiguity to worry about: an
     * original is always `<uuid>.<ext>`, so none of them can end in the preview
     * suffix.
     */
    public static function original(string $key): string
    {
        if (!str_ends_with($key, EvidenceKey::THUMB_SUFFIX)) {
            return $key;
        }

        return substr($key, 0, -\strlen(EvidenceKey::THUMB_SUFFIX));
    }
}
