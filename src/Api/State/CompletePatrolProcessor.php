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
use UhifadhiLabs\Patrol\Service\Api\PatrolCompletionService;

/**
 * `POST /api/patrols/{uuid}/complete` — API-CONTRACT.md §9. The call that turns
 * a pile of accepted parts into a record the module will draw.
 */
final class CompletePatrolProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly PatrolCompletionService $completion,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $this->api->requireRecorder();

        $patrol = $this->api->patrol($this->api->uriUuid($uriVariables));

        [$completed, $alreadyComplete] = $this->completion->complete($patrol);

        // 200 either way: a repeated complete is success, and `duplicate`
        // carries the difference.
        return ContractResponse::completed($completed, $alreadyComplete);
    }
}
