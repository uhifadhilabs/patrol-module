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
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Entity\Department — the org-wide unit a
 * {@see Position} sits in, and therefore the thing a department KPI is sliced BY.
 * Carried in autoload-dev so Position→Department resolves when the bundle is tested
 * standalone; the real class replaces it in-app.
 *
 * Mirrors only what the module reads: the id (it groups by it) and the name (it captions
 * with it). The real class also owns the attached-modules join, which is the HOST's
 * business — this bundle is only ever asked about a department the host already decided
 * attaches Patrols.
 */
#[ORM\Entity]
#[ORM\Table(name: 'department')]
class Department
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 120, unique: true)]
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
