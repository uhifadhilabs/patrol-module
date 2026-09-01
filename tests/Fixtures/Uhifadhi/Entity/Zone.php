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

namespace Uhifadhi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Entity\Trait\TimestampableTrait;
use Uhifadhi\Entity\Trait\UuidTrait;

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Entity\Zone — a named polygon
 * subdividing one area, the host's SPATIAL lens. Carried in autoload-dev so the
 * module's zone-absence SQL has a table and a mapping to read when the bundle is
 * tested standalone; the real class replaces it in-app.
 *
 * THE MODULE OWNS NO ZONE AND NAMES NONE. It asks one generic spatial question —
 * "which zones has a track entered, and when last?" — so only the three fields
 * that question needs are mirrored: the name, the area and the geometry. The
 * host's uniqueness constraint and its non-overlap invariant (ZoneService) are
 * its own and are omitted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'zone')] // matches uhifadhi's real table
#[ORM\HasLifecycleCallbacks]
class Zone
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $area = null;

    #[ORM\Column(type: 'multipolygon')]
    private ?string $geom = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getArea(): ?AreaOfInterest
    {
        return $this->area;
    }

    public function setArea(AreaOfInterest $area): static
    {
        $this->area = $area;

        return $this;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }

    public function setGeom(string $geom): static
    {
        $this->geom = $geom;

        return $this;
    }
}
