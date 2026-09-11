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

namespace Uhifadhi\Patrol\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Patrol\Entity\Trait\TimestampableTrait;
use Uhifadhi\Patrol\Repository\PatrolDraftFileRepository;

/**
 * ONE FILE ALREADY RECEIVED FOR A PATROL NOBODY HAS SAVED YET.
 *
 * The upload contract's fifth question is "the bytes are stored — write the
 * row", and this is the row a draft writes. It exists because the storage
 * publishes no listing: a key that nothing remembers cannot be re-homed on save,
 * cannot be taken back off the form, and cannot be swept when the draft is
 * abandoned. Three things need the same answer, so it is written down once.
 *
 * THE SLOT IS WHICH BOX ON THE FORM. `track` is PL·01's dropzone — at most one,
 * and a second replaces the first, because a patrol has one track. `obs-1`,
 * `obs-2` … are PL·03's evidence grids, one per observation being recorded, and
 * they take as many photographs as the deployment allows.
 *
 * NOTHING HERE OUTLIVES THE SAVE. On save each row's bytes are copied under the
 * patrol's own prefix and the draft's copy is deleted; on abandonment the sweep
 * deletes both. The row is bookkeeping for a few minutes of a form's life and is
 * deliberately not a second evidence table.
 */
#[ORM\Entity(repositoryClass: PatrolDraftFileRepository::class)]
#[ORM\Table(name: 'patrol_draft_file')]
#[ORM\HasLifecycleCallbacks]
class PatrolDraftFile
{
    use TimestampableTrait;

    /** PL·01's dropzone: the patrol's track, of which there is at most one. */
    public const string TRACK_SLOT = 'track';

    /** The slot one of PL·03's evidence grids files under. */
    public const string OBSERVATION_SLOT_PREFIX = 'obs-';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: PatrolDraft::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PatrolDraft $draft;

    #[ORM\Column(length: 40)]
    private string $slot;

    /** The evidence key the storage returned — unique, because bytes have one name. */
    #[ORM\Column(length: 255, unique: true)]
    private string $storageKey;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbKey = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    /** What a person uploaded it under — the only thing the form can print. */
    #[ORM\Column(length: 255)]
    private string $label;

    /**
     * The module's word for what the file became — the chip the design draws on
     * a finished row. For a track it is the parsed sentence, so a reload can
     * redraw the row the component drew without re-reading the file.
     */
    #[ORM\Column(length: 120)]
    private string $outcome;

    public function __construct(PatrolDraft $draft, string $slot, string $storageKey, string $label, string $outcome)
    {
        $this->draft = $draft;
        $this->slot = $slot;
        $this->storageKey = $storageKey;
        $this->label = $label;
        $this->outcome = $outcome;
        $draft->addFile($this);
        $this->initTimestamps();
    }

    /** The slot one observation's evidence grid files under. */
    public static function observationSlot(int $n): string
    {
        return self::OBSERVATION_SLOT_PREFIX.$n;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDraft(): PatrolDraft
    {
        return $this->draft;
    }

    public function getSlot(): string
    {
        return $this->slot;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getThumbKey(): ?string
    {
        return $this->thumbKey;
    }

    public function setThumbKey(?string $thumbKey): static
    {
        $this->thumbKey = $thumbKey;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function setByteSize(?int $byteSize): static
    {
        $this->byteSize = $byteSize;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }
}
