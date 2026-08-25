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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Entity\Trait\TimestampableTrait;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Enum\PatrolStatusEnum;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;

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

    /**
     * The one patrol type the sync contract gives its own rules: a drone patrol
     * never posts a track (§5) and declares its coverage as launch-point
     * sectors instead (§7). Patrol types are otherwise deployment vocabulary,
     * never an enum — but "drone" carries behaviour, so the module has to be
     * able to name it.
     */
    public const string DRONE_TYPE = 'drone';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * The UUID the FIELD APP generated for this patrol, before it had any
     * network — the idempotency key the whole sync contract rests on
     * (API-CONTRACT.md §1). A re-sent create with a clientUuid we already hold
     * is success, never a conflict, and never a second patrol.
     *
     * Deliberately NOT reusing {@see $uuid}: that one is ours, minted here and
     * used in web URLs. Keeping the client's identifier in its own column means
     * a patrol always says plainly where it came from, and a phone can never
     * name a patrol the web module created.
     *
     * Null for every patrol born in the web module (GPX import, manual log).
     */
    #[ORM\Column(type: 'uuid', unique: true, nullable: true)]
    private ?Uuid $clientUuid = null;

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

    /**
     * The team as the FIELD APP named them — the ranger ids it sent and will
     * send again. {@see $team} above is the human sentence the web module
     * renders; these are the identifiers, kept because a name is not an
     * identity and re-deriving ids from a joined string would be guesswork.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $teamRangerIds = [];

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

    /**
     * Defaults to Complete, and that is not laziness: a patrol logged or
     * imported in the WEB module is a finished record the moment it is saved.
     * Only the field app's piecemeal upload starts life as Recording, and only
     * its verified `complete` call moves it back here.
     */
    #[ORM\Column(enumType: PatrolStatusEnum::class, options: ['default' => 'complete'])]
    private PatrolStatusEnum $status = PatrolStatusEnum::Complete;

    /** The aircraft's identifier, drone patrols only (API-CONTRACT.md §4). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $droneId = null;

    /** The flight mission this patrol served, drone patrols only. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mission = null;

    /** The handset that recorded it — provenance, for when a device misbehaves. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceId = null;

    /** The app build that produced it, so a bad release can be identified later. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $appVersion = null;

    /**
     * When somebody edited this patrol in the WEB module.
     *
     * The contract gives editing exactly one writer (§10): once a patrol is
     * acknowledged it is immutable on the phone, and corrections happen here.
     * If a phone then re-sends a part for it — a retry that outlived the
     * acknowledgement — the answer is 409 `patrol_immutable`, because silently
     * applying it would overwrite a human's correction with a stale queue.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $webEditedAt = null;

    /** The route as GeoJSON LineString text (postgis-bundle `linestring` type). */
    #[ORM\Column(type: 'linestring', nullable: true)]
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

    /**
     * The batches of fixes the phone uploaded, kept as rows so a re-sent batch
     * can be recognised and ignored.
     *
     * @var Collection<int, TrackBatch>
     */
    #[ORM\OneToMany(targetEntity: TrackBatch::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $trackBatches;

    /**
     * Every individual fix, with its own accuracy. The assembled LINESTRING in
     * {@see $track} is derived from these; the fixes are the record.
     *
     * @var Collection<int, TrackPoint>
     */
    #[ORM\OneToMany(targetEntity: TrackPoint::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['recordedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $trackPoints;

    /** @var Collection<int, LaunchPoint> */
    #[ORM\OneToMany(targetEntity: LaunchPoint::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $launchPoints;

    /** @var Collection<int, Flight> */
    #[ORM\OneToMany(targetEntity: Flight::class, mappedBy: 'patrol', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC', 'id' => 'ASC'])]
    private Collection $flights;

    public function __construct(AreaOfInterest $area, string $type)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->type = $type;
        $this->observations = new ArrayCollection();
        $this->trackBatches = new ArrayCollection();
        $this->trackPoints = new ArrayCollection();
        $this->launchPoints = new ArrayCollection();
        $this->flights = new ArrayCollection();
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

    /**
     * A drone patrol: its coverage is declared sectors, not a walked line. The
     * phone's own positions during one are the OPERATOR's, so they are never
     * accepted as this patrol's track (API-CONTRACT.md §5).
     */
    public function isDrone(): bool
    {
        return self::DRONE_TYPE === $this->type;
    }

    public function getClientUuid(): ?Uuid
    {
        return $this->clientUuid;
    }

    public function setClientUuid(?Uuid $clientUuid): static
    {
        $this->clientUuid = $clientUuid;

        return $this;
    }

    /** @return list<string> */
    public function getTeamRangerIds(): array
    {
        return $this->teamRangerIds;
    }

    /** @param list<string> $teamRangerIds */
    public function setTeamRangerIds(array $teamRangerIds): static
    {
        $this->teamRangerIds = array_values(array_unique($teamRangerIds));

        return $this;
    }

    public function getStatus(): PatrolStatusEnum
    {
        return $this->status;
    }

    public function setStatus(PatrolStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDroneId(): ?string
    {
        return $this->droneId;
    }

    public function setDroneId(?string $droneId): static
    {
        $this->droneId = $droneId;

        return $this;
    }

    public function getMission(): ?string
    {
        return $this->mission;
    }

    public function setMission(?string $mission): static
    {
        $this->mission = $mission;

        return $this;
    }

    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    public function setDeviceId(?string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function setAppVersion(?string $appVersion): static
    {
        $this->appVersion = $appVersion;

        return $this;
    }

    public function getWebEditedAt(): ?\DateTimeImmutable
    {
        return $this->webEditedAt;
    }

    public function markWebEdited(?\DateTimeImmutable $at = null): static
    {
        $this->webEditedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Whether the phone may still add parts to this patrol. False once a human
     * has corrected it in the web module — from then on the phone is told
     * `patrol_immutable` and stops trying (API-CONTRACT.md §10).
     */
    public function acceptsFieldUploads(): bool
    {
        return null === $this->webEditedAt;
    }

    /** @return Collection<int, TrackBatch> */
    public function getTrackBatches(): Collection
    {
        return $this->trackBatches;
    }

    public function addTrackBatch(TrackBatch $batch): static
    {
        if (!$this->trackBatches->contains($batch)) {
            $this->trackBatches->add($batch);
        }

        return $this;
    }

    /** @return Collection<int, TrackPoint> */
    public function getTrackPoints(): Collection
    {
        return $this->trackPoints;
    }

    public function addTrackPoint(TrackPoint $point): static
    {
        if (!$this->trackPoints->contains($point)) {
            $this->trackPoints->add($point);
        }

        return $this;
    }

    /** @return Collection<int, LaunchPoint> */
    public function getLaunchPoints(): Collection
    {
        return $this->launchPoints;
    }

    public function addLaunchPoint(LaunchPoint $launchPoint): static
    {
        if (!$this->launchPoints->contains($launchPoint)) {
            $this->launchPoints->add($launchPoint);
        }

        return $this;
    }

    /** @return Collection<int, Flight> */
    public function getFlights(): Collection
    {
        return $this->flights;
    }

    public function addFlight(Flight $flight): static
    {
        if (!$this->flights->contains($flight)) {
            $this->flights->add($flight);
        }

        return $this;
    }

    /**
     * Whether this patrol carries a RECORDED route — a GPX import or an API
     * feed — as opposed to nothing at all or a hand-sketched line. Only a
     * recorded route may be offered back as GPX: handing a sketch out as a
     * .gpx file would let it re-enter the world as a recording
     * (docs/design-decisions.md §4).
     */
    public function hasRecordedTrack(): bool
    {
        return null !== $this->track && PatrolSourceEnum::Manual !== $this->source;
    }

    /** Display reference ("P-0142") — presentation only, derived from the id. */
    public function getRef(): string
    {
        return \sprintf('P-%04d', $this->id ?? 0);
    }
}
