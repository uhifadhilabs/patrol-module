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

namespace Uhifadhi\Patrol\Service\Api;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Api\Payload;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\TaxonomyKind;
use Uhifadhi\Patrol\Enum\PositionSourceEnum;
use Uhifadhi\Patrol\Repository\FlightRepository;
use Uhifadhi\Patrol\Repository\LaunchPointRepository;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;

/**
 * `POST /api/patrols/{uuid}/observations` — API-CONTRACT.md §6.
 *
 * All of a patrol's observations arrive in one part, and the phone re-sends the
 * WHOLE part if any of it failed. So each observation is matched individually by
 * its own clientUuid: a retry after a partial write must add only what is
 * missing, not duplicate what landed.
 *
 * Two fields here are load-bearing rather than descriptive:
 *
 * * `positionSource` — a drone observation is where the operator SAYS the drone
 *   was. Storing it as an ordinary fix would turn a judgement into evidence.
 * * `photoCount` — what the phone INTENDS to send. The gap between it and the
 *   photos actually held is what `complete` checks, and what stops the module
 *   publishing an observation whose evidence is still on a handset.
 */
final class ObservationSyncService
{
    /** The width of {@see Observation::$subcategory}. */
    private const int SUBCATEGORY_LIMIT = 60;

    /**
     * @param array<string, array{label: string}> $categories the deployment's
     *                                                        patrol.observation_categories vocabulary
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObservationRepository $observations,
        private readonly LaunchPointRepository $launchPoints,
        private readonly FlightRepository $flights,
        private readonly TaxonomyAdminService $taxonomy,
        private readonly array $categories,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: list<string>, 1: bool} [acceptedUuids, everyOneWasAlreadyHeld]
     *
     * @throws PatrolApiException
     */
    public function append(Patrol $patrol, array $data, UserInterface $recorder): array
    {
        if (!$patrol->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $patrol->getClientUuid()?->toRfc4122());
        }

        $rows = Payload::rows($data, 'observations');
        $accepted = [];
        $created = 0;

        foreach ($rows as $row) {
            $clientUuid = Payload::uuid($row, 'clientUuid');
            $accepted[] = $clientUuid->toRfc4122();

            if ($this->observations->findOneByClientUuid($clientUuid) instanceof Observation) {
                continue;
            }

            [$category, $subcategory] = $this->words($patrol, $row);

            $observation = new Observation($patrol, $category)
                ->setClientUuid($clientUuid)
                ->setSubcategory($subcategory)
                ->setNote(Payload::string($row, 'note'))
                ->setLoggedAt(Payload::timestamp($row, 'loggedAt'))
                ->setRecordedBy($recorder)
                ->setPhotoCount(Payload::int($row, 'photoCount') ?? 0)
                ->setPositionSource($this->positionSource($row))
                // Both the id and (if it is already here) the association. The
                // flights part arrives AFTER this one, so the association is
                // usually made later — see Observation::$flightClientUuid.
                ->setLaunchPointClientUuid($launchPointUuid = Payload::optionalUuid($row, 'launchPointUuid'))
                ->setFlightClientUuid($flightUuid = Payload::optionalUuid($row, 'flightUuid'))
                ->setLaunchPoint(null === $launchPointUuid ? null : $this->launchPoints->findOneByClientUuid($launchPointUuid))
                ->setFlight(null === $flightUuid ? null : $this->flights->findOneByClientUuid($flightUuid));

            $this->applyPosition($observation, $row);

            $this->entityManager->persist($observation);
            ++$created;
        }

        $this->entityManager->flush();

        // "duplicate" describes the PART: true only when the whole re-sent part
        // was already held, which is what the phone is asking about.
        return [$accepted, [] !== $rows && 0 === $created];
    }

    /**
     * THE TWO WORDS AN OBSERVATION ARRIVES UNDER, resolved against what
     * `GET /api/patrols/vocabulary` published — the area's observation kinds and
     * their sub-categories, by key.
     *
     * `category` is looked for in THIS AREA'S TAXONOMY first, because that is
     * the list the handset was handed. Failing that it is looked for in the
     * deployment-wide `patrol.observation_categories`, which is a parallel model
     * still in service and whose words older handsets still send. Failing both,
     * it is a word nobody here configured — and that is CREATED RETIRED in the
     * area, never refused, for the reason
     * {@see \Uhifadhi\Patrol\Service\PatrolVocabularyService} states for an
     * unknown station: the observation is gone where the disagreement is merely
     * dimmed on a settings screen.
     *
     * `subcategory` is optional, and it is resolved UNDER the kind the category
     * landed on — unknown likewise arriving retired. Where the category was a
     * deployment-wide word the area has no kind for, there is no second level to
     * resolve against and the word is stored as the handset sent it.
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: ?string} [category, subcategory]
     *
     * @throws PatrolApiException
     */
    private function words(Patrol $patrol, array $row): array
    {
        $area = $patrol->getArea();
        $word = Payload::requiredString($row, 'category');

        $kind = $this->taxonomy->findKindByWord($area, $word);
        if (!$kind instanceof TaxonomyKind && !isset($this->categories[$word])) {
            $kind = $this->taxonomy->resolveKind($area, $word);
        }

        $subcategory = Payload::string($row, 'subcategory');
        if (null !== $subcategory) {
            $subcategory = $kind instanceof TaxonomyKind
                ? $this->taxonomy->resolveSubcategory($kind, $subcategory)->getCode()
                // Cut to the column's width. A taxonomy code is already within
                // it; a word from nowhere has nothing else keeping it honest.
                : mb_substr($subcategory, 0, self::SUBCATEGORY_LIMIT);
        }

        return [$kind?->getCode() ?? $word, $subcategory];
    }

    /**
     * The position is optional (§6) — an observation can be logged without a
     * fix, and a missing one is stored as missing rather than as 0,0, which is
     * a real place in the Gulf of Guinea.
     *
     * @param array<string, mixed> $row
     *
     * @throws PatrolApiException
     */
    private function applyPosition(Observation $observation, array $row): void
    {
        $position = $row['position'] ?? null;

        if (!\is_array($position)) {
            return;
        }

        /** @var array<string, mixed> $position */
        $observation
            ->setPosition(Payload::geoJsonPoint($position))
            ->setAccuracyM(Payload::float($position, 'accuracyM'))
            ->setSatellites(Payload::int($position, 'satellites'));
    }

    /**
     * Defaults to `gps` only when the phone says nothing. An unrecognised value
     * is refused rather than coerced: guessing wrong here is exactly the
     * misrepresentation the field exists to prevent.
     *
     * @param array<string, mixed> $row
     *
     * @throws PatrolApiException
     */
    private function positionSource(array $row): PositionSourceEnum
    {
        $raw = Payload::string($row, 'positionSource');
        $positioned = \is_array($row['position'] ?? null);

        if (null === $raw) {
            return $positioned ? PositionSourceEnum::Gps : PositionSourceEnum::None;
        }

        $source = PositionSourceEnum::tryFrom($raw)
            ?? throw PatrolApiException::invalidPayload(\sprintf('"%s" is not a position source.', $raw), ['field' => 'positionSource', 'value' => $raw]);

        if (PositionSourceEnum::None === $source && $positioned) {
            throw PatrolApiException::invalidPayload('An observation with coordinates cannot claim no position source.', ['field' => 'positionSource', 'value' => $raw]);
        }

        return $source;
    }
}
