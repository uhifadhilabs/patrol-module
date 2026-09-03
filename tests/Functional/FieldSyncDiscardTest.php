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

namespace Uhifadhi\Patrol\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolEventKindEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;

/**
 * Discarding a patrol, over the wire — API-CONTRACT.md §4, §9 and §9A.
 *
 * Every assertion is about the LITERAL contract: the field names, the status
 * codes, the error shape, and the two promises the whole feature rests on — that
 * a discard always carries a reason, and that a discarded patrol is accepted
 * exactly like any other however small it is.
 */
final class FieldSyncDiscardTest extends FieldSyncTestCase
{
    /**
     * THE SMALL ONE, tested literally.
     *
     * Forty seconds and three fixes: a ranger who started a patrol by mistake and
     * noticed within a minute. There is no floor of any kind on a patrol's size
     * or duration, and there must not be — a phone holding a record the server
     * will not take is a phone that can never safely delete it.
     */
    #[Test]
    public function aFortySecondThreePointDiscardedPatrolIsAcceptedLikeAnyOther(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol([
            'startedAt' => '2026-08-23T06:44:12Z',
            'endedAt' => '2026-08-23T06:44:52Z',
            'status' => 'discarded',
            'discardReason' => 'Started by mistake',
        ]);

        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();
        self::assertSame('discarded', $created['status']);
        self::assertFalse($created['duplicate']);
        // The server still assigns a reference: a discarded patrol is a record,
        // and the app shows the number it was given.
        self::assertIsString($created['reference']);
        self::assertMatchesRegularExpression('/^P-\d{4}$/', $created['reference']);

        // Its three fixes are taken exactly as a full day's would be.
        $this->postJson("/api/patrols/{$patrolUuid}/track", [
            'batchUuid' => "{$patrolUuid}:track:0",
            'points' => [
                ['lat' => -3.2014, 'lon' => 35.4623, 'recordedAt' => '2026-08-23T06:44:17Z', 'accuracyM' => 4.0],
                ['lat' => -3.2015, 'lon' => 35.4624, 'recordedAt' => '2026-08-23T06:44:37Z', 'accuracyM' => 4.0],
                ['lat' => -3.2016, 'lon' => 35.4625, 'recordedAt' => '2026-08-23T06:44:50Z', 'accuracyM' => 4.0],
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['accepted']);

        $patrol = $this->reloadPatrol($patrolUuid);
        self::assertSame(PatrolStatusEnum::Discarded, $patrol->getStatus());
        self::assertSame('Started by mistake', $patrol->getDiscardReason());
        self::assertSame(3, $patrol->getPointCount());
        self::assertSame(40, $patrol->getEndedAt()?->getTimestamp() - $patrol->getStartedAt()?->getTimestamp());
    }

    /**
     * The one rule the server is stricter about than "take what the phone gives
     * you". A reasonless discard is a hole in the register exactly where somebody
     * will look.
     */
    #[Test]
    public function aDiscardWithoutAReasonIsRefusedInTheContractsErrorShape(): void
    {
        $this->actingAs($this->recorder);

        $this->createPatrol(['status' => 'discarded']);

        self::assertResponseStatusCodeSame(422);
        $error = $this->payload();
        self::assertSame('discard_reason_required', $error['code']);
        self::assertFalse($error['retryable'], 'The same body will be just as reasonless in two minutes.');
        self::assertIsArray($error['details']);
        self::assertSame('discardReason', $error['details']['field']);

        // Nothing was stored: a refused patrol is not half-created.
        self::assertNull($this->patrolOrNull('8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111'));
    }

    /** A blank string is not a reason either. */
    #[Test]
    public function aBlankReasonIsRefusedTheSameWay(): void
    {
        $this->actingAs($this->recorder);

        $this->createPatrol(['status' => 'discarded', 'discardReason' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('discard_reason_required', $this->payload()['code']);
    }

    /**
     * `complete` is the ordinary way a patrol closes, and a discard closes it
     * too — with NOTHING verified, because a discarded patrol is published
     * nowhere and demanding its missing photographs first would strand it as
     * `recording` for ever.
     */
    #[Test]
    public function completeCanCloseAPatrolAsDiscardedWithoutVerifyingItsParts(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol();

        // An observation promising a photograph that never arrives. An ordinary
        // complete would be refused for exactly this.
        $this->postJson("/api/patrols/{$patrolUuid}/observations", [
            'observations' => [[
                'clientUuid' => 'b23f0e77-0000-4000-8000-000000000001',
                'category' => 'maintenance',
                'loggedAt' => '2026-08-23T08:31:02Z',
                'photoCount' => 1,
            ]],
        ]);
        self::assertResponseIsSuccessful();

        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('incomplete_patrol', $this->payload()['code']);

        // The same call, discarding instead: accepted.
        $this->postJson("/api/patrols/{$patrolUuid}/complete", [
            'status' => 'discarded',
            'discardReason' => 'Testing',
        ]);

        self::assertResponseIsSuccessful();
        $closed = $this->payload();
        self::assertSame('discarded', $closed['status']);
        self::assertFalse($closed['duplicate']);
        self::assertSame(PatrolStatusEnum::Discarded, $this->reloadPatrol($patrolUuid)->getStatus());

        // And again, because the phone retries: success, changed nothing.
        $this->postJson("/api/patrols/{$patrolUuid}/complete", [
            'status' => 'discarded',
            'discardReason' => 'Testing',
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['duplicate']);
    }

    /**
     * A `complete` queued before the ranger discarded the patrol, arriving after.
     * The discard is the later decision and must not be undone by a stale part.
     */
    #[Test]
    public function aQueuedCompleteDoesNotUndoADiscard(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol(['status' => 'discarded', 'discardReason' => 'Started by mistake']);
        self::assertResponseStatusCodeSame(201);

        $this->postJson("/api/patrols/{$patrolUuid}/complete", []);

        self::assertResponseIsSuccessful();
        self::assertSame('discarded', $this->payload()['status']);
        self::assertTrue($this->payload()['duplicate']);
        self::assertSame(PatrolStatusEnum::Discarded, $this->reloadPatrol($patrolUuid)->getStatus());
    }

    /** `complete` is reached by calling /complete, never by naming it in a body. */
    #[Test]
    public function aStatusTheContractDoesNotCarryIsRefused(): void
    {
        $this->actingAs($this->recorder);

        $this->createPatrol(['status' => 'complete']);

        self::assertResponseStatusCodeSame(422);
        $error = $this->payload();
        self::assertSame('unsupported_status', $error['code']);
        self::assertIsArray($error['details']);
        self::assertSame('complete', $error['details']['value']);
    }

    /**
     * §9A, the whole of it: three kinds, each appending an immutable event AND
     * folding itself onto the patrol row.
     */
    #[Test]
    public function eventsAreAppendedAndAppliedToThePatrolRow(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol();

        $this->postJson("/api/patrols/{$patrolUuid}/events", [
            'events' => [
                [
                    'clientUuid' => 'aa000000-0000-4000-8000-000000000001',
                    'kind' => 'renamed',
                    'at' => '2026-08-23T06:58:00Z',
                    'payload' => ['name' => 'North gate'],
                ],
                [
                    'clientUuid' => 'aa000000-0000-4000-8000-000000000002',
                    'kind' => 'type_changed',
                    'at' => '2026-08-23T07:04:00Z',
                    'payload' => ['type' => 'boat'],
                ],
                [
                    'clientUuid' => 'aa000000-0000-4000-8000-000000000003',
                    'kind' => 'discarded',
                    'at' => '2026-08-23T07:31:00Z',
                    'payload' => ['reason' => 'Started by mistake'],
                ],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $ack = $this->payload();
        self::assertTrue($ack['accepted']);
        self::assertSame([
            'aa000000-0000-4000-8000-000000000001',
            'aa000000-0000-4000-8000-000000000002',
            'aa000000-0000-4000-8000-000000000003',
        ], $ack['acceptedUuids']);
        self::assertFalse($ack['duplicate']);

        $patrol = $this->reloadPatrol($patrolUuid);

        // The row is the current truth.
        self::assertSame('North gate', $patrol->getName());
        self::assertSame('boat', $patrol->getType());
        self::assertSame(PatrolStatusEnum::Discarded, $patrol->getStatus());
        self::assertSame('Started by mistake', $patrol->getDiscardReason());

        // The events are the story — oldest first, actor attributed, and each
        // carrying what the value WAS so the history can read "walk → boat".
        $events = $patrol->getEvents()->toArray();
        self::assertCount(3, $events);
        self::assertSame(
            [PatrolEventKindEnum::Renamed, PatrolEventKindEnum::TypeChanged, PatrolEventKindEnum::Discarded],
            array_map(static fn ($event) => $event->getKind(), array_values($events)),
        );

        [$renamed, $typeChanged, $discarded] = array_values($events);
        self::assertSame($this->recorder->getId(), $renamed->getActor()?->getId());
        self::assertSame('2026-08-23T06:58:00+00:00', $renamed->getAt()->format(\DateTimeInterface::ATOM), 'The ranger\'s moment, not our receive time.');
        // The patrol had no name before, so there is no arrow to draw.
        self::assertNull($renamed->getPrevious());
        self::assertSame('North gate', $renamed->getSubject());

        self::assertSame('walk', $typeChanged->getPrevious());
        self::assertSame('boat', $typeChanged->getSubject());

        self::assertSame('Started by mistake', $discarded->getSubject());
        self::assertNull($discarded->getPrevious(), 'A discard replaces nothing, so it has no "from".');

        // The discard EVENT is what dates the retention window.
        self::assertSame('2026-08-23T07:31:00+00:00', $patrol->discardedAt()?->format(\DateTimeInterface::ATOM));
    }

    /** The promise the phone's retry loop rests on: a re-sent part costs nothing. */
    #[Test]
    public function aResentEventPartIsAcknowledgedAndAppliedOnlyOnce(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol();
        $part = [
            'events' => [[
                'clientUuid' => 'aa000000-0000-4000-8000-000000000010',
                'kind' => 'renamed',
                'at' => '2026-08-23T06:58:00Z',
                'payload' => ['name' => 'North gate'],
            ]],
        ];

        $this->postJson("/api/patrols/{$patrolUuid}/events", $part);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['duplicate']);

        // The ranger renames it again in the meantime — the row moves on.
        $this->postJson("/api/patrols/{$patrolUuid}/events", [
            'events' => [[
                'clientUuid' => 'aa000000-0000-4000-8000-000000000011',
                'kind' => 'renamed',
                'at' => '2026-08-23T07:10:00Z',
                'payload' => ['name' => 'River loop'],
            ]],
        ]);
        self::assertResponseIsSuccessful();

        // …and the FIRST part arrives again, from a queue that outlived it.
        $this->postJson("/api/patrols/{$patrolUuid}/events", $part);

        self::assertResponseIsSuccessful();
        $ack = $this->payload();
        self::assertTrue($ack['duplicate']);
        self::assertSame(['aa000000-0000-4000-8000-000000000010'], $ack['acceptedUuids']);

        $patrol = $this->reloadPatrol($patrolUuid);
        self::assertCount(2, $patrol->getEvents(), 'The re-send added no third row.');
        self::assertSame('River loop', $patrol->getName(), 'Nor did it re-apply the older name over the newer one.');
    }

    /** An event kind with no code to apply it is refused, never stored. */
    #[Test]
    public function anUnknownEventKindIsRefused(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol();

        $this->postJson("/api/patrols/{$patrolUuid}/events", [
            'events' => [[
                'clientUuid' => 'aa000000-0000-4000-8000-000000000020',
                'kind' => 'exonerated',
                'at' => '2026-08-23T06:58:00Z',
                'payload' => [],
            ]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported_event_kind', $this->payload()['code']);
        self::assertCount(0, $this->reloadPatrol($patrolUuid)->getEvents());
    }

    /** A `discarded` event with no reason is refused, exactly as §4 and §9 are. */
    #[Test]
    public function aDiscardedEventWithoutAReasonIsRefused(): void
    {
        $this->actingAs($this->recorder);

        $patrolUuid = $this->createPatrol();

        $this->postJson("/api/patrols/{$patrolUuid}/events", [
            'events' => [[
                'clientUuid' => 'aa000000-0000-4000-8000-000000000030',
                'kind' => 'discarded',
                'at' => '2026-08-23T07:31:00Z',
                'payload' => [],
            ]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('discard_reason_required', $this->payload()['code']);
        self::assertSame(PatrolStatusEnum::Recording, $this->reloadPatrol($patrolUuid)->getStatus());
    }

    /** The events endpoint demands `patrols.record`, like every other write. */
    #[Test]
    public function eventsRequireThePermissionEveryOtherWriteRequires(): void
    {
        $this->actingAs($this->recorder);
        $patrolUuid = $this->createPatrol();

        $this->actingAs($this->bystander);
        $this->postJson("/api/patrols/{$patrolUuid}/events", [
            'events' => [[
                'clientUuid' => 'aa000000-0000-4000-8000-000000000040',
                'kind' => 'renamed',
                'at' => '2026-08-23T06:58:00Z',
                'payload' => ['name' => 'North gate'],
            ]],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('forbidden', $this->payload()['code']);
    }

    private function reloadPatrol(string $clientUuid): Patrol
    {
        $this->em->clear();

        $patrol = $this->em->getRepository(Patrol::class)
            ->findOneBy(['clientUuid' => Uuid::fromString($clientUuid)]);
        self::assertInstanceOf(Patrol::class, $patrol);

        return $patrol;
    }

    private function patrolOrNull(string $clientUuid): ?Patrol
    {
        $this->em->clear();

        return $this->em->getRepository(Patrol::class)
            ->findOneBy(['clientUuid' => Uuid::fromString($clientUuid)]);
    }
}
