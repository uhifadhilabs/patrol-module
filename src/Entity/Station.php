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
use Uhifadhi\Patrol\Repository\StationRepository;

/**
 * ONE PLACE A PATROL SETS OFF FROM, IN ONE AREA — a post, a camp, a gate. What
 * SET·03 draws a row for, with the count of patrols filed against it.
 *
 * A RECORD RATHER THAN A STRING, because the three things SET·03 asks for are
 * the three a string cannot do: rename a post without rewriting every patrol
 * filed against it, retire one the area has closed, and count what each
 * carries. And it is the AREA's: one area closing a post is not a reason for
 * another to lose it.
 *
 * THE KEY IS THE WIRE VALUE AND NEVER CHANGES — the handset sends it, a saved
 * filter holds it, an export column prints it. Unique WITHIN THE AREA.
 *
 * WHERE IT IS, WHERE THAT IS KNOWN. The coverage map draws a marker per station.
 * Null is a real state, and the marker is then placed at the first fix of a
 * patrol that set out from here
 * ({@see \Uhifadhi\Patrol\Service\PatrolDashboardService::coveragePayload()}) —
 * the best evidence there is, and better than an invented coordinate.
 *
 * RETIRE, NEVER DELETE — patrols are filed against it.
 */
#[ORM\Entity(repositoryClass: StationRepository::class)]
#[ORM\Table(name: 'patrol_station')]
#[ORM\UniqueConstraint(name: 'uniq_patrol_station_area_key', columns: ['area_id', 'station_key'])]
#[ORM\HasLifecycleCallbacks]
class Station
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

    /** `station_key` for the reason {@see PatrolType::$key} is `type_key`. */
    #[ORM\Column(name: 'station_key', length: 60)]
    private string $key;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Where it stands, as GeoJSON Point text; null where nobody has said. */
    #[ORM\Column(type: 'point', nullable: true)]
    private ?string $point = null;

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

    public function getPoint(): ?string
    {
        return $this->point;
    }

    public function setPoint(?string $point): static
    {
        $this->point = $point;

        return $this;
    }
}
