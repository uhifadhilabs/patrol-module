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

namespace UhifadhiLabs\Patrol\Overview;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Overview\PulseEvent;
use Uhifadhi\Overview\PulseProviderInterface;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Repository\ObservationRepository;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;
use UhifadhiLabs\Patrol\Service\PatrolOverviewService;

/**
 * WHAT PATROLS DID WHILE YOU WERE AWAY.
 *
 * The pulse is a log of MOVES, not of records: a patrol opened, a patrol closed,
 * an observation was logged, a patrol was discarded. The host merges every
 * module's moves, sorts them by time and groups them by day; it does not
 * interpret any of them, which is why a new module needs no work on that widget.
 *
 * FOUR MOVES, AND THREE OF THEM ARE NOT EVENTS. This module keeps a real event
 * table ({@see \UhifadhiLabs\Patrol\Entity\PatrolEvent}), but it records
 * CORRECTIONS — renamed, retyped, discarded — because those are the things that
 * need a signature and an audit trail. Opening and closing a patrol are not
 * corrections; they are the record's own timestamps, and `startedAt` /`endedAt`
 * falling inside the window IS the move having happened. Reading them straight
 * is honest and needs no second write; the alternative is a synthetic event row
 * that could disagree with the column beside it.
 *
 * A DISCARD IS THE ONE MOVE WITH A STATE, so it is the one that carries one. The
 * others say what happened and land in no new state — a patrol that closed is a
 * closed patrol, and a status chip repeating that would be noise.
 *
 * When the platform's move log lands (Symfony Workflow plus an audit trail),
 * this is the seam it fills; nothing on the host's widget changes.
 */
final readonly class PatrolPulse implements PulseProviderInterface
{
    public function __construct(
        private PatrolRepository $patrols,
        private ObservationRepository $observations,
        private PatrolOverviewService $overview,
        /** @var array<string, array{label: string}> the deployment's patrol.types map */
        private array $types,
        /** @var array<string, array{label: string}> the deployment's patrol.observation_categories map */
        private array $categories,
    ) {
    }

    public function moduleSlug(): string
    {
        return PatrolOverviewContributor::SLUG;
    }

    public function pulseFor(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        return [
            ...$this->opened($area, $since, $now),
            ...$this->closed($area, $since, $now),
            ...$this->logged($area, $since, $now),
        ];
    }

    /**
     * A PATROL OPENED — a patrol whose `startedAt` fell in the window.
     *
     * A patrol that was opened and DISCARDED still opened: the discard is a
     * later judgement about the record, not a claim that the shift never began,
     * and a pulse that quietly dropped it would be a log with a hole in it. It
     * is drawn quietly, which is what the module does everywhere else.
     *
     * @return list<PulseEvent>
     */
    private function opened(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $events = [];
        foreach ($this->patrols->findByAreaStartedBetween($area, $since, $now) as $patrol) {
            $startedAt = $patrol->getStartedAt();
            if (null === $startedAt || !$patrol->getStatus()->isPresentable()) {
                continue;
            }
            $events[] = $this->event(
                $area,
                $patrol,
                $startedAt,
                'patrol opened',
                \sprintf(
                    '%s patrol out of %s%s',
                    ucfirst(mb_strtolower($this->types[$patrol->getType()]['label'] ?? $patrol->getType())),
                    $patrol->getStation() ?? 'an unrecorded station',
                    $patrol->isDiscarded() ? ' — since discarded' : '',
                ),
                $patrol->isDiscarded() ? 'discarded' : null,
                $patrol->isDiscarded() ? 'discarded' : null,
            );
        }

        return $events;
    }

    /**
     * A PATROL CLOSED — with what it came back with. Distance is an em dash
     * where the patrol recorded none, never 0 km.
     *
     * @return list<PulseEvent>
     */
    private function closed(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $events = [];
        foreach ($this->patrols->findByAreaEndedBetween($area, $since, $now) as $patrol) {
            $endedAt = $patrol->getEndedAt();
            if (null === $endedAt || !$patrol->getStatus()->countsTowardsStatistics()) {
                continue;
            }
            $km = $patrol->getDistanceKm();
            $events[] = $this->event(
                $area,
                $patrol,
                $endedAt,
                'patrol closed',
                \sprintf(
                    'Closed at %s — %s, %d %s',
                    $patrol->getStation() ?? 'an unrecorded station',
                    null === $km ? 'distance not recorded' : number_format($km, 1).' km',
                    $count = $patrol->getObservations()->count(),
                    1 === $count ? 'observation' : 'observations',
                ),
                'closed',
                'closed',
            );
        }

        return $events;
    }

    /**
     * AN OBSERVATION LOGGED, by when the ranger saw it rather than by when the
     * handset found signal — which is the same rule the day's count reads by.
     *
     * @return list<PulseEvent>
     */
    private function logged(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $events = [];
        foreach ($this->observations->findByAreaLoggedBetween($area, $since, $now) as $observation) {
            $loggedAt = $observation->getLoggedAt();
            if (null === $loggedAt) {
                continue;
            }
            $patrol = $observation->getPatrol();
            $note = $observation->getNote();
            $category = $this->categories[$observation->getCategory()]['label'] ?? $observation->getCategory();

            $events[] = new PulseEvent(
                $loggedAt,
                PatrolOverviewContributor::SLUG,
                'Patrols',
                $observation->getRef(),
                'observation logged',
                null === $note || '' === $note ? $category : \sprintf('%s — %s', $category, $note),
                $this->overview->patrolUrl($area, $patrol),
                PatrolDashboardService::TRACK_COLORS[0],
                meta: array_values(array_filter([$patrol->getStation(), $patrol->getRef()])),
            );
        }

        return $events;
    }

    /**
     * One row, with the module's own colour on it.
     *
     * The swatch is {@see PatrolDashboardService::TRACK_COLORS}[0] — the same
     * accent the module's tracks, chips and legend wear — because a colour is
     * data and this module states it once.
     */
    private function event(AreaOfInterest $area, Patrol $patrol, \DateTimeImmutable $at, string $move, string $summary, ?string $state, ?string $stateClass): PulseEvent
    {
        return new PulseEvent(
            $at,
            PatrolOverviewContributor::SLUG,
            'Patrols',
            $patrol->getRef(),
            $move,
            $summary,
            $this->overview->patrolUrl($area, $patrol),
            PatrolDashboardService::TRACK_COLORS[0],
            $state,
            $stateClass,
            array_values(array_filter([
                $patrol->getStation(),
                null === $patrol->getLead() ? null : trim($patrol->getLead()->getFirstName().' '.$patrol->getLead()->getLastName()),
            ])),
        );
    }
}
