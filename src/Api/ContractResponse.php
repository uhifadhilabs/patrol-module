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

namespace UhifadhiLabs\Patrol\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use UhifadhiLabs\Patrol\Entity\Patrol;

/**
 * Writes the three response shapes the field app knows — API-CONTRACT.md §4–§10.
 *
 * Hand-built JSON, on purpose. These key names are an external contract with a
 * client that is already built and already deployed to handsets; a serializer
 * naming convention or a normalizer added for some other feature must not be
 * able to rename `acceptedUuids` on a Tuesday. api-platform passes a Response
 * straight through its serialize and respond processors, so returning one from
 * a state processor is the supported way to mean exactly this.
 */
final class ContractResponse
{
    /**
     * The acknowledgement every batch part returns: what was taken, and whether
     * this was a re-send.
     *
     * @param list<string> $acceptedUuids
     */
    public static function ack(array $acceptedUuids, bool $duplicate): JsonResponse
    {
        return new JsonResponse([
            'accepted' => true,
            'acceptedUuids' => $acceptedUuids,
            'duplicate' => $duplicate,
        ]);
    }

    /**
     * The patrol acknowledgement (§4, §9). 201 the first time, 200 for a
     * re-send — a distinction the contract makes and the app reads, so it is
     * carried by the status and by `duplicate` together, never inferred.
     */
    public static function patrol(Patrol $patrol, bool $duplicate): JsonResponse
    {
        return new JsonResponse([
            'uuid' => (string) $patrol->getClientUuid()?->toRfc4122(),
            // Assigned HERE, never by the phone: the app shows "P-????" until
            // the server has given it a number, and never invents one.
            'reference' => $patrol->getRef(),
            'status' => $patrol->getStatus()->value,
            'duplicate' => $duplicate,
        ], $duplicate ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * The `complete` acknowledgement (§9). Always 200 — unlike a create, this
     * makes nothing new, so there is no 201 to draw; `duplicate` alone says
     * whether the patrol was already complete when the call arrived.
     */
    public static function completed(Patrol $patrol, bool $alreadyComplete): JsonResponse
    {
        return new JsonResponse([
            'uuid' => (string) $patrol->getClientUuid()?->toRfc4122(),
            'reference' => $patrol->getRef(),
            'status' => $patrol->getStatus()->value,
            'duplicate' => $alreadyComplete,
        ]);
    }

    /** A refusal, in the one error shape §10 describes. */
    public static function error(PatrolApiException $problem): JsonResponse
    {
        return new JsonResponse([
            'code' => $problem->getProblemCode(),
            'message' => $problem->getMessage(),
            'retryable' => $problem->isRetryable(),
            'details' => (object) $problem->getDetails(),
        ], $problem->getStatusCode());
    }
}
