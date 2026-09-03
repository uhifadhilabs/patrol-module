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

use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Patrol\Api\ContractResponse;
use Uhifadhi\Patrol\Api\PatrolApiContext;
use Uhifadhi\Patrol\Service\Api\PatrolUpsertService;

/**
 * `POST /api/patrols` — API-CONTRACT.md §4.
 */
final class CreatePatrolProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly PatrolUpsertService $upsert,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $recorder = $this->api->requireRecorder();

        [$patrol, $duplicate] = $this->upsert->upsert($this->api->body(), $recorder);

        // 201 the first time, 200 on a re-send — the contract draws that line
        // and the app reads it.
        return ContractResponse::patrol($patrol, $duplicate);
    }
}
