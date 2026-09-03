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
use Uhifadhi\Patrol\Api\State\UploadPhotoProcessor;

/**
 * `POST /api/observations/{uuid}/photos` — API-CONTRACT.md §8.
 *
 * Separate from {@see PatrolSync} because it is a different kind of request:
 * multipart, one photograph per call, and the slow part of every sync. The
 * `inputFormats` declaration is what tells api-platform to accept
 * `multipart/form-data` on this operation alone — the rest of the API stays
 * JSON, and nothing else gains a file-upload surface.
 */
#[ApiResource(
    shortName: 'ObservationPhotoSync',
    operations: [
        new Post(
            uriTemplate: '/observations/{uuid}/photos',
            status: 200,
            inputFormats: ['multipart' => ['multipart/form-data']],
            description: 'Upload one photo for an observation. Idempotent by clientUuid: a re-upload returns "duplicate": true and stores nothing twice.',
            deserialize: false,
            validate: false,
            read: false,
            processor: UploadPhotoProcessor::class,
        ),
    ],
)]
final class ObservationPhotoSync
{
}
