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
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Patrol\Api\ContractResponse;
use Uhifadhi\Patrol\Api\PatrolApiContext;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Service\Api\VocabularySyncService;

/**
 * `GET /api/patrols/vocabulary` — the words one area lets a handset use.
 *
 * A PROVIDER RATHER THAN A PROCESSOR, because this is the sync's one READ and
 * the rest are writes: {@see PatrolSyncProcessor} is the shape of a write. What
 * it shares with them is everything else — the same permission
 * (`patrols.record`: the caller is a field client, and this is the list they
 * record against), the same hand-built JSON, and the same error document.
 *
 * The area is a query parameter and not a URI segment, because the handset holds
 * an area id and no route of its own to it; `since` is optional and turns the
 * answer into a delta.
 *
 * @implements ProviderInterface<Response>
 */
final readonly class VocabularyProvider implements ProviderInterface
{
    public function __construct(
        private PatrolApiContext $api,
        private RequestStack $requests,
        private VocabularySyncService $vocabulary,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        try {
            $this->api->requireRecorder();

            $query = $this->requests->getCurrentRequest()?->query;

            return new JsonResponse($this->vocabulary->forArea(
                $query?->getString('areaId') ?? '',
                $query?->getString('since') ?: null,
            ));
        } catch (PatrolApiException $problem) {
            return ContractResponse::error($problem);
        }
    }
}
