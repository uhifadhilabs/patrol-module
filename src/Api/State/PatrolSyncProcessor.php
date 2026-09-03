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

namespace Uhifadhi\Patrol\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Patrol\Api\ContractResponse;
use Uhifadhi\Patrol\Api\PatrolApiException;

/**
 * What every one of this module's sync endpoints has in common: it answers with
 * a {@see Response} it built itself, and it turns a {@see PatrolApiException}
 * into the contract's error document.
 *
 * Returning a Response is the supported way to mean exactly this — api-platform
 * passes one straight through SerializeProcessor and RespondProcessor (see the
 * first line of each). It is what lets these endpoints promise the field app
 * literal key names and a literal status, including the 201-vs-200 distinction
 * between a create and a re-send, which no amount of serializer configuration
 * could express.
 *
 * Errors are caught HERE rather than left to the host's exception listener so
 * the bundle's endpoints answer correctly on their own — a module that can only
 * report failure through its host cannot be tested without one.
 *
 * @implements ProcessorInterface<mixed, Response>
 */
abstract class PatrolSyncProcessor implements ProcessorInterface
{
    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    final public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        try {
            return $this->handle($uriVariables);
        } catch (PatrolApiException $problem) {
            return ContractResponse::error($problem);
        }
    }

    /**
     * @param array<string, mixed> $uriVariables
     *
     * @throws PatrolApiException
     */
    abstract protected function handle(array $uriVariables): Response;
}
