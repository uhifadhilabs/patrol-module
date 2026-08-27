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

namespace UhifadhiLabs\Patrol\Api\State;

use Symfony\Component\HttpFoundation\Response;
use UhifadhiLabs\Patrol\Api\ContractResponse;
use UhifadhiLabs\Patrol\Api\PatrolApiContext;
use UhifadhiLabs\Patrol\Service\Api\PatrolEventService;

/**
 * `POST /api/patrols/{uuid}/events` — API-CONTRACT.md §9A.
 */
final class AppendEventsProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly PatrolEventService $events,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $recorder = $this->api->requireRecorder();

        $patrol = $this->api->patrol($this->api->uriUuid($uriVariables));

        [$accepted, $duplicate] = $this->events->append($patrol, $this->api->body(), $recorder);

        return ContractResponse::ack($accepted, $duplicate);
    }
}
