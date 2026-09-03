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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Patrol\Enum\SectorTypeEnum;
use Uhifadhi\Patrol\Repository\LaunchPointRepository;

/**
 * A spot a drone took off from, and the sector it was said to cover —
 * API-CONTRACT.md §7.
 *
 * Coverage here is DECLARED, not measured. Nobody records where the aircraft
 * actually flew (the GPX/KML is attached by hand in the web module afterwards),
 * so what the module can honestly draw is the sector the operator claimed from
 * this point — never a line pretending to be a flight path.
 */
#[ORM\Entity(repositoryClass: LaunchPointRepository::class)]
#[ORM\Table(name: 'patrol_launch_point')]
#[ORM\HasLifecycleCallbacks]
class LaunchPoint
{
    use Trait\TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Patrol::class, inversedBy: 'launchPoints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /** The phone's own id for this launch point — the idempotency key (§1). */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $clientUuid;

    /** The short tag used on the map ("LP-1"). */
    #[ORM\Column(length: 40)]
    private string $label;

    /** The fuller name a person reads ("North Gate LP-1"). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    /** Where the aircraft left the ground, as GeoJSON Point text. */
    #[ORM\Column(type: 'point')]
    private string $position;

    #[ORM\Column(nullable: true)]
    private ?float $accuracyM = null;

    #[ORM\Column(nullable: true)]
    private ?int $satellites = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $establishedAt = null;

    #[ORM\Column(enumType: SectorTypeEnum::class)]
    private SectorTypeEnum $sectorType = SectorTypeEnum::Radius;

    /** Set when {@see $sectorType} is Radius — the circle's radius in metres. */
    #[ORM\Column(nullable: true)]
    private ?float $sectorRadiusM = null;

    /** Set when {@see $sectorType} is Polygon — GeoJSON Polygon text, lon/lat. */
    #[ORM\Column(type: 'polygon', nullable: true)]
    private ?string $sectorPolygon = null;

    public function __construct(Patrol $patrol, Uuid $clientUuid, string $label, string $position)
    {
        $this->patrol = $patrol;
        $this->clientUuid = $clientUuid;
        $this->label = $label;
        $this->position = $position;
        $patrol->addLaunchPoint($this);
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPatrol(): Patrol
    {
        return $this->patrol;
    }

    public function getClientUuid(): Uuid
    {
        return $this->clientUuid;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function setPosition(string $position): static
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

    public function getSatellites(): ?int
    {
        return $this->satellites;
    }

    public function setSatellites(?int $satellites): static
    {
        $this->satellites = $satellites;

        return $this;
    }

    public function getEstablishedAt(): ?\DateTimeImmutable
    {
        return $this->establishedAt;
    }

    public function setEstablishedAt(?\DateTimeImmutable $establishedAt): static
    {
        $this->establishedAt = $establishedAt;

        return $this;
    }

    public function getSectorType(): SectorTypeEnum
    {
        return $this->sectorType;
    }

    public function getSectorRadiusM(): ?float
    {
        return $this->sectorRadiusM;
    }

    public function getSectorPolygon(): ?string
    {
        return $this->sectorPolygon;
    }

    /**
     * A radius sector. Set through a named method rather than two loose setters
     * so the pair can never disagree — a "radius" sector with a polygon and no
     * radius would be undrawable, and the module would have no honest fallback.
     */
    public function declareRadiusSector(float $radiusM): static
    {
        $this->sectorType = SectorTypeEnum::Radius;
        $this->sectorRadiusM = $radiusM;
        $this->sectorPolygon = null;

        return $this;
    }

    /** A drawn sector, as GeoJSON Polygon text (lon/lat). */
    public function declarePolygonSector(string $geoJsonPolygon): static
    {
        $this->sectorType = SectorTypeEnum::Polygon;
        $this->sectorPolygon = $geoJsonPolygon;
        $this->sectorRadiusM = null;

        return $this;
    }
}
