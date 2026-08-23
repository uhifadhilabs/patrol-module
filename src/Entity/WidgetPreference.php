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

namespace UhifadhiLabs\Patrol\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Entity\Trait\TimestampableTrait;
use UhifadhiLabs\Patrol\Repository\WidgetPreferenceRepository;

/**
 * One person's patrols-dashboard layout for one area: which widgets they keep,
 * how wide, in what order. Absence is the design's default layout, so a user who
 * never opened the widget library has no row at all and "reset" is a delete.
 *
 * The area is held as its UUID rather than a relation: a preference is a UI
 * scrap, and deleting an area must never be blocked by one. The user is held as
 * the host's user id for the same reason — the bundle stores no host relation it
 * would have to keep referentially honest.
 *
 * `prefs` is the stored shape {order: [id, …], widgets: {id: {on, cols}}},
 * always written through {@see \UhifadhiLabs\Patrol\Service\PatrolWidgetService},
 * never trusted on the way out.
 */
#[ORM\Entity(repositoryClass: WidgetPreferenceRepository::class)]
#[ORM\Table(name: 'patrol_widget_preference')]
// Declared by FIELD, not by column: the column names are the host's naming
// strategy's business, and a host that underscores them must still get the
// constraint.
#[ORM\UniqueConstraint(name: 'uniq_patrol_widget_pref_area_user', fields: ['areaUuid', 'userId'])]
#[ORM\HasLifecycleCallbacks]
class WidgetPreference
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid')]
    private Uuid $areaUuid;

    #[ORM\Column]
    private int $userId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $prefs = [];

    /** @param array<string, mixed> $prefs */
    public function __construct(Uuid $areaUuid, int $userId, array $prefs = [])
    {
        $this->areaUuid = $areaUuid;
        $this->userId = $userId;
        $this->prefs = $prefs;
        // Values exist pre-flush; PrePersist keeps them if already set.
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAreaUuid(): Uuid
    {
        return $this->areaUuid;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    /** @return array<string, mixed> */
    public function getPrefs(): array
    {
        return $this->prefs;
    }

    /** @param array<string, mixed> $prefs */
    public function setPrefs(array $prefs): static
    {
        $this->prefs = $prefs;

        return $this;
    }
}