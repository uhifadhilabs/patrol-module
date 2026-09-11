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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Exception\InvalidPatrolTimesException;

/**
 * THE SECOND DOOR INTO THIS MODULE'S RECORDS — a patrol written up by hand,
 * beside {@see TrackIngestService}, which is the door a recorded one comes
 * through.
 *
 * The two are deliberately separate services rather than one with a flag,
 * because they know different things. Ingest reads time, distance and route out
 * of a file and the caller contributes only what a file cannot know; here there
 * is no file, so every one of those facts is somebody's own account of the
 * shift.
 *
 * A SKETCH IS STAMPED AS ONE, FOR GOOD. {@see PatrolSourceEnum::Manual} is set
 * here rather than passed in, and no track, point count or gap count is written,
 * so a hand-entered patrol can never be read back as a measured one — the
 * distinction every coverage figure in this module rests on.
 *
 * IT DECIDES NOTHING ABOUT WHO IS ASKING, and nothing about the deployment's
 * vocabulary. Whether the caller holds "patrols.record", and whether `foot` is a
 * word this installation uses, are questions the screen settles before it calls
 * in here — the same division {@see TrackIngestService} keeps.
 */
final readonly class PatrolRecordingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param ?\DateTimeImmutable $endedAt null is a real state: a shift written
     *                                     up before it is closed
     *
     * @throws InvalidPatrolTimesException when the end does not follow the start
     */
    public function record(
        AreaOfInterest $area,
        PatrolType $type,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt = null,
        ?Station $station = null,
        ?UserInterface $lead = null,
        ?string $team = null,
        ?string $note = null,
        ?float $distanceKm = null,
    ): Patrol {
        if (null !== $endedAt && $endedAt <= $startedAt) {
            throw new InvalidPatrolTimesException($startedAt, $endedAt);
        }

        $patrol = new Patrol($area, $type)
            ->setSource(PatrolSourceEnum::Manual)
            ->setStationRecord($station)
            ->setLead($lead)
            ->setTeam($team)
            ->setNote($note)
            ->setStartedAt($startedAt)
            ->setEndedAt($endedAt)
            ->setDistanceKm($distanceKm);

        $this->entityManager->persist($patrol);
        $this->entityManager->flush();

        return $patrol;
    }
}
