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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Api\ContractResponse;
use UhifadhiLabs\Patrol\Api\PatrolApiContext;
use UhifadhiLabs\Patrol\Api\PatrolApiException;
use UhifadhiLabs\Patrol\Service\Api\PhotoSyncService;

/**
 * `POST /api/observations/{uuid}/photos` — API-CONTRACT.md §8.
 *
 * The one multipart endpoint. The fields are read straight off the request
 * rather than deserialized: a multipart body carries an uploaded FILE, which is
 * a filesystem handle and not something a JSON deserializer has any business
 * modelling.
 */
final class UploadPhotoProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly PhotoSyncService $photos,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $this->api->requireRecorder();

        $observation = $this->api->observation($this->api->uriUuid($uriVariables));
        $request = $this->api->request();

        $rawUuid = trim((string) $request->request->get('clientUuid'));
        if (!Uuid::isValid($rawUuid)) {
            throw PatrolApiException::invalidPayload('"clientUuid" is required and must be a UUID.', ['field' => 'clientUuid']);
        }
        $clientUuid = Uuid::fromString($rawUuid);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw PatrolApiException::invalidPayload('A photo upload needs a "file" part.', ['field' => 'file']);
        }

        [, $duplicate] = $this->photos->store(
            $observation,
            $clientUuid,
            $file,
            $this->takenAt($request->request->get('takenAt')),
        );

        return ContractResponse::ack([$clientUuid->toRfc4122()], $duplicate);
    }

    /**
     * When the picture was taken, per the phone. Absent is acceptable — a
     * photograph without a timestamp is still evidence — but a malformed one is
     * refused rather than silently dropped.
     *
     * @throws PatrolApiException
     */
    private function takenAt(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw PatrolApiException::invalidPayload('"takenAt" is not an ISO-8601 timestamp.', ['field' => 'takenAt', 'value' => $raw]);
        }
    }
}
