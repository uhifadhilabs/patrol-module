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

namespace UhifadhiLabs\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Model\ParsedTrack;

/**
 * THE ingest path — one service, two doors. The upload screen feeds it today;
 * the tracking app POSTs to the same service through the API endpoint later.
 * Neither door re-implements parsing or persistence.
 *
 * Time, distance and route always come from the file; the caller contributes
 * only what a file cannot know (type, station, lead, team, note).
 */
final class TrackIngestService
{
    public function __construct(
        private readonly GpxParser $parser,
        private readonly EntityManagerInterface $em,
        private readonly float $gapThresholdMinutes,
    ) {
    }

    /** Parse without saving — the import screen's preview step. */
    public function preview(string $gpxXml): ParsedTrack
    {
        return $this->parser->parse($gpxXml, $this->gapThresholdMinutes);
    }

    /**
     * Parse and persist a patrol from a GPX document.
     *
     * @throws \UhifadhiLabs\Patrol\Exception\InvalidGpxException
     */
    public function ingest(
        string $gpxXml,
        AreaOfInterest $area,
        string $type,
        PatrolSourceEnum $source = PatrolSourceEnum::Gpx,
        ?string $station = null,
        ?User $lead = null,
        ?string $team = null,
        ?string $note = null,
    ): Patrol {
        $track = $this->preview($gpxXml);

        $patrol = new Patrol($area, $type)
            ->setSource($source)
            ->setStation($station)
            ->setLead($lead)
            ->setTeam($team)
            ->setNote($note)
            ->setStartedAt($track->startedAt)
            ->setEndedAt($track->endedAt)
            ->setDistanceKm($track->distanceKm)
            ->setTrack($track->toGeoJson())
            ->setPointCount($track->pointCount())
            ->setGapCount($track->gapCount);

        $this->em->persist($patrol);
        $this->em->flush();

        return $patrol;
    }
}
