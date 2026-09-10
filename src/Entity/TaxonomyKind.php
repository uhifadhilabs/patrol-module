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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Trait\TimestampableTrait;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;

/**
 * ONE KIND OF OBSERVATION IN ONE AREA — the top level of the area-scoped patrol
 * taxonomy the design rules out (option B): the taxonomy is keyed to the CURRENT
 * area, and every area owns its own. One area's observation kinds are a different
 * list from the next area's; the two never merge, and nothing here is shared with
 * another area. A kind is the chip a ranger taps first on the handset, and the
 * grouping every count and filter in this area uses.
 *
 * THIS IS THE ADMIN'S MODEL FOR OBSERVATION VOCABULARY, and it is a PARALLEL model
 * to the flat `patrol.observation_categories` deployment config that
 * {@see Observation::$category} still reads. The two coexist while the platform
 * converges on one; wiring observation capture (the web module and the field app)
 * onto this area-scoped taxonomy is a follow-up, not part of this admin.
 *
 * SHALLOW — LABELS ONLY. Unlike the incident taxonomy, a patrol kind carries NO
 * colour (the marker colour is the patrol type), NO term, NO money, and its
 * sub-categories switch on NO behaviour blocks: they group, they never ask a
 * question. The moment an observation needs a structured question, a clock or a
 * fine, the right control is "File as incident".
 *
 * EMPTY-START, DEACTIVATE-NEVER-DELETE. An area begins with NO kinds — the
 * platform ships none and suggests none. A kind is created, renamed and RETIRED
 * ({@see $active}) but never deleted, because observations are filed under it and
 * a field record must never lose the words that describe it.
 *
 * THE WIRE-CODE NEVER CHANGES. {@see $code} is what a saved filter, an export
 * column and an offline handset actually hold; renaming the label leaves it
 * untouched. It is unique WITHIN THE AREA and independent of any other area's
 * codes.
 */
#[ORM\Entity(repositoryClass: TaxonomyKindRepository::class)]
#[ORM\Table(name: 'patrol_taxonomy_kind')]
#[ORM\UniqueConstraint(name: 'uniq_patrol_tx_kind_area_code', columns: ['area_id', 'code'])]
#[ORM\HasLifecycleCallbacks]
class TaxonomyKind
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * The area this kind belongs to. Mapped to the concrete AreaOfInterest, as
     * {@see Patrol::$area} is: this bundle already requires uhifadhi/area-module
     * and its entity is the one identity ({@see AreaOfInterest::getId()}) an area
     * is told apart by. onDelete CASCADE, so removing an area takes its taxonomy.
     */
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /** Stable across renames — see the class docblock. Unique within the area. */
    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** A retired kind is DIMMED, never hidden and never struck through. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, TaxonomySubcategory> */
    #[ORM\OneToMany(targetEntity: TaxonomySubcategory::class, mappedBy: 'kind', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $subcategories;

    public function __construct(AreaOfInterest $area, string $code, string $label)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->code = $code;
        $this->label = $label;
        $this->subcategories = new ArrayCollection();
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

    /** @return Collection<int, TaxonomySubcategory> */
    public function getSubcategories(): Collection
    {
        return $this->subcategories;
    }

    public function addSubcategory(TaxonomySubcategory $subcategory): static
    {
        if (!$this->subcategories->contains($subcategory)) {
            $this->subcategories->add($subcategory);
        }

        return $this;
    }

    /** How many sub-categories are still live under this kind. */
    public function activeSubcategoryCount(): int
    {
        $count = 0;
        foreach ($this->subcategories as $subcategory) {
            if ($subcategory->isActive()) {
                ++$count;
            }
        }

        return $count;
    }
}
