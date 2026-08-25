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

use Symfony\Component\HttpFoundation\Response;

/**
 * A refusal the field app is expected to understand — API-CONTRACT.md §10.
 *
 * Every code the contract names for patrols has a named constructor here, so
 * the strings live in one place and a typo cannot quietly become an error the
 * app has no rule for. The app's whole failure policy keys off the status and
 * `retryable`: none of these are retryable, because every one of them describes
 * something that will be just as wrong in two minutes.
 *
 * The module raises and renders its own errors rather than borrowing the host's
 * exception type: it owns these endpoints, and a bundle that can only report
 * failure through its host is a bundle that cannot be tested on its own.
 */
final class PatrolApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details what the app should re-queue or show
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $problemCode,
        string $message,
        private readonly bool $retryable = false,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** No patrol with that uuid — the app must not keep posting parts to it. */
    public static function unknownPatrol(string $uuid): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            'unknown_patrol',
            'No patrol is known by that uuid.',
            details: ['patrolUuid' => $uuid],
        );
    }

    /**
     * No observation with that uuid — the photo has nothing to attach to. The
     * contract names `unknown_patrol` but not this; it is the same situation
     * one level down, and the app parks the part either way.
     */
    public static function unknownObservation(string $uuid): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            'unknown_observation',
            'No observation is known by that uuid.',
            details: ['observationUuid' => $uuid],
        );
    }

    /**
     * Somebody corrected this patrol in the web module, so the phone's queued
     * part is stale. §10 gives editing exactly one writer; applying this would
     * overwrite a person's correction with an old retry.
     */
    public static function patrolImmutable(string $uuid): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            'patrol_immutable',
            'This patrol has been edited in the web module and no longer accepts uploads.',
            details: ['patrolUuid' => $uuid],
        );
    }

    /**
     * A track arrived for a drone patrol. Refused rather than stored, because
     * during a drone patrol the phone's positions are the OPERATOR's — filing
     * them as the patrol's track would be a lie about what was covered (§5).
     */
    public static function invalidTrackForDronePatrol(string $batchKey): self
    {
        return new self(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'invalid_track_for_drone_patrol',
            'A drone patrol has no track: those fixes are the operator\'s positions, not coverage.',
            details: ['batchUuid' => $batchKey],
        );
    }

    /** @param array<string, mixed> $details */
    public static function invalidGeometry(string $message, array $details = []): self
    {
        return new self(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_geometry', $message, details: $details);
    }

    /**
     * `complete` was called but the module does not hold everything the phone
     * said it would send. The missing ids go back so the app re-queues exactly
     * those parts and nothing else (§9).
     *
     * @param array<string, mixed> $missing
     */
    public static function incompletePatrol(array $missing): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            'incomplete_patrol',
            'Some parts of this patrol have not arrived yet.',
            details: $missing,
        );
    }

    /** A category outside the deployment's configured vocabulary. */
    public static function unsupportedCategory(string $category, string $clientUuid): self
    {
        return new self(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'unsupported_category',
            \sprintf('"%s" is not an observation category this deployment records.', $category),
            details: ['clientUuid' => $clientUuid, 'category' => $category],
        );
    }

    /**
     * A malformed body — a missing uuid, an unparseable timestamp.
     *
     * @param array<string, mixed> $details
     */
    public static function invalidPayload(string $message, array $details = []): self
    {
        return new self(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_payload', $message, details: $details);
    }

    /** The caller is authenticated but does not hold `patrols.record`. */
    public static function forbidden(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            'forbidden',
            'This account is not permitted to record patrols.',
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getProblemCode(): string
    {
        return $this->problemCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
