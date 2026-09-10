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

use Symfony\Component\Routing\RouterInterface;

/**
 * THE WIRE IS FROZEN — the seven addresses, the four documents and the statuses,
 * held as literals so nothing can change them by accident.
 *
 * There is a field application already built and already on handsets. It reads
 * these key names, these status codes and these error codes; a serializer
 * setting, a naming convention or a platform listener added for some other
 * feature must not be able to rename `acceptedUuids` on a Tuesday, and the
 * difference between 201 and 200 is a distinction the app acts on rather than
 * an incidental of how the endpoint was written.
 *
 * IT IS A PIN, NOT A SPECIFICATION, and that is the honest description: it was
 * written against behaviour that already existed and passed the moment it was
 * saved. There is no red-first step to claim for it. What it buys is the next
 * change — the one that would otherwise alter the wire while every behavioural
 * test stayed green, because each of those asserts what an endpoint DID rather
 * than what it SAID.
 *
 * The behaviour behind each of these lives in its own test: FieldSyncFlowTest,
 * FieldSyncDiscardTest, FieldSyncDroneTest, FieldSyncPhotoPositionTest and
 * FieldSyncAuthorizationTest. This one asserts only the shapes.
 */
final class FieldSyncWireContractTest extends FieldSyncTestCase
{
    /**
     * Every address the handset knows, with its method. A route added here is a
     * new endpoint and a route missing is one the app can no longer reach, so
     * the set is compared whole rather than searched.
     */
    public function testTheSevenAddressesAreExactlyTheseWithTheseMethods(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $found = [];
        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/patrols') && !str_starts_with($path, '/api/observations')) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $found[] = $method.' '.$path;
            }
        }

        sort($found);

        self::assertSame([
            'POST /api/observations/{uuid}/photos',
            'POST /api/patrols',
            'POST /api/patrols/{uuid}/complete',
            'POST /api/patrols/{uuid}/events',
            'POST /api/patrols/{uuid}/flights',
            'POST /api/patrols/{uuid}/observations',
            'POST /api/patrols/{uuid}/track',
        ], $found);
    }

    /**
     * A create answers 201 and a re-send of the same clientUuid answers 200 with
     * the identical body plus `duplicate: true`. Both carry the reference the
     * SERVER assigned — the app prints "P-????" until it has one and never
     * invents one.
     */
    public function testThePatrolDocumentIsFourKeysAnd201ThenThe200OfAReSend(): void
    {
        $this->actingAs($this->recorder);

        $this->createPatrol();

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = $this->payload();
        self::assertSame(['uuid', 'reference', 'status', 'duplicate'], array_keys($created));
        self::assertFalse($created['duplicate']);
        self::assertIsString($created['reference']);
        self::assertNotSame('', $created['reference']);

        $this->createPatrol();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $resent = $this->payload();
        self::assertSame(['uuid', 'reference', 'status', 'duplicate'], array_keys($resent));
        self::assertTrue($resent['duplicate']);
        self::assertSame($created['uuid'], $resent['uuid']);
        self::assertSame($created['reference'], $resent['reference']);
        self::assertSame($created['status'], $resent['status']);
    }

    /**
     * Every batch part — track, observations, flights, events — answers the same
     * three keys and 200. `acceptedUuids` is a LIST, so it encodes as a JSON
     * array even when it holds one element.
     */
    public function testEveryBatchPartAnswersTheSameThreeKeys(): void
    {
        $this->actingAs($this->recorder);
        $uuid = $this->createPatrol();

        $this->postJson('/api/patrols/'.$uuid.'/track', [
            'batchUuid' => $uuid.':track:0',
            'points' => [
                ['lat' => -3.2014, 'lon' => 35.4623, 'recordedAt' => '2026-08-23T06:44:17Z'],
                ['lat' => -3.2020, 'lon' => 35.4630, 'recordedAt' => '2026-08-23T06:45:17Z'],
            ],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $ack = $this->payload();
        self::assertSame(['accepted', 'acceptedUuids', 'duplicate'], array_keys($ack));
        self::assertTrue($ack['accepted']);
        self::assertIsArray($ack['acceptedUuids']);
        self::assertSame(array_keys($ack['acceptedUuids']), range(0, \count($ack['acceptedUuids']) - 1));
    }

    /** `complete` makes nothing new, so it is always 200 and `duplicate` alone says what happened. */
    public function testTheCompleteDocumentIsFourKeysAndAlways200(): void
    {
        $this->actingAs($this->recorder);
        $uuid = $this->createPatrol();

        $this->postJson('/api/patrols/'.$uuid.'/complete', []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['uuid', 'reference', 'status', 'duplicate'], array_keys($this->payload()));
    }

    /**
     * THE REFUSAL, AND THE CODE THAT SURVIVES IT.
     *
     * `details` is an OBJECT even when empty, so a client's parser meets one
     * shape rather than an object on some failures and an array on others. And
     * `code` is the CONTRACT's word — the platform gives every /api failure the
     * same four keys by replacing the body of any 4xx, and a document already in
     * that shape must reach the handset with its own code rather than the status
     * word.
     */
    public function testTheErrorDocumentIsFourKeysAndKeepsTheContractsOwnCode(): void
    {
        $this->actingAs($this->recorder);
        $uuid = $this->createPatrol();

        $this->postJson('/api/patrols/'.$uuid.'/complete', ['status' => 'discarded']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $error = $this->payload();
        self::assertSame(['code', 'message', 'retryable', 'details'], array_keys($error));
        self::assertSame('discard_reason_required', $error['code']);
        self::assertFalse($error['retryable']);
        self::assertIsArray($error['details']);
        self::assertStringContainsString(
            '"details":{',
            (string) $this->client->getResponse()->getContent(),
            'details must encode as an object, so a parser meets one shape on every failure.',
        );
    }

    /**
     * A request with no credential is 401 and not 403, because a client shows a
     * person different things for the two — "sign in again" against "you may not
     * do that".
     */
    public function testNoCredentialIs401(): void
    {
        $this->postJson('/api/patrols', []);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
