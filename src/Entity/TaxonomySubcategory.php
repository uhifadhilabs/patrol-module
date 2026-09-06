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
use Uhifadhi\Patrol\Entity\Trait\TimestampableTrait;
use Uhifadhi\Patrol\Repository\TaxonomySubcategoryRepository;

/**
 * A SUB-CATEGORY under one area's observation kind — the second (and last) level
 * of the area-scoped patrol taxonomy the design rules (two levels, no deeper). A
 * finer label under the kind, so "a carcass" can be counted apart from "a poached
 * carcass" without reading a thousand notes.
 *
 * LABELS ONLY — the whole difference from the incident taxonomy. A patrol
 * sub-category switches on NO behaviour blocks, carries NO money, asks NO
 * structured question: it groups, and nothing more. A kind with no sub-categories
 * is perfectly normal; the ranger simply never sees a second chip. When an
 * observation needs structured detail, it is filed as an incident instead.
 *
 * WIRE-CODE, RETIREMENT, RENAMING — the same rules as {@see TaxonomyKind}: the
 * code never changes, retirement dims but keeps, nothing is deleted. Labels are
 * unique within the parent kind; codes are unique within the area.
 */
#[ORM\Entity(repositoryClass: TaxonomySubcategoryRepository::class)]
#[ORM\Table(name: 'patrol_taxonomy_subcategory')]
#[ORM\HasLifecycleCallbacks]
class TaxonomySubcategory
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: TaxonomyKind::class, inversedBy: 'subcategories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TaxonomyKind $kind;

    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(TaxonomyKind $kind, string $code, string $label)
    {
        $this->uuid = Uuid::v7();
        $this->kind = $kind;
        $this->code = $code;
        $this->label = $label;
        $kind->addSubcategory($this);
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

    public function getKind(): TaxonomyKind
    {
        return $this->kind;
    }

    public function getCode(): string
    {
        return $this->code;
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
}
