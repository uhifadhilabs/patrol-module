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
     * Where the bytes are, relative to the module's configured photo directory.
     * Relative on purpose: an absolute path would bake one machine's filesystem
     * into the database and break the moment the deployment moves.
     */
    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    /** When the picture was TAKEN, per the phone — not when it finally uploaded. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $takenAt = null;

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
}
