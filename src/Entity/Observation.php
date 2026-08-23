<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Entity\Trait\TimestampableTrait;
use UhifadhiLabs\Patrol\Repository\ObservationRepository;

/**
 * A georeferenced field note logged en route — the patrol's eyes: a category
 * (deployment vocabulary, patrol.observation_categories), a verbatim note and
 * an optional position. Observations are the raw material other modules refine
 * (an observation can later be filed as an incident); they are records, never
 * silently edited — corrections append.
 */
#[ORM\Entity(repositoryClass: ObservationRepository::class)]
#[ORM\Table(name: 'patrol_observation')]
#[ORM\HasLifecycleCallbacks]
class Observation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Patrol::class, inversedBy: 'observations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /** A patrol.observation_categories key — deployment vocabulary. */
    #[ORM\Column(length: 40)]
    private string $category;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** Where it was seen, as GeoJSON Point text. */
    #[ORM\Column(type: 'point', nullable: true)]
    private ?string $position = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $loggedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recordedBy = null;

    public function __construct(Patrol $patrol, string $category)
    {
        $this->uuid = Uuid::v7();
        $this->patrol = $patrol;
        $this->category = $category;
        $patrol->addObservation($this);
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getPatrol(): Patrol
    {
        return $this->patrol;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

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

    public function getLoggedAt(): ?\DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function setLoggedAt(?\DateTimeImmutable $loggedAt): static
    {
        $this->loggedAt = $loggedAt;

        return $this;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function setRecordedBy(?User $recordedBy): static
    {
        $this->recordedBy = $recordedBy;

        return $this;
    }
}
