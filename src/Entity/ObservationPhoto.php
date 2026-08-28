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

namespace UhifadhiLabs\Patrol\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Repository\ObservationPhotoRepository;

/**
 * A photograph attached to an observation — API-CONTRACT.md §8.
 *
 * The bytes live on disk; this row is the record of them. Uploaded one request
 * per photo so an interruption costs one photo rather than a patrol's evidence,
 * and keyed by the phone's own {@see $clientUuid} because the phone does not
 * delete its copy until the upload has been acknowledged — which means it will
 * retry, and a retry must not produce a second copy of the same picture.
 */
#[ORM\Entity(repositoryClass: ObservationPhotoRepository::class)]
#[ORM\Table(name: 'patrol_observation_photo')]
#[ORM\HasLifecycleCallbacks]
class ObservationPhoto
{
    use Trait\TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Observation::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Observation $observation;

    /** The phone's own id for this photo — the idempotency key (§1, §8). */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $clientUuid;

    /**
     * The EVIDENCE KEY: where the bytes are inside the platform's private
     * evidence storage (uhifadhilabs/storage-module). Relative on purpose — that
     * is what lets a deployment move from a local directory to object storage
     * without rewriting a single row here.
     *
     * The column keeps its original name: the rows written before the module
     * adopted storage-module hold exactly the same thing, a relative path under
     * the evidence storage, and renaming a column whose meaning did not change
     * would be churn.
     */
    #[ORM\Column(length: 255)]
    private string $storagePath;

    /**
     * The ~400px preview generated beside the original, or NULL.
     *
     * NULLABLE is not an oversight and must not be tightened: no GD build
     * decodes HEIC and an ImageMagick without libheif cannot either, so an
     * iPhone photograph is routinely stored with no preview available. The
     * bundle records that plainly rather than a key pointing at nothing — losing
     * a ranger's photograph over a missing image library would be an absurd
     * trade.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbKey = null;

    /** The DETECTED type, read from the bytes — never what the client claimed. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    /** When the picture was TAKEN, per the phone — not when it finally uploaded. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $takenAt = null;

    /**
     * WHERE THE SHUTTER FIRED, as GeoJSON Point text (§8) — its OWN place, not
     * the observation's.
     *
     * They are genuinely different facts and the difference is the reason this
     * column exists: a ranger stands where it is safe to stand and photographs
     * what is over there, and a photograph filed under an observation two
     * kilometres away is evidence of something at neither place unless it says
     * where it was taken.
     *
     * NULLABLE, and null means NO FIX — never 0,0, which is a real place in the
     * Gulf of Guinea and would put every unpositioned photograph on the same
     * island. The phone omits the parts entirely rather than zeroing them.
     */
    #[ORM\Column(type: 'point', nullable: true)]
    private ?string $position = null;

    /**
     * How good that fix was, in metres. Meaningless without {@see $position},
     * and refused at the door in that state rather than stored as a number
     * about nothing.
     */
    #[ORM\Column(nullable: true)]
    private ?float $accuracyM = null;

    /**
     * TRUE where this photograph arrived on a web AMENDMENT rather than off the
     * handset.
     *
     * Two sets of photographs with different provenance live in this one table,
     * and the difference matters twice over: PL·05 shows only what the field
     * took, and §9's completeness check counts only what the phone promised. A
     * default of FALSE is right for every row written before amendments existed
     * — all of them came from a handset.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $fromAmendment = false;

    public function __construct(Observation $observation, Uuid $clientUuid, string $storagePath)
    {
        $this->observation = $observation;
        $this->clientUuid = $clientUuid;
        $this->storagePath = $storagePath;
        $observation->addPhoto($this);
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObservation(): Observation
    {
        return $this->observation;
    }

    public function getClientUuid(): Uuid
    {
        return $this->clientUuid;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
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

    /**
     * What a page should ask the serving route for: the preview where there is
     * one, the original where there is not. Never a broken image.
     */
    public function getDisplayKey(): string
    {
        return $this->thumbKey ?? $this->storagePath;
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

    public function getTakenAt(): ?\DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function setTakenAt(?\DateTimeImmutable $takenAt): static
    {
        $this->takenAt = $takenAt;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getAccuracyM(): ?float
    {
        return $this->accuracyM;
    }

    public function setAccuracyM(?float $accuracyM): static
    {
        $this->accuracyM = $accuracyM;

        return $this;
    }

    /** Whether this photograph knows where it was taken. */
    public function hasPosition(): bool
    {
        return null !== $this->position && '' !== $this->position;
    }

    /**
     * Whether this photograph came in on a web AMENDMENT rather than off the
     * handset.
     *
     * The flag lives here, on the photograph, rather than being inferred by
     * asking every amendment what it holds: PL·05 draws the field photographs
     * and needs to exclude these in one pass, and §9's completeness check needs
     * the same answer for a different reason. One fact, one place.
     */
    public function isAmendmentAttachment(): bool
    {
        return $this->fromAmendment;
    }

    /**
     * Marked once, when the amendment that carries it is assembled. There is no
     * unmarking: a photograph does not become a field photograph later.
     */
    public function markAsAmendmentAttachment(): static
    {
        $this->fromAmendment = true;

        return $this;
    }
}
