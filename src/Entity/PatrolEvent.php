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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\User;
use Uhifadhi\Patrol\Enum\PatrolEventKindEnum;
use Uhifadhi\Patrol\Repository\PatrolEventRepository;

/**
 * One thing that happened to a patrol — API-CONTRACT.md §9A.
 *
 * APPEND-ONLY. Nothing here is ever updated or deleted while its patrol lives:
 * the event is the STORY and the patrol row is the CURRENT TRUTH, and the two
 * are written together. A rename event says "on Tuesday she called it River
 * loop"; `Patrol::$name` says what it is called now. Overwriting the event when
 * she renames it again would leave the row with a name and no account of how it
 * got one, which is precisely what the detail screen's history card is for.
 *
 * There is deliberately NO updatedAt (and so no {@see Trait\TimestampableTrait}):
 * an immutable row has no honest one. `at` is the moment the RANGER acted, per
 * the phone and trusted verbatim (§1); `receivedAt` is when this server heard
 * about it. They differ by however long the handset was out of signal, and
 * collapsing them would rewrite field history to match a network.
 */
#[ORM\Entity(repositoryClass: PatrolEventRepository::class)]
#[ORM\Table(name: 'patrol_event')]
class PatrolEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Patrol::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Patrol $patrol;

    /**
     * The phone's own id for this event — the idempotency key (§1, §9A).
     *
     * Unique and NOT NULL: every event this module stores came from a device
     * that will retry, and the promise the whole sync contract rests on is that
     * a retry adds nothing. The web module's own actions are not events — they
     * are readable from the columns they wrote (a hold from `heldAt`/`heldBy`),
     * so there is no second, keyless producer to make room for here.
     */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $clientUuid;

    #[ORM\Column(length: 40, enumType: PatrolEventKindEnum::class)]
    private PatrolEventKindEnum $kind;

    /** When the RANGER did it, per the phone — never our receive time (§1). */
    #[ORM\Column]
    private \DateTimeImmutable $at;

    /**
     * Who did it, where that is derivable — the authenticated account the event
     * arrived under. Null rather than a guess: the contract sends no actor
     * field, so this is the recorder the sync call was made by, and a patrol
     * imported or migrated without one says so plainly.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /**
     * What the event carried, as the phone sent it.
     *
     * JSON rather than a column per kind: the kinds do not share a shape, and
     * the payload is evidence of what was claimed at the time — it must survive
     * verbatim even after the code that applies it has moved on.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /** When this server heard about it. See the class docblock on why both. */
    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    public function __construct(Patrol $patrol, Uuid $clientUuid, PatrolEventKindEnum $kind, \DateTimeImmutable $at)
    {
        $this->patrol = $patrol;
        $this->clientUuid = $clientUuid;
        $this->kind = $kind;
        $this->at = $at;
        $this->receivedAt = new \DateTimeImmutable();
        $patrol->addEvent($this);
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

    public function getKind(): PatrolEventKindEnum
    {
        return $this->kind;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    /**
     * The one value this kind is about ({@see PatrolEventKindEnum::payloadKey()}),
     * or null where the phone sent none — a rename with no name, say, which is
     * stored as it arrived rather than repaired.
     */
    public function getSubject(): ?string
    {
        return self::text($this->payload[$this->kind->payloadKey()] ?? null);
    }

    /**
     * What the value WAS before this event changed it, where that is known —
     * the left-hand side of the history card's "VEHICLE → FOOT".
     *
     * Filled in by the sync service at write time from the row it was about to
     * overwrite, unless the phone sent its own; see
     * {@see \Uhifadhi\Patrol\Service\Api\PatrolEventService}.
     */
    public function getPrevious(): ?string
    {
        return self::text($this->payload['from'] ?? null);
    }

    /** A payload value as displayable text, or null for anything that is not. */
    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
