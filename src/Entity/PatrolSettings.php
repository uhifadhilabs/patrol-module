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
use Uhifadhi\Patrol\Repository\PatrolSettingsRepository;

/**
 * WHAT ONE AREA RUNS PATROLS ON — the numbers that used to be one installation's
 * opinion and are now each area's own.
 *
 * ONE ROW PER AREA, and the row may be absent. An area that has never opened
 * the Settings section has no row, and the service answers out of the
 * installation's configuration instead; writing a row for every area at install
 * time would make an untouched default indistinguishable from a chosen one.
 *
 * COLUMNS, NOT A JSON BLOB. Each of these is a typed, bounded number the module
 * reads in anger — a gap threshold decides whether a track is drawn broken, a
 * retention decides when a discarded patrol is destroyed — so each gets a
 * column: the database can constrain it, a migration can change it in the open,
 * phpstan can check the type, and a reader can see the whole of what an area may
 * set by looking at the table. A JSON document would hide all four behind a
 * string and put the schema in whichever code last wrote it.
 *
 * THIN, like every entity here: state and accessors, no behaviour. What the
 * numbers MEAN is {@see \Uhifadhi\Patrol\Service\PatrolSettingsService}'s.
 */
#[ORM\Entity(repositoryClass: PatrolSettingsRepository::class)]
#[ORM\Table(name: 'patrol_settings')]
#[ORM\UniqueConstraint(name: 'uniq_patrol_settings_area', columns: ['area_id'])]
#[ORM\HasLifecycleCallbacks]
class PatrolSettings
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * Minutes without a fix before a track is drawn as broken and the patrol is
     * flagged for review.
     */
    #[ORM\Column(type: 'smallint')]
    private int $gapThresholdMinutes;

    /** Days a discarded patrol stays recoverable before it is purged. */
    #[ORM\Column(type: 'smallint')]
    private int $discardRetentionDays;

    public function __construct(AreaOfInterest $area, int $gapThresholdMinutes, int $discardRetentionDays)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->gapThresholdMinutes = $gapThresholdMinutes;
        $this->discardRetentionDays = $discardRetentionDays;
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

    public function getGapThresholdMinutes(): int
    {
        return $this->gapThresholdMinutes;
    }

    public function setGapThresholdMinutes(int $minutes): self
    {
        $this->gapThresholdMinutes = $minutes;

        return $this;
    }

    public function getDiscardRetentionDays(): int
    {
        return $this->discardRetentionDays;
    }

    public function setDiscardRetentionDays(int $days): self
    {
        $this->discardRetentionDays = $days;

        return $this;
    }
}
