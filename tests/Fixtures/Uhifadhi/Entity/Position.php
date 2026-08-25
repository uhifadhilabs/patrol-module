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

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Entity\Position — the reviewer's
 * job title (e.g. "Ranger"), shown as the role sublabel under the reviewer's name on
 * the campaign dashboard's review team. Carried in autoload-dev so the User→Position
 * mapping resolves when the bundle is tested standalone; the real class replaces it
 * in-app. Mirrors the fields the module reads: the name, and the DEPARTMENT the position
 * sits in — which is how a department KPI is sliced, since a person's department is derived
 * only through their position. Permissions and lock are the host's and are omitted.
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

    /**
     * The department this position sits in, if any. Purely organizational in the host, and
     * the ONLY path from a recorded row to a department: patrol → lead → position →
     * department.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): static
    {
        $this->department = $department;

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
