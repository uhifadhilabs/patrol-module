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

namespace UhifadhiLabs\Patrol\Service\Api;

use Doctrine\ORM\EntityManagerInterface;
use UhifadhiLabs\Patrol\Api\PatrolApiException;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolStatusEnum;

/**
 * `POST /api/patrols/{uuid}/complete` — API-CONTRACT.md §9.
 *
 * This is the call that flips a patrol into the module's map, library and
 * calendar, and it is what the phone treats as *synced*. So it verifies before
 * it agrees: the module must actually hold what the phone said it would send.
 *
 * Verifying matters because the failure it prevents is invisible. A patrol
 * published with two of its five photographs missing looks complete to everyone
 * who reads it — nothing on the screen says otherwise — and the missing evidence
 * is only noticed when somebody needs it. Refusing with `incomplete_patrol` and
 * the missing ids costs the phone one more sync; not refusing costs the record.
 */
final class PatrolCompletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Mark the patrol complete, or say exactly what is still missing.
     *
     * @return array{0: Patrol, 1: bool} [patrol, wasAlreadyComplete]
     *
     * @throws PatrolApiException
     */
    public function complete(Patrol $patrol): array
    {
        if (!$patrol->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $patrol->getClientUuid()?->toRfc4122());
        }

        // Already complete: a re-sent `complete` is success and changes nothing,
        // the same as every other repeated part (§1).
        if (PatrolStatusEnum::Complete === $patrol->getStatus()) {
            return [$patrol, true];
        }

        $missing = $this->missingParts($patrol);
        if ([] !== $missing) {
            throw PatrolApiException::incompletePatrol($missing);
        }

        $patrol->setStatus(PatrolStatusEnum::Complete);
        $this->entityManager->flush();

        return [$patrol, false];
    }

    /**
     * What the phone promised but has not delivered.
     *
     * Only PHOTOS can be checked, and honestly so: `photoCount` is the one
     * expectation the contract has the phone declare in advance (§6). It never
     * states a total fix count or observation count anywhere, so there is no
     * number to compare those against — and inventing a threshold ("a patrol
     * should have at least N fixes") would reject real patrols recorded under a
     * canopy. See the note in API-CONTRACT.md.
     *
     * @return array<string, mixed> keyed for the app to re-queue from
     */
    private function missingParts(Patrol $patrol): array
    {
        $observations = [];

        foreach ($patrol->getObservations() as $observation) {
            if ($observation->hasAllPhotos()) {
                continue;
            }

            $observations[] = [
                'observationUuid' => $observation->getClientUuid()?->toRfc4122() ?? $observation->getUuid()->toRfc4122(),
                'expectedPhotos' => $observation->getPhotoCount(),
                'heldPhotos' => $observation->heldPhotoCount(),
            ];
        }

        return [] === $observations ? [] : ['missingPhotos' => $observations];
    }
}
