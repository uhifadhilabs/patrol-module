<?php

declare(strict_types=1);

namespace Uhifadhi\Access\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Access\Entity\Position — the reviewer's
 * job title (e.g. "Ranger"), shown as the role sublabel under the reviewer's name on
 * the campaign dashboard's review team. Carried in autoload-dev so the User→Position
 * mapping resolves when the bundle is tested standalone; the real class replaces it
 * in-app. Mirrors only the field the module reads (name); permissions/lock are omitted.
 */
#[ORM\Entity]
class Position
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 120)]
    private ?string $name = null;

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
}
