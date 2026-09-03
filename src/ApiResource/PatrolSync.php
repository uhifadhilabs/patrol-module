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

namespace Uhifadhi\Patrol\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Uhifadhi\Patrol\Api\State\AppendEventsProcessor;
use Uhifadhi\Patrol\Api\State\AppendFlightsProcessor;
use Uhifadhi\Patrol\Api\State\AppendObservationsProcessor;
use Uhifadhi\Patrol\Api\State\AppendTrackProcessor;
use Uhifadhi\Patrol\Api\State\CompletePatrolProcessor;
use Uhifadhi\Patrol\Api\State\CreatePatrolProcessor;

/**
 * The field app's patrol endpoints — API-CONTRACT.md §4, §5, §6, §7, §9, §9A.
 *
 * ## The module seam
 *
 * The HOST installs api-platform (one /api, one firewall, one OpenAPI document)
 * and knows nothing about patrols. This bundle declares its own endpoints here,
 * and api-platform finds them with no host configuration at all: it scans
 * `<bundle path>/ApiResource` for every registered bundle, exactly as it scans
 * `<bundle path>/Entity` — see
 * `vendor/api-platform/core/src/Symfony/Bundle/DependencyInjection/ApiPlatformExtension.php`
 * (getBundlesResourcesPaths). That is the same zero-config property the bundle's
 * doctrine mappings already have, and it is why installing this module adds
 * these routes and uninstalling it removes them, with nothing to edit either way.
 *
 * ## Why there is no input DTO
 *
 * `deserialize: false`, `validate: false`, `read: false`. These bodies are
 * batches of heterogeneous rows — 500 fixes, a mixed list of observations — and
 * their rules are per-field and contract-specific: a `batchUuid` that is not a
 * UUID at all, a position that must be refused for being off the planet, a
 * timestamp that must be trusted verbatim rather than normalised. That checking
 * lives in {@see \Uhifadhi\Patrol\Api\Payload}, where the error it raises
 * carries the contract code the app has a rule for. A DTO layer on top would add
 * a second place for these field names to drift from the document, and no
 * safety: the serializer cannot express "trust this timestamp exactly".
 *
 * Entities are never exposed. Nothing here is a Doctrine entity, and every
 * response is written by hand in
 * {@see \Uhifadhi\Patrol\Api\ContractResponse}.
 */
#[ApiResource(
    shortName: 'PatrolSync',
    operations: [
        new Post(
            uriTemplate: '/patrols',
            status: 201,
            description: 'Record a patrol. Upserts by clientUuid: a re-send returns the same patrol with "duplicate": true and 200.',
            deserialize: false,
            validate: false,
            read: false,
            processor: CreatePatrolProcessor::class,
        ),
        new Post(
            uriTemplate: '/patrols/{uuid}/track',
            status: 200,
            description: 'Append a batch of GPS fixes. Idempotent per batchUuid. Refused for drone patrols: those fixes are the operator\'s position, not coverage.',
            deserialize: false,
            validate: false,
            read: false,
            processor: AppendTrackProcessor::class,
        ),
        new Post(
            uriTemplate: '/patrols/{uuid}/observations',
            status: 200,
            description: 'Append the patrol\'s observations. Each is idempotent by its own clientUuid, so a re-sent part adds only what is missing.',
            deserialize: false,
            validate: false,
            read: false,
            processor: AppendObservationsProcessor::class,
        ),
        new Post(
            uriTemplate: '/patrols/{uuid}/flights',
            status: 200,
            description: 'Append drone launch points and flights. Coverage is the declared sectors, never a track.',
            deserialize: false,
            validate: false,
            read: false,
            processor: AppendFlightsProcessor::class,
        ),
        new Post(
            uriTemplate: '/patrols/{uuid}/events',
            status: 200,
            description: 'Append what the ranger did to the patrol (renamed, type_changed, discarded). Idempotent per event clientUuid; each accepted event also updates the patrol row.',
            deserialize: false,
            validate: false,
            read: false,
            processor: AppendEventsProcessor::class,
        ),
        new Post(
            uriTemplate: '/patrols/{uuid}/complete',
            status: 200,
            description: 'Verify every declared part arrived and publish the patrol. Answers 409 incomplete_patrol with the missing ids if not. Send status "discarded" with a discardReason to close it as discarded instead — nothing is verified on that path.',
            deserialize: false,
            validate: false,
            read: false,
            processor: CompletePatrolProcessor::class,
        ),
    ],
)]
final class PatrolSync
{
}
