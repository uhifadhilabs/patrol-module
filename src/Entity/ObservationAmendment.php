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

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Enum\ObservationAmendmentKindEnum;
use UhifadhiLabs\Patrol\Repository\ObservationAmendmentRepository;

/**
 * ONE CORRECTION APPENDED TO AN OBSERVATION — never a rewrite of it.
 *
 * A SYNCED OBSERVATION IS CLOSED. What the ranger typed, where they typed it, is
 * the field record and it is never edited. A correction is a NEW row underneath
 * it — who, when, what was corrected, in their own words — and the original
 * stays visible above.
 *
 * WHY, in one line: the observation is the provenance of any incident filed from
 * it, and an incident can be read out in a hearing. A note somebody could
 * quietly change afterwards proves nothing there.
 *
 * APPEND-ONLY, ENFORCED BY THE TYPE. There is no setter on this class beyond the
 * constructor and the two `with*` assemblers, and that is the design, not an
 * oversight: nothing here is ever edited or removed, and a wrong amendment is
 * corrected the way everything else is — by another amendment. It is exactly
 * that property that makes the record worth anything; a row somebody could
 * quietly rewrite proves nothing. Same shape and same reasoning as the incidents
 * module's timeline ({@see \UhifadhiLabs\Incident\Entity\IncidentEvent}), stated
 * again here rather than shared, because the two modules must be installable
 * independently.
 *
 * DELIBERATELY WITHOUT the timestampable trait: an immutable row has no honest
 * updatedAt, and {@see $writtenAt} is the only time that matters.
 *
 * NOT BACK-DATABLE, and again by construction: the moment is taken from the
 * clock inside the constructor, so there is no parameter for a caller to lie in.
 * "Never back-dated" is a rule the design states plainly (PL·09), and a rule
 * worth stating is worth making unreachable rather than merely unenforced.
 */
#[ORM\Entity(repositoryClass: ObservationAmendmentRepository::class)]
#[ORM\Table(name: 'patrol_observation_amendment')]
// By FIELD, not by column: the host owns the naming strategy, and a host that
// underscores its columns must still get the index.
#[ORM\Index(name: 'idx_patrol_amendment_observation', fields: ['observation', 'writtenAt'])]
class ObservationAmendment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Observation::class, inversedBy: 'amendments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Observation $observation;

    /** What was corrected, in the words the form offered. */
    #[ORM\Column(length: 40, enumType: ObservationAmendmentKindEnum::class)]
    private ObservationAmendmentKindEnum $kind;

    /** What is right, in the amender's OWN words. Never a new value for a column. */
    #[ORM\Column(type: 'text')]
    private string $body;

    /**
     * The value this correction supersedes, quoted — the design's "as it was
     * recorded" block.
     *
     * A COPY, taken at the moment of writing, and never read back out of the
     * original: the original is still there and still authoritative, and this is
     * only so a reader can see the two side by side without scrolling. Null
     * where the amendment adds rather than corrects (a photograph, a note about
     * something the record never claimed).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $supersededValue = null;

    /**
     * WHEN IT WAS WRITTEN — the server's own clock, at the moment of insert.
     *
     * Not the handset's, unlike almost every other time in this module: an
     * amendment is made on the web, by somebody sitting at a desk, and the only
     * honest moment is the one this server observed.
     */
    #[ORM\Column]
    private \DateTimeImmutable $writtenAt;

    /**
     * WHO SIGNED IT. Never null in practice — PL·09's "go in unsigned: never" is
     * enforced at the door — but nullable in the column so that deleting a user
     * can never take an evidence trail with it.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /**
     * The name the signature is printed under, copied at the time of writing and
     * kept even if the user row later goes. A trail that lost its names when
     * somebody left the service would be a trail nobody could read back.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $authorName = null;

    /**
     * A photograph attached to THIS amendment — not to the observation's field
     * photographs, which are a different set with different provenance (the
     * handset took them, in the field, at the time). The design says so in as
     * many words: "attached to this amendment, not to the two field photographs
     * above".
     */
    #[ORM\OneToOne(targetEntity: ObservationPhoto::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ObservationPhoto $photo = null;

    public function __construct(
        Observation $observation,
        ObservationAmendmentKindEnum $kind,
        string $body,
    ) {
        $this->uuid = Uuid::v7();
        $this->observation = $observation;
        $this->kind = $kind;
        $this->body = $body;
        $this->writtenAt = new \DateTimeImmutable();
        $observation->addAmendment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getObservation(): Observation
    {
        return $this->observation;
    }

    public function getKind(): ObservationAmendmentKindEnum
    {
        return $this->kind;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSupersededValue(): ?string
    {
        return $this->supersededValue;
    }

    /**
     * Set ONCE, while the amendment is being assembled, before the flush that
     * stores it. Not a mutation of history: an amendment is built and then
     * appended, and nothing calls this afterwards.
     */
    public function withSupersededValue(?string $supersededValue): static
    {
        $this->supersededValue = $supersededValue;

        return $this;
    }

    public function getWrittenAt(): \DateTimeImmutable
    {
        return $this->writtenAt;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    /** Set once, at assembly time — see {@see withSupersededValue()}. */
    public function withAuthor(?User $author, ?string $authorName = null): static
    {
        $this->author = $author;
        $this->authorName = $authorName ?? $author?->getFullName();

        return $this;
    }

    public function getPhoto(): ?ObservationPhoto
    {
        return $this->photo;
    }

    /** Set once, at assembly time — see {@see withSupersededValue()}. */
    public function withPhoto(?ObservationPhoto $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    /**
     * The name to print, falling back to the honest word rather than to a blank
     * or to an invented one.
     */
    public function signature(): string
    {
        return $this->authorName ?? $this->author?->getFullName() ?? 'unattributed';
    }
}
