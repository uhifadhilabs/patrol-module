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
use UhifadhiLabs\Patrol\Api\Payload;
use UhifadhiLabs\Patrol\Entity\Flight;
use UhifadhiLabs\Patrol\Entity\LaunchPoint;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Repository\FlightRepository;
use UhifadhiLabs\Patrol\Repository\LaunchPointRepository;

/**
 * `POST /api/patrols/{uuid}/flights` — API-CONTRACT.md §7. The drone branch.
 *
 * Launch points and their flights arrive together, and they are all the coverage
 * a drone patrol will ever have: the sectors are DECLARED by the operator, not
 * measured, because nobody records where the aircraft actually went. The module
 * renders those sectors and never a track — see {@see TrackBatchService} for the
 * other half of that rule.
 *
 * This part also closes the loop the upload ORDER opens: observations are sent
 * before flights (§11), so a drone observation naming a flight arrives before
 * that flight exists. Once the flights land, the observations waiting on them
 * are linked up.
 */
final class FlightSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LaunchPointRepository $launchPoints,
        private readonly FlightRepository $flights,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: list<string>, 1: bool} [acceptedUuids, everyOneWasAlreadyHeld]
     *
     * @throws PatrolApiException
     */
    public function append(Patrol $patrol, array $data): array
    {
        if (!$patrol->acceptsFieldUploads()) {
            throw PatrolApiException::patrolImmutable((string) $patrol->getClientUuid()?->toRfc4122());
        }

        $accepted = [];
        $created = 0;

        foreach (Payload::rows($data, 'launchPoints') as $row) {
            $clientUuid = Payload::uuid($row, 'clientUuid');
            $accepted[] = $clientUuid->toRfc4122();

            if ($this->launchPoints->findOneByClientUuid($clientUuid) instanceof LaunchPoint) {
                continue;
            }

            $position = $row['position'] ?? null;
            if (!\is_array($position)) {
                throw PatrolApiException::invalidGeometry('A launch point needs a position.', ['clientUuid' => $clientUuid->toRfc4122()]);
            }

            /** @var array<string, mixed> $position */
            $launchPoint = new LaunchPoint(
                $patrol,
                $clientUuid,
                Payload::requiredString($row, 'label'),
                Payload::geoJsonPoint($position),
            )
                ->setName(Payload::string($row, 'name'))
                ->setAccuracyM(Payload::float($position, 'accuracyM'))
                ->setSatellites(Payload::int($position, 'satellites'))
                ->setEstablishedAt(Payload::timestamp($row, 'establishedAt'));

            $this->applySector($launchPoint, $row);

            $this->entityManager->persist($launchPoint);
            ++$created;
        }

        // Flushed before the flights so a flight can find its launch point by
        // client uuid in the same request.
        $this->entityManager->flush();

        foreach (Payload::rows($data, 'flights') as $row) {
            $clientUuid = Payload::uuid($row, 'clientUuid');
            $accepted[] = $clientUuid->toRfc4122();

            if ($this->flights->findOneByClientUuid($clientUuid) instanceof Flight) {
                continue;
            }

            $launchPointUuid = Payload::optionalUuid($row, 'launchPointUuid');

            $flight = new Flight($patrol, $clientUuid)
                ->setLaunchPoint(null === $launchPointUuid ? null : $this->launchPoints->findOneByClientUuid($launchPointUuid))
                ->setSequence(Payload::int($row, 'sequence') ?? 1)
                ->setStartedAt(Payload::timestamp($row, 'startedAt'))
                // Null means still airborne at upload time (§7) — a real state,
                // never backfilled with a guess.
                ->setEndedAt(Payload::timestamp($row, 'endedAt'));

            $this->entityManager->persist($flight);
            ++$created;
        }

        $this->entityManager->flush();

        $this->linkWaitingObservations($patrol);
        $this->entityManager->flush();

        return [$accepted, [] !== $accepted && 0 === $created];
    }

    /**
     * A sector is either a radius or a drawn polygon (§7), and the two are set
     * through named methods so the pair can never disagree — a "radius" sector
     * with no radius would be undrawable, and the module would have nothing
     * honest to fall back on.
     *
     * @param array<string, mixed> $row
     *
     * @throws PatrolApiException
     */
    private function applySector(LaunchPoint $launchPoint, array $row): void
    {
        $polygon = Payload::geoJsonPolygon($row, 'sectorPolygon');
        if (null !== $polygon) {
            $launchPoint->declarePolygonSector($polygon);

            return;
        }

        $radius = Payload::float($row, 'sectorRadiusM');
        if (null !== $radius && $radius > 0.0) {
            $launchPoint->declareRadiusSector($radius);

            return;
        }

        throw PatrolApiException::invalidGeometry('A launch point needs either a sector radius or a sector polygon.', ['clientUuid' => $launchPoint->getClientUuid()->toRfc4122()]);
    }

    /**
     * Attach the flights and launch points to the observations that named them
     * before they existed. Without this the drone branch would lose which sortie
     * saw what, purely because of the order the parts are uploaded in.
     */
    private function linkWaitingObservations(Patrol $patrol): void
    {
        foreach ($patrol->getObservations() as $observation) {
            $launchPointUuid = $observation->getLaunchPointClientUuid();
            if (null === $observation->getLaunchPoint() && null !== $launchPointUuid) {
                $observation->setLaunchPoint($this->launchPoints->findOneByClientUuid($launchPointUuid));
            }

            $flightUuid = $observation->getFlightClientUuid();
            if (null === $observation->getFlight() && null !== $flightUuid) {
                $observation->setFlight($this->flights->findOneByClientUuid($flightUuid));
            }
        }
    }
}
