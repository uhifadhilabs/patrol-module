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

namespace Uhifadhi\Patrol\Service\Api;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\User;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Api\Payload;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolEvent;
use Uhifadhi\Patrol\Enum\PatrolEventKindEnum;
use Uhifadhi\Patrol\Repository\PatrolEventRepository;

/**
 * `POST /api/patrols/{uuid}/events` — API-CONTRACT.md §9A.
 *
 * What the ranger DID to a patrol after recording it: renamed it, corrected its
 * type, threw it away. The phone queues these like every other part and re-sends
 * the whole array if any of it failed, so each event is matched individually by
 * its own clientUuid — exactly as observations are (§6).
 *
 * ## The event is the story; the row is the current truth
 *
 * Every accepted event does TWO things: it appends an immutable
 * {@see PatrolEvent}, and it applies itself to the {@see Patrol} row. Neither
 * half is optional and neither is redundant.
 *
 * Keeping only the row would answer "what is this patrol called" and lose "who
 * changed it, when, and from what" — which is the entire content of the detail
 * screen's history card, and the only thing that can explain a patrol that reads
 * differently today than in last month's report.
 *
 * Keeping only the events would mean every screen in the module replaying a
 * patrol's history to learn its name, and a log table joining and folding a
 * second table to render one column. The row is the fold, computed once, at
 * write time.
 *
 * ## Order
 *
 * Events are applied in the order the ARRAY gives them, which is the order the
 * phone recorded them. Two renames in one part therefore leave the later name on
 * the row, and both in the history — the same answer a person reading the card
 * would give.
 */
final class PatrolEventService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PatrolEventRepository $events,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: list<string>, 1: bool} [acceptedUuids, everyOneWasAlreadyHeld]
     *
     * @throws PatrolApiException
     */
    public function append(Patrol $patrol, array $data, User $recorder): array
    {
        if (!$patrol->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $patrol->getClientUuid()?->toRfc4122());
        }

        $rows = Payload::rows($data, 'events');
        $accepted = [];
        $created = 0;

        foreach ($rows as $row) {
            $clientUuid = Payload::uuid($row, 'clientUuid');
            $accepted[] = $clientUuid->toRfc4122();

            // Already held: acknowledged, and applied NOWHERE a second time.
            // Re-applying would be harmless for a rename and wrong for anything
            // that ever accumulates, and "an accepted event is applied exactly
            // once" is the rule that stays true as kinds are added.
            if ($this->events->findOneByClientUuid($clientUuid) instanceof PatrolEvent) {
                continue;
            }

            $kindValue = Payload::requiredString($row, 'kind');
            $kind = PatrolEventKindEnum::tryFrom($kindValue)
                ?? throw PatrolApiException::unsupportedEventKind($kindValue, $clientUuid->toRfc4122());

            $event = new PatrolEvent($patrol, $clientUuid, $kind, Payload::requiredTimestamp($row, 'at'))
                ->setActor($recorder)
                ->setPayload($this->withPrevious($patrol, $kind, $this->payloadOf($row)));

            // Order matters: the payload above was sealed while the row still
            // held its OLD value, and only now is the new one written.
            $this->apply($patrol, $event);

            $this->entityManager->persist($event);
            ++$created;
        }

        $this->entityManager->flush();

        // "duplicate" describes the PART: true only when the whole re-sent part
        // was already held, which is what the phone is asking about.
        return [$accepted, [] !== $rows && 0 === $created];
    }

    /**
     * Fold one event onto the patrol row.
     *
     * A `match` with no default, on purpose: adding a kind to
     * {@see PatrolEventKindEnum} without deciding what it does to the record is
     * then a compile-time-visible error rather than an event that is stored,
     * acknowledged, and quietly changes nothing.
     *
     * An event whose payload is missing its one value ({@see PatrolEvent::getSubject()}
     * returns null) is STORED and applies nothing. It is still true that the
     * ranger did something; the module simply has no new value to write, and
     * blanking the name because a payload arrived empty would destroy a good
     * record on the strength of a bad message.
     *
     * @throws PatrolApiException
     */
    private function apply(Patrol $patrol, PatrolEvent $event): void
    {
        $subject = $event->getSubject();

        match ($event->getKind()) {
            PatrolEventKindEnum::Renamed => null === $subject ? null : $patrol->setName($subject),
            PatrolEventKindEnum::TypeChanged => null === $subject ? null : $patrol->setType($subject),
            // The one kind whose value is not optional. A discard with no reason
            // is refused for the same reason it is refused on §4 and §9 — see
            // PatrolApiException::discardReasonRequired().
            PatrolEventKindEnum::Discarded => $patrol->discard(
                $subject ?? throw PatrolApiException::discardReasonRequired((string) $patrol->getClientUuid()?->toRfc4122()),
            ),
        };
    }

    /**
     * The payload with `from` filled in — what the value WAS before this event
     * changed it.
     *
     * The history reads "VEHICLE → FOOT", not "FOOT": a change with only its
     * outcome recorded is half a fact, and the half it drops is the one a
     * reviewer is asking about. The phone does not send it (the contract carries
     * only the new value), so the server supplies what it can see — which it can
     * see exactly once, in the instant before {@see self::apply()} overwrites it.
     *
     * An app that DOES send `from` keeps its own: the payload is evidence of what
     * was claimed, and a phone that has been offline for a week knows a history
     * this server has not caught up with yet. Where neither has anything to say
     * — a first rename of a patrol nobody named — the key is left out rather than
     * written as an empty string, and the card renders the outcome alone.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withPrevious(Patrol $patrol, PatrolEventKindEnum $kind, array $payload): array
    {
        if (isset($payload['from'])) {
            return $payload;
        }

        $previous = match ($kind) {
            PatrolEventKindEnum::Renamed => $patrol->getName(),
            PatrolEventKindEnum::TypeChanged => $patrol->getType(),
            // A discard replaces nothing; "from" would be a column that was
            // always empty, and an arrow drawn from it would invent a change.
            PatrolEventKindEnum::Discarded => null,
        };

        if (null === $previous) {
            return $payload;
        }

        $payload['from'] = $previous;

        return $payload;
    }

    /**
     * The event's payload, as the phone sent it.
     *
     * Kept verbatim rather than reduced to the one key this module reads: the
     * payload is evidence of what was claimed at the time, and a later kind's
     * extra fields must survive a server that predates them.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function payloadOf(array $row): array
    {
        $payload = $row['payload'] ?? null;

        if (!\is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> */
        return $payload;
    }
}
