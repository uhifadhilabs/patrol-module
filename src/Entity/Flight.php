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
use Uhifadhi\Patrol\Repository\FlightRepository;

/**
 * One sortie from a {@see LaunchPoint} — API-CONTRACT.md §7.
 *
 * There is no telemetry: the aircraft's own track is attached later, by hand,
 * in the web module. A flight is therefore a time box and nothing more, and the
 * module must not imply otherwise.
 */
#[ORM\Entity(repositoryClass: FlightRepository::class)]
#[ORM\Table(name: 'patrol_flight')]
#[ORM\HasLifecycleCallbacks]
class Flight
{
    use Trait\TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Patrol::class, inversedBy: 'flights')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /** The phone's own id for this flight — the idempotency key (§1). */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $clientUuid;

    #[ORM\ManyToOne(targetEntity: LaunchPoint::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LaunchPoint $launchPoint = null;

    /** Which sortie of the patrol this was (1, 2, 3 …). */
    #[ORM\Column(options: ['default' => 1])]
    private int $sequence = 1;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    /**
     * Null means the aircraft was still airborne when the phone uploaded (§7) —
     * a real state, not missing data, so it is never backfilled with a guess.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(Patrol $patrol, Uuid $clientUuid)
    {
        $this->patrol = $patrol;
        $this->clientUuid = $clientUuid;
        $patrol->addFlight($this);
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPatrol(): Patrol
    {
        return $this->patrol;
    }

    public function getClientUuid(): Uuid
    {
        return $this->clientUuid;
    }

    public function getLaunchPoint(): ?LaunchPoint
    {
        return $this->launchPoint;
    }

    public function setLaunchPoint(?LaunchPoint $launchPoint): static
    {
        $this->launchPoint = $launchPoint;

        return $this;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    /** Still airborne as far as the module knows. */
    public function isAirborne(): bool
    {
        return null === $this->endedAt;
    }
}
