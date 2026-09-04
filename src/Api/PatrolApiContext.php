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

namespace Uhifadhi\Patrol\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Patrol\Controller\PatrolRecordController;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * The three questions every sync endpoint asks before it does anything: who is
 * calling, which patrol, and what did they send.
 *
 * Answered in one place so no endpoint can quietly skip a step. Every one of
 * these routes WRITES field records, so every one requires the same permission
 * the two recording screens do — `patrols.record`, declared by this module and
 * granted by the host. A module that authenticated its API differently from its
 * own UI would be two security models pretending to be one.
 */
final class PatrolApiContext
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly PatrolRepository $patrols,
        private readonly ObservationRepository $observations,
    ) {
    }

    /**
     * The signed-in field worker, once confirmed they may record patrols.
     *
     * 403, not 401: the token is valid and the caller is known — they simply do
     * not hold this permission, and the app shows a different thing for each
     * (§10).
     *
     * @throws PatrolApiException
     */
    public function requireRecorder(): UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof UserInterface) {
            throw new PatrolApiException(401, 'unauthorized', 'Sign in again.');
        }

        if (!$this->authorizationChecker->isGranted(PatrolRecordController::RECORD_PERMISSION)) {
            throw PatrolApiException::forbidden();
        }

        return $user;
    }

    /**
     * The patrol a URI names, addressed by the CLIENT's uuid — the only
     * identifier the phone has ever seen for it.
     *
     * @throws PatrolApiException
     */
    public function patrol(string $uuid): Patrol
    {
        if (!Uuid::isValid($uuid)) {
            throw PatrolApiException::unknownPatrol($uuid);
        }

        return $this->patrols->findOneByClientUuid(Uuid::fromString($uuid))
            ?? throw PatrolApiException::unknownPatrol($uuid);
    }

    /** @throws PatrolApiException */
    public function observation(string $uuid): Observation
    {
        if (!Uuid::isValid($uuid)) {
            throw PatrolApiException::unknownObservation($uuid);
        }

        return $this->observations->findOneByClientUuid(Uuid::fromString($uuid))
            ?? throw PatrolApiException::unknownObservation($uuid);
    }

    /**
     * The decoded JSON body.
     *
     * Read from the raw request rather than deserialized into a DTO: these
     * payloads are batches of heterogeneous rows whose validity rules are
     * per-field and contract-specific ({@see Payload}), and a DTO layer in
     * between would only be a second place for the field names to drift.
     *
     * @return array<string, mixed>
     *
     * @throws PatrolApiException
     */
    public function body(): array
    {
        $request = $this->request();
        $content = $request->getContent();

        if ('' === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw PatrolApiException::invalidPayload('The request body is not valid JSON.', ['reason' => $exception->getMessage()]);
        }

        if (!\is_array($decoded)) {
            throw PatrolApiException::invalidPayload('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /** @throws PatrolApiException */
    public function request(): Request
    {
        return $this->requestStack->getCurrentRequest()
            ?? throw PatrolApiException::invalidPayload('There is no request to read.');
    }

    /**
     * The uuid from the URI. api-platform hands uriVariables to the processor;
     * this only turns a missing one into the contract's own error rather than a
     * type error.
     *
     * @param array<string, mixed> $uriVariables
     *
     * @throws PatrolApiException
     */
    public function uriUuid(array $uriVariables, string $key = 'uuid'): string
    {
        $value = $uriVariables[$key] ?? null;

        return \is_string($value) && '' !== $value
            ? $value
            : throw PatrolApiException::invalidPayload(\sprintf('The URI is missing "%s".', $key));
    }
}
