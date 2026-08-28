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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Trait\TimestampableTrait;
use Uhifadhi\Entity\Trait\UuidTrait;
use Uhifadhi\Repository\WidgetCustomPresetRepository;
use Uhifadhi\Service\WidgetService;

/**
 * A layout one person saved under their own name, on one dashboard SURFACE: the
 * other half of presets. A surface SHIPS the design directions it was drawn in;
 * a person KEEPS the arrangements they actually work in ("morning check",
 * "board meeting") and puts one back on in a click.
 *
 * The scoping trio is the one {@see WidgetPreference} uses — surface, user, and
 * a nullable area for the surfaces that have one — held as plain values for the
 * same reason: a saved layout is a UI scrap and deleting an area must never be
 * blocked by one. It is addressed externally by its UUID, never by the
 * sequential id.
 *
 * `layout` is a {@see \Uhifadhi\Model\WidgetPreset} layout — widget id => span,
 * in order, listed meaning on — NOT the stored-preference shape. It is written
 * from a resolved layout and read back tolerantly, so a preset saved before a
 * widget was retired still applies.
 */
#[ORM\Entity(repositoryClass: WidgetCustomPresetRepository::class)]
#[ORM\Table(name: 'widget_custom_preset')]
// ONE preset per name per (surface, user, area) — so saving under a name you
// already used replaces that preset rather than growing a second card with the
// same word on it. Two partial unique indexes for the same reason
// widget_preference needs two: Postgres treats NULLs as distinct, so a single
// four-column constraint would not constrain the org-wide rows at all. The WHERE
// clauses name the column, which is what Postgres reads — keep them in step with
// the naming strategy (underscore).
#[ORM\UniqueConstraint(
    name: 'uniq_widget_preset_surface_user_area_name',
    fields: ['surface', 'userId', 'areaUuid', 'name'],
    options: ['where' => '(area_uuid IS NOT NULL)'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_widget_preset_surface_user_org_name',
    fields: ['surface', 'userId', 'name'],
    options: ['where' => '(area_uuid IS NULL)'],
)]
#[ORM\HasLifecycleCallbacks]
class WidgetCustomPreset
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /** The dashboard this layout belongs to, e.g. 'departments' — see WidgetCatalog::$surface. */
    #[ORM\Column(length: 64)]
    private string $surface;

    #[ORM\Column]
    private int $userId;

    /** Null on an org-wide surface, which has no area to scope a layout to. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $areaUuid;

    /** What the person called it. Trimmed and length-checked by WidgetService before it lands here. */
    #[ORM\Column(length: WidgetService::NAME_MAX)]
    private string $name;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $layout = [];

    /** @param array<string, int> $layout */
    public function __construct(string $surface, int $userId, ?Uuid $areaUuid, string $name, array $layout = [])
    {
        $this->surface = $surface;
        $this->userId = $userId;
        $this->areaUuid = $areaUuid;
        $this->name = $name;
        $this->layout = $layout;
        // Values exist pre-flush; the PrePersist callbacks keep what is set.
        $this->initTimestamps();
        $this->generateUuid();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSurface(): string
    {
        return $this->surface;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getAreaUuid(): ?Uuid
    {
        return $this->areaUuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return array<string, int> */
    public function getLayout(): array
    {
        return $this->layout;
    }

    /** @param array<string, int> $layout */
    public function setLayout(array $layout): static
    {
        $this->layout = $layout;

        return $this;
    }
}
