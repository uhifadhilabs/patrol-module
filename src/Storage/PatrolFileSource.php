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

namespace Uhifadhi\Patrol\Storage;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Repository\ObservationPhotoRepository;
use Uhifadhi\Patrol\Service\PhotoEvidenceKey;
use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Enum\GuardStateEnum;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\FileGuard;
use Uhifadhi\Storage\Registry\FileSourceInterface;

/**
 * PATROL'S FILES, ON THE PLATFORM'S FILES HUB.
 *
 * The hub at /files knows nothing about observations and cannot: knowing what a
 * photograph is attached to is precisely what makes a module a module. So every
 * photograph patrol holds is handed over here, already carrying the one thing
 * that makes it a file on this platform — the record it belongs to.
 *
 * The seam this implements is uhifadhi/storage-module's
 * {@see FileSourceInterface}, and this class answers only what patrol knows:
 * which keys are ours, what each photograph belongs to, and what may be done to
 * it. It deliberately does NOT answer where the bytes are or whether the small
 * picture was made — the storage bundle adds those two from its own
 * configuration, and a module guessing at them would be inventing facts.
 *
 * The two seams patrol ships into storage answer two different questions and
 * must never disagree about which keys are patrol's:
 *
 *   - {@see \Uhifadhi\Patrol\Security\PatrolEvidenceVoter} — may you READ
 *     these bytes;
 *   - this class — may you take this file OFF its record.
 *
 * Both read {@see PhotoEvidenceKey}, which is the single truth about the prefix;
 * PatrolFileSourceTest and PatrolFileSourceRegistrationTest pin that they stay
 * one answer.
 *
 * NOT A TRACK SOURCE, yet. A patrol's GPX export is generated on demand from the
 * track column (PatrolDetailController::exportGpx) — there is no stored object
 * and therefore no key, and a hub entry for bytes that do not exist would be a
 * tile linking at nothing. When patrol stores exports, they arrive here as a
 * second kind of entry and {@see attachesTo()} grows the second half of its
 * sentence.
 */
final class PatrolFileSource implements FileSourceInterface
{
    /** The module slug the hub counts patrol by, and the colour files.css draws its dot in. */
    public const string SLUG = 'patrols';

    public const string LABEL = 'Patrols';

    /**
     * THE ONE TOKEN PATROL PUTS ON THE WIRE for itself, singular: the value of
     * `source` in the File-as-incident seam
     * ({@see \Uhifadhi\Patrol\Controller\PatrolDetailController::fileAsIncidentUrl()})
     * and the value another module hands back to {@see filesForRecord()} to ask
     * for one observation's photographs.
     *
     * It is stated here, once, because two bundles that may be installed without
     * each other cannot share a constant — so the only defence against drift is
     * that every place patrol writes or reads this token reads THIS line, and
     * that patrol accepts its own module slug as an alias for it.
     */
    public const string SOURCE_TOKEN = 'patrol';

    public function __construct(
        private readonly ObservationPhotoRepository $photos,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function moduleLabel(): string
    {
        return self::LABEL;
    }

    public function attachesTo(): string
    {
        return 'an observation’s photographs';
    }

    public function claimsKey(string $key): bool
    {
        return self::claims($key);
    }

    /**
     * The claim, as a function of the key alone — the same question the evidence
     * voter asks, answered from the same place.
     */
    public static function claims(string $key): bool
    {
        return PhotoEvidenceKey::claims($key);
    }

    /**
     * @return iterable<FileEntry>
     */
    public function files(): iterable
    {
        foreach ($this->photos->findForFilesHub() as $photo) {
            yield self::entryFor($photo, $this->observationUrl($photo));
        }
    }

    /**
     * ONE OBSERVATION'S PHOTOGRAPHS, for a module that is SHOWING that
     * observation without owning it.
     *
     * The incidents report flow, opened from an observation, draws that
     * observation's photographs on its source card so the filer can see what they
     * are filing about. It has a record uuid and the `source` token patrol put on
     * the wire, and nothing else — it may not name patrol's classes, its routes
     * or its key prefix. So it asks here, and patrol answers with the same
     * {@see FileEntry} the hub gets: one photograph, already carrying its owner.
     *
     * WHAT IS NOT FOUND IS NOT AN ERROR. An observation with no photographs, a
     * uuid that is not an observation's, a uuid that is not a uuid at all, and a
     * token naming somebody else all answer the same way — nothing — and the card
     * simply draws no strip.
     *
     * @return iterable<FileEntry>
     */
    public function filesForRecord(string $source, string $recordUuid): iterable
    {
        if (!self::speaksFor($source) || !Uuid::isValid($recordUuid)) {
            return;
        }

        foreach ($this->photos->findForObservation(Uuid::fromString($recordUuid)) as $photo) {
            yield self::entryFor($photo, $this->observationUrl($photo));
        }
    }

    /**
     * Whether a wire token names patrol. Its own token first, its module slug as
     * an alias — a source card must not go blank over a plural.
     */
    public static function speaksFor(string $source): bool
    {
        return \in_array(strtolower(trim($source)), [self::SOURCE_TOKEN, self::SLUG], true);
    }

    public function guard(string $key, ?UserInterface $user): FileGuard
    {
        return self::lockedGuard();
    }

    /**
     * EVIDENCE IS NEVER REMOVED FROM THE WEB — patrol's answer, in patrol's own
     * words, and the same answer for every reader.
     *
     * The storage bundle's four answers are about a RECORD, not about a person,
     * and patrol's record refuses outright: a photograph is the evidence an
     * observation rests on, and an observation that could quietly lose one would
     * be worth nothing in the hearing the observation exists for. So this is
     * Locked rather than Reason, and it does not vary with $user — a Denied here
     * would say "somebody else could", which is not true of anyone.
     *
     * This is a DEVIATION from storage-module's README, which sketches Reason
     * for a filed observation's photographs, and it is the deviation that README
     * itself provides for: Reason promises a line on the record's own trail, and
     * an Observation HAS NO TRAIL to write one onto. Promising a recorded
     * removal that nothing records would be the one failure the hub's whole
     * "remove, never delete" wording exists to prevent. If observations grow a
     * trail, this becomes Reason and the class grows
     * {@see \Uhifadhi\Storage\Removal\FileRemovalInterface} beside it —
     * which is why that interface is absent here rather than throwing.
     */
    public static function lockedGuard(): FileGuard
    {
        return new FileGuard(
            GuardStateEnum::Locked,
            'The observation will not let go of this photograph',
            'A patrol photograph is the evidence its observation rests on, and this module keeps no trail on which a removal could be recorded. Nothing on this platform — signed in or otherwise — can take it off the record: it is kept for as long as the observation is, and it goes when the observation goes.',
        );
    }

    /**
     * One photograph, as the hub knows it.
     *
     * Static and given its owner's URL rather than generating one, so the whole
     * mapping can be pinned against real entities without a router or a database
     * standing behind it.
     *
     * The KIND is passed explicitly instead of being read off the mime type. A
     * row this module wrote before it recorded detected types has no type at
     * all, and a photograph whose type is unknown is still a photograph — read
     * off the fallback it would arrive on the hub as a document, filed under the
     * wrong chip and the wrong count.
     */
    public static function entryFor(ObservationPhoto $photo, ?string $ownerUrl): FileEntry
    {
        $observation = $photo->getObservation();
        $area = $observation->getPatrol()->getArea();
        $key = $photo->getStoragePath();

        return new FileEntry(
            key: $key,
            // Patrol keeps no client filename — deliberately, since a filename
            // is attacker-controlled text — so the file's own name is the last
            // segment of the key it was stored under.
            name: basename($key),
            mimeType: $photo->getMimeType() ?? 'application/octet-stream',
            byteSize: $photo->getByteSize() ?? 0,
            // The RECORD's id alone: the hub's own template prints
            // "{moduleLabel} · {ownerLabel}", so naming the module here would
            // print it twice.
            ownerLabel: $observation->getRef(),
            ownerUrl: $ownerUrl,
            moduleSlug: self::SLUG,
            moduleLabel: self::LABEL,
            areaSlug: $area->getUuidString(),
            areaLabel: $area->getName(),
            // The HANDSET's clock, never the sync clock: a patrol out for three
            // days syncs on the third, and its photographs still belong to the
            // days they were taken.
            takenAt: $photo->getTakenAt(),
            arrivedAt: $photo->getCreatedAt(),
            thumbKey: $photo->getThumbKey(),
            // No thumb state is passed: patrol runs no thumbnail queue — the
            // preview is made at store() time or not at all — so the storage
            // bundle's own reading of "a key means made, none means could not be
            // made" is exactly patrol's situation.
            caption: null,
            kind: FileKindEnum::Photo,
        );
    }

    /**
     * The observation's own page, or null where the area has no public address
     * yet (an area that was never persisted). A named-but-unlinked file is a
     * state the hub draws; a URL built from a null is a crash on every tile.
     */
    private function observationUrl(ObservationPhoto $photo): ?string
    {
        $observation = $photo->getObservation();
        $patrol = $observation->getPatrol();
        $areaUuid = $patrol->getArea()->getUuidString();

        if (null === $areaUuid) {
            return null;
        }

        return $this->urls->generate('patrol_observation_show', [
            'uuid' => $areaUuid,
            'patrol' => $patrol->getUuid()->toRfc4122(),
            'observation' => $observation->getUuid()->toRfc4122(),
        ]);
    }
}
