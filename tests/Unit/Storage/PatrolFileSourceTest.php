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

namespace Uhifadhi\Patrol\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Service\PhotoEvidenceKey;
use Uhifadhi\Patrol\Storage\PatrolFileSource;
use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Enum\GuardStateEnum;
use Uhifadhi\Storage\Enum\ThumbStateEnum;

/**
 * What the Files hub is told about a patrol photograph.
 *
 * The mapping is pinned here rather than through the hub, because every field
 * of a FileEntry is a CLAIM about the record — the wrong owner label or the
 * wrong area puts a photograph on somebody else's page, and nothing throws.
 */
final class PatrolFileSourceTest extends TestCase
{
    public function testAPhotographIsHandedOverWithTheObservationItBelongsTo(): void
    {
        $photo = $this->photo();
        $entry = PatrolFileSource::entryFor($photo, '/areas/a/patrols/p/observations/o');

        self::assertSame('patrol/1f0aa/e77c.jpg', $entry->key);
        self::assertSame('e77c.jpg', $entry->name);
        self::assertSame('image/jpeg', $entry->mimeType);
        self::assertSame(204_800, $entry->byteSize);
        self::assertSame('patrols', $entry->moduleSlug);
        self::assertSame('Patrols', $entry->moduleLabel);
        self::assertSame('/areas/a/patrols/p/observations/o', $entry->ownerUrl);
        self::assertSame(FileKindEnum::Photo, $entry->kind);
    }

    /**
     * The owner label is the RECORD's id alone. The hub's own template prints
     * "{moduleLabel} · {ownerLabel}", so a label that repeated the module would
     * read "Patrols · Patrols · OBS-0000" on every tile.
     */
    public function testTheOwnerLabelIsTheObservationsReferenceAndNotTheModulesName(): void
    {
        $entry = PatrolFileSource::entryFor($this->photo(), null);

        self::assertSame('OBS-0000', $entry->ownerLabel);
        self::assertStringNotContainsString('Patrols', $entry->ownerLabel);
    }

    /** A module without a page for the record hands over a name, not a link. */
    public function testAPhotographWithNoReachablePageIsNamedRatherThanLinked(): void
    {
        self::assertNull(PatrolFileSource::entryFor($this->photo(), null)->ownerUrl);
    }

    public function testTheAreaIsThePatrolsArea(): void
    {
        $photo = $this->photo();
        $area = $photo->getObservation()->getPatrol()->getArea();
        $entry = PatrolFileSource::entryFor($photo, null);

        self::assertSame($area->getUuidString(), $entry->areaSlug);
        self::assertSame('Kifaru Sector', $entry->areaLabel);
    }

    /**
     * The handset's clock and the sync clock are two different facts and the hub
     * files a photograph under the first: a patrol out for three days syncs on
     * the third, and its photographs still belong to the days they were taken.
     */
    public function testTheHandsetsClockFilesThePhotographAndTheSyncClockIsWhenItArrived(): void
    {
        $photo = $this->photo();
        $entry = PatrolFileSource::entryFor($photo, null);

        self::assertEquals($photo->getTakenAt(), $entry->takenAt);
        self::assertEquals($photo->getCreatedAt(), $entry->arrivedAt);
        self::assertSame($photo->getTakenAt()?->format('Y-m-d'), $entry->day());
    }

    /** A caption belongs to the record; patrol photographs carry none. */
    public function testAPatrolPhotographCarriesNoCaption(): void
    {
        self::assertNull(PatrolFileSource::entryFor($this->photo(), null)->caption);
    }

    public function testAPreviewThatWasMadeIsHandedOverWithTheFile(): void
    {
        $entry = PatrolFileSource::entryFor($this->photo(), null);

        self::assertSame('patrol/1f0aa/e77c.jpg.thumb.jpg', $entry->thumbKey);
        self::assertSame(ThumbStateEnum::Made, $entry->thumbState);
    }

    /**
     * A null thumb key is the storage bundle's honest "nothing here could decode
     * it" (HEIC without libheif is the everyday case), so the hub is told the
     * picture could not be made rather than that it is still coming.
     */
    public function testAPhotographWithNoPreviewSaysThePictureCouldNotBeMade(): void
    {
        $photo = $this->photo()->setThumbKey(null);
        $entry = PatrolFileSource::entryFor($photo, null);

        self::assertNull($entry->thumbKey);
        self::assertSame(ThumbStateEnum::Failed, $entry->thumbState);
    }

    /**
     * A row written before the module recorded types is still a PHOTOGRAPH; only
     * its type is unknown. The kind is passed explicitly for exactly this case —
     * read off an unknown mime type it would come back as a document.
     */
    public function testAPhotographWithNoRecordedTypeIsStillAPhotograph(): void
    {
        $photo = $this->photo()->setMimeType(null)->setByteSize(null);
        $entry = PatrolFileSource::entryFor($photo, null);

        self::assertSame('application/octet-stream', $entry->mimeType);
        self::assertSame(0, $entry->byteSize);
        self::assertSame(FileKindEnum::Photo, $entry->kind);
    }

    /**
     * EVIDENCE IS NEVER REMOVED FROM THE WEB.
     *
     * Patrol's answer does not vary by person, because the refusal is a fact
     * about the record rather than about the reader: nothing signed in and
     * nothing privileged may take a photograph off an observation.
     */
    public function testAPhotographIsLockedToItsObservationForEveryone(): void
    {
        $guard = PatrolFileSource::lockedGuard();

        self::assertSame(GuardStateEnum::Locked, $guard->state);
        self::assertFalse($guard->offersRemoval());
        self::assertNotSame('', $guard->title);
        self::assertNotSame('', $guard->text);
    }

    /**
     * THE ONE TRUTH ABOUT WHICH KEYS ARE PATROL'S.
     *
     * The source and PatrolEvidenceVoter must claim exactly the same set, or the
     * hub shows a file whose bytes the serving route refuses — a broken tile on
     * a page the reader is entitled to. Both read PhotoEvidenceKey; this pins
     * that they do.
     */
    #[DataProvider('keys')]
    public function testTheSourceClaimsExactlyWhatTheEvidenceRuleClaims(string $key): void
    {
        self::assertSame(PhotoEvidenceKey::claims($key), PatrolFileSource::claims($key));
    }

    /** @return iterable<string, array{string}> */
    public static function keys(): iterable
    {
        yield 'current shape' => ['patrol/1f0aa/e77c.jpg'];
        yield 'a preview' => ['patrol/1f0aa/e77c.jpg.thumb.jpg'];
        yield 'legacy shape' => ['patrol-1f0aa/e77c.jpg'];
        yield 'another module' => ['incident/1f0aa/e77c.jpg'];
        yield 'a lookalike' => ['patrols/1f0aa/e77c.jpg'];
        yield 'nothing at all' => [''];
    }

    private function photo(): ObservationPhoto
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Kifaru Sector');
        $patrol = new Patrol($area, 'walk');
        $observation = new Observation($patrol, 'maintenance');

        $photo = new ObservationPhoto(
            $observation,
            Uuid::fromString('e77c0000-0000-4000-8000-000000000001'),
            'patrol/1f0aa/e77c.jpg',
        );

        return $photo
            ->setThumbKey('patrol/1f0aa/e77c.jpg.thumb.jpg')
            ->setMimeType('image/jpeg')
            ->setByteSize(204_800)
            ->setTakenAt(new \DateTimeImmutable('2026-08-19 06:41:00'));
    }
}
