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
use UhifadhiLabs\Patrol\Service\Api\TrackBatchService;

/**
 * `POST /api/patrols/{uuid}/track` — API-CONTRACT.md §5.
 */
final class AppendTrackProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly TrackBatchService $track,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $this->api->requireRecorder();

        $patrol = $this->api->patrol($this->api->uriUuid($uriVariables));

        [$accepted, $duplicate] = $this->track->append($patrol, $this->api->body());

        return ContractResponse::ack($accepted, $duplicate);
    }
}
