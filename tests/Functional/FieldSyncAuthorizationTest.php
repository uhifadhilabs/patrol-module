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

namespace UhifadhiLabs\Patrol\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use UhifadhiLabs\Patrol\Entity\Patrol;

/**
 * Every sync endpoint WRITES field records, so every one demands the same
 * permission the module's two recording screens do — `patrols.record`. The
 * bundle only declares it; the host decides who holds it (here, a fixture
 * voter standing in for the host's).
 *
 * The distinction being protected is the one the app acts on: a signed-in user
 * who may not record gets 403 and stops. It must never be 200-with-nothing-saved,
 * which would leave a ranger believing a patrol had synced.
 */
final class FieldSyncAuthorizationTest extends FieldSyncTestCase
{
    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function writeEndpoints(): iterable
    {
        yield 'create patrol' => ['/api/patrols', ['clientUuid' => '8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111']];
        yield 'append track' => ['/api/patrols/8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111/track', ['batchUuid' => 'x:track:0']];
        yield 'append observations' => ['/api/patrols/8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111/observations', ['observations' => []]];
        yield 'append flights' => ['/api/patrols/8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111/flights', ['flights' => []]];
        yield 'complete' => ['/api/patrols/8f1f4e02-6b1a-4f34-8f8f-1a0f19a1c111/complete', []];
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('writeEndpoints')]
    public function aUserWithoutTheRecordPermissionIsRefused(string $uri, array $body): void
    {
        $this->actingAs($this->bystander);

        $this->postJson($uri, $body);

        self::assertResponseStatusCodeSame(403);
        $error = $this->payload();
        self::assertSame('forbidden', $error['code']);
        self::assertFalse($error['retryable'], 'A 403 must never be retried in a loop.');
    }

    #[Test]
    public function aRefusedWriteStoresNothing(): void
    {
        $this->actingAs($this->bystander);

        $this->createPatrol();

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            [],
            $this->em->getRepository(Patrol::class)->findAll(),
            'A forbidden request created a patrol anyway.',
        );
    }

    #[Test]
    public function thePermissionCheckHappensBeforeThePatrolIsEvenLookedUp(): void
    {
        $this->actingAs($this->bystander);

        // An unknown patrol AND no permission: the answer must be 403, not 404.
        // Otherwise the endpoint tells a caller who may not record anything
        // which patrol uuids exist.
        $this->postJson('/api/patrols/1e1f4e02-6b1a-4f34-8f8f-1a0f19a1cfff/complete', []);

        self::assertResponseStatusCodeSame(403);
    }
}
