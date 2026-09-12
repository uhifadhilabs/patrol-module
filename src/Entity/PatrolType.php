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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Trait\TimestampableTrait;
use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Model\PatrolBaseDefaults;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;

/**
 * ONE KIND OF PATROL IN ONE AREA — "Foot patrol", "Vehicle patrol", "Drone
 * sortie". The words a ranger picks from when they open a patrol, and the axis
 * every colour, chart and filter in this module groups by.
 *
 * PER AREA, because that is what the Patrol types section draws: a row with the count of patrols
 * filed under it and its own rename and retire. An installation-wide list could
 * show neither — one area's count is not another's, and retiring a type for
 * everybody because one area stopped walking is not a thing an area
 * administrator may do. The installation's `patrol.types` configuration survives
 * as the SEED a NEW area starts from, and nothing more.
 *
 * THE KEY IS THE WIRE VALUE AND NEVER CHANGES. It is what a saved filter, an
 * export column, an offline handset and {@see Patrol::DRONE_TYPE} actually hold;
 * renaming the label leaves it untouched. Unique WITHIN THE AREA.
 *
 * RETIRE, NEVER DELETE. Patrols are filed against a type, so the row, its key
 * and every patrol under it are kept; retiring takes it off the handset at the
 * next sync and one click brings it back.
 */
#[ORM\Entity(repositoryClass: PatrolTypeRepository::class)]
#[ORM\Table(name: 'patrol_type')]
#[ORM\UniqueConstraint(name: 'uniq_patrol_type_area_key', columns: ['area_id', 'type_key'])]
#[ORM\HasLifecycleCallbacks]
class PatrolType
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * `type_key` rather than `key`: KEY is a reserved word in several platforms
     * and a column nobody can write bare is a column that breaks a dump.
     */
    #[ORM\Column(name: 'type_key', length: 40)]
    private string $key;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** A retired type is DIMMED on the settings row, never hidden. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /**
     * WHAT IT RECORDS — the one thing about a type that is a fixed key rather
     * than a word somebody chose, and what a handset builds its screen from.
     *
     * NULL IS A STATE THE SECTION DRAWS: a type carried over from before bases
     * existed has none, its row asks for one, and nothing is blocked while it
     * has none.
     */
    #[ORM\Column(length: 12, nullable: true, enumType: PatrolBaseEnum::class)]
    private ?PatrolBaseEnum $base = null;

    /**
     * THE FOUR THE BASE PREFILLS AND THE TYPE THEN OWNS. Seeded from
     * {@see PatrolBaseDefaults} the moment a base is chosen and editable per type
     * afterwards, which is why they are columns here and not a lookup at read
     * time: an installation that tunes a default later never re-tunes an area
     * that had already chosen.
     *
     * The pace band is what gap detection and the stopped rule are read against;
     * the buffer is how wide this type's track counts as covered ground; the
     * placement is where an observation of this type of patrol is put.
     */
    #[ORM\Column(nullable: true)]
    private ?int $paceMinKmh = null;

    #[ORM\Column(nullable: true)]
    private ?int $paceMaxKmh = null;

    #[ORM\Column(nullable: true)]
    private ?int $coverageBufferM = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ObservationPlacementEnum::class)]
    private ?ObservationPlacementEnum $observationPlacement = null;

    /**
     * The mark this type wears, from the house set the section's strip offers.
     * Null falls back to the base's own, so a row always draws a mark.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $glyph = null;

    public function __construct(AreaOfInterest $area, string $key, string $label)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->key = $key;
        $this->label = $label;
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

    public function getKey(): string
    {
        return $this->key;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): static
    {
        $this->active = false;

        return $this;
    }

    public function reactivate(): static
    {
        $this->active = true;

        return $this;
    }

    public function getBase(): ?PatrolBaseEnum
    {
        return $this->base;
    }

    public function setBase(?PatrolBaseEnum $base): static
    {
        $this->base = $base;

        return $this;
    }

    public function getPaceMinKmh(): ?int
    {
        return $this->paceMinKmh;
    }

    public function setPaceMinKmh(?int $paceMinKmh): static
    {
        $this->paceMinKmh = $paceMinKmh;

        return $this;
    }

    public function getPaceMaxKmh(): ?int
    {
        return $this->paceMaxKmh;
    }

    public function setPaceMaxKmh(?int $paceMaxKmh): static
    {
        $this->paceMaxKmh = $paceMaxKmh;

        return $this;
    }

    public function getCoverageBufferM(): ?int
    {
        return $this->coverageBufferM;
    }

    public function setCoverageBufferM(?int $coverageBufferM): static
    {
        $this->coverageBufferM = $coverageBufferM;

        return $this;
    }

    public function getObservationPlacement(): ?ObservationPlacementEnum
    {
        return $this->observationPlacement;
    }

    public function setObservationPlacement(?ObservationPlacementEnum $placement): static
    {
        $this->observationPlacement = $placement;

        return $this;
    }

    public function getGlyph(): ?string
    {
        return $this->glyph;
    }

    public function setGlyph(?string $glyph): static
    {
        $this->glyph = $glyph;

        return $this;
    }
}
