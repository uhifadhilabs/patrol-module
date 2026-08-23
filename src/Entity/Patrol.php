<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Access\Entity\User;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use UhifadhiLabs\PatrolBundle\Entity\Trait\TimestampableTrait;
use UhifadhiLabs\PatrolBundle\Enum\PatrolSourceEnum;
use UhifadhiLabs\PatrolBundle\Repository\PatrolRepository;

/**
 * One patrol: a typed, timed record of field effort — who led it, from which
 * station, when, how far — with an optional geometry track. GPX-born patrols
 * carry the recorded LineString plus its honesty metadata (point count, GPS
 * gaps); manual patrols may carry a sketched route, clearly marked as such via
 * {@see PatrolSourceEnum}.
 *
 * The type is deployment vocabulary (patrol.types config), stored as its key —
 * never an enum in code.
 */
#[ORM\Entity(repositoryClass: PatrolRepository::class)]
#[ORM\Table(name: 'patrol_patrol')]
#[ORM\HasLifecycleCallbacks]
class Patrol
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /** The host area this patrol belongs to. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /** A patrol.types key ("foot", "boat", …) — deployment vocabulary. */
    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $station = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lead = null;

    /** Free-text team roster ("A. Example, B. Example"). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $team = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(nullable: true)]
    private ?float $distanceKm = null;

    #[ORM\Column(enumType: PatrolSourceEnum::class)]
    private PatrolSourceEnum $source = PatrolSourceEnum::Manual;

    /** The route as GeoJSON LineString text (see postgis-bundle GeometryType). */
    #[ORM\Column(type: 'geometry', nullable: true, options: ['geometry_type' => 'LineString', 'srid' => 4326])]
    private ?string $track = null;

    #[ORM\Column(nullable: true)]
    private ?int $pointCount = null;

    /** GPS silences above the configured threshold — flagged, never smoothed. */
    #[ORM\Column(options: ['default' => 0])]
    private int $gapCount = 0;

    /** @var Collection<int, Observation> */
    #[ORM\OneToMany(targetEntity: Observation::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['loggedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $observations;

    public function __construct(AreaOfInterest $area, string $type)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->type = $type;
        $this->observations = new ArrayCollection();
        // Values exist pre-flush; PrePersist keeps them if already set.
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

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStation(): ?string
    {
        return $this->station;
    }

    public function setStation(?string $station): static
    {
        $this->station = $station;

        return $this;
    }

    public function getLead(): ?User
    {
        return $this->lead;
    }

    public function setLead(?User $lead): static
    {
        $this->lead = $lead;

        return $this;
    }

    public function getTeam(): ?string
    {
        return $this->team;
    }

    public function setTeam(?string $team): static
    {
        $this->team = $team;

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

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getDistanceKm(): ?float
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?float $distanceKm): static
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    public function getSource(): PatrolSourceEnum
    {
        return $this->source;
    }

    public function setSource(PatrolSourceEnum $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getTrack(): ?string
    {
        return $this->track;
    }

    public function setTrack(?string $track): static
    {
        $this->track = $track;

        return $this;
    }

    public function getPointCount(): ?int
    {
        return $this->pointCount;
    }

    public function setPointCount(?int $pointCount): static
    {
        $this->pointCount = $pointCount;

        return $this;
    }

    public function getGapCount(): int
    {
        return $this->gapCount;
    }

    public function setGapCount(int $gapCount): static
    {
        $this->gapCount = $gapCount;

        return $this;
    }

    /** @return Collection<int, Observation> */
    public function getObservations(): Collection
    {
        return $this->observations;
    }

    public function addObservation(Observation $observation): static
    {
        if (!$this->observations->contains($observation)) {
            $this->observations->add($observation);
        }

        return $this;
    }

    /** Display reference ("P-0142") — presentation only, derived from the id. */
    public function getRef(): string
    {
        return \sprintf('P-%04d', $this->id ?? 0);
    }
}
