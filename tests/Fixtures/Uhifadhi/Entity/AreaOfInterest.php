<?php

declare(strict_types=1);

namespace Uhifadhi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Entity\AreaOfInterest — carried
 * in the bundle's autoload-dev so the Patrol→AreaOfInterest mapping resolves when
 * the bundle is tested and phpstan'd in isolation. NOT shipped (autoload-dev is
 * dropped on install), so inside uhifadhi the REAL AreaOfInterest is loaded instead.
 * It mirrors the real class for the fields the module reads — id + uuid + name; the real
 * source/IUCN metadata are omitted because the module never reads them; geom is
 * mirrored since the coverage map draws the area boundary.
 */
#[ORM\Entity]
#[ORM\Table(name: 'area_of_interest')] // matches uhifadhi's real table
class AreaOfInterest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\Column(type: 'multipolygon', nullable: true)]
    private ?string $geom = null;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getUuidString(): ?string
    {
        return $this->uuid->toRfc4122();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
