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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Atlas\CalendarDay;
use Uhifadhi\Contracts\Atlas\CalendarFeedInterface;
use Uhifadhi\Contracts\Atlas\CalendarMonth;
use Uhifadhi\Contracts\Atlas\CalendarPill;
use Uhifadhi\Contracts\Atlas\PillHue;
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Repository\PatrolRepository;

/**
 * ONE AREA'S MONTH OF PATROLS, fed to the house calendar.
 *
 * THE ATLAS OWNS THE MONTH AND THIS OWNS WHAT IS IN IT. The grid, the day
 * head, the cell, its fixed height, the day number, the "+N more" and the
 * stepper are the atlas's, drawn the same way on every calendar in the
 * product. This module says only what happened on which day — which is the
 * whole bargain, and the reason the patrols month ships no grid of its own.
 *
 * A MARK IS A PATROL AND ITS REFERENCE IS THE LABEL. A cell is a fixed height
 * whatever it holds, so what goes on it is a fragment: P-0135, not a sentence.
 * Everything a reader might want past that is on the page the mark opens, and
 * what does not fit on a day was counted into "+N more" by the renderer, where
 * the height is known.
 *
 * THE HUE IS A STATE AND NEVER A TYPE. A patrol type is a CATEGORY — one of a
 * set an area writes — and a category is a chip with a hue dot, not a meaning
 * the five roles can carry; naming one here would have this module deciding
 * what a colour means in a product with two palettes. So a finished patrol is
 * the month's subject, a discard is quiet and hollow because the recording is
 * over and the effort is withdrawn, and a discard somebody stopped the purge
 * clock on asks to be looked at. Which TYPE a month shows is answered by the
 * surface's own control instead, through the scope.
 *
 * THE GRID'S NEIGHBOURS ARE ANSWERED TOO. A month starts mid-week, so the
 * first and last rows carry real days of the months either side and a patrol
 * that falls in one belongs in the cell it falls in. The window read here is
 * therefore the six weeks a grid can draw, not the month.
 */
final readonly class PatrolCalendar implements CalendarFeedInterface
{
    /** Six Monday-start weeks always cover a month wherever it begins. */
    private const int GRID_DAYS = 42;

    public function __construct(
        private PatrolRepository $patrols,
        private AreaOfInterestRepository $areas,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * THE ONE SPELLING OF THIS FEED'S SCOPE, so a surface and the feed cannot
     * disagree about it: the area, and optionally the one patrol type the
     * surface's control has narrowed to.
     */
    public static function scopeFor(string $areaUuid, ?string $type = null): string
    {
        return null === $type || '' === $type ? $areaUuid : $areaUuid.':'.$type;
    }

    public function month(YearMonth $month, ?string $scope = null): CalendarMonth
    {
        [$areaUuid, $type] = self::subjectOf($scope);
        $area = null === $areaUuid ? null : $this->areaFor($areaUuid);
        if (null === $area) {
            return CalendarMonth::none($month);
        }

        [$from, $until] = self::gridWindow($month);

        /** @var array<string, list<CalendarPill>> $pills */
        $pills = [];
        foreach ($this->patrols->findByAreaStartedBetween($area, $from, $until) as $patrol) {
            $started = $patrol->getStartedAt();
            // A patrol still arriving is not a record: every field a cell would
            // print is provisional. Its parts land, it completes, it appears.
            if (null === $started || !$patrol->getStatus()->isPresentable()) {
                continue;
            }
            if (null !== $type && $patrol->getType() !== $type) {
                continue;
            }

            $pills[$started->format('Y-m-d')][] = $this->pillFor($area, $patrol);
        }

        $days = [];
        foreach ($pills as $localDate => $onThatDay) {
            $days[$localDate] = new CalendarDay(
                $localDate,
                $onThatDay,
                url: $this->registerUrl($area, $localDate, $type),
            );
        }

        return new CalendarMonth($month, $days);
    }

    /** One patrol as a mark: its reference, its state, and the page it opens. */
    private function pillFor(AreaOfInterest $area, Patrol $patrol): CalendarPill
    {
        $discarded = PatrolStatusEnum::Discarded === $patrol->getStatus();

        return new CalendarPill(
            label: $patrol->getRef(),
            hue: match (true) {
                $discarded && $patrol->isHeld() => PillHue::Attention,
                $discarded => PillHue::Quiet,
                default => PillHue::Subject,
            },
            url: $this->urls->generate('patrol_show', [
                'uuid' => (string) $area->getUuidString(),
                'patrol' => $patrol->getUuid()->toRfc4122(),
            ]),
            // A discard is a CLOSED recording. Hollow says finished, which is
            // the same reading the map plate uses.
            closed: $discarded,
            title: self::titleOf($patrol),
        );
    }

    /**
     * WHAT A READER IS TOLD WHERE THE REFERENCE IS NOT ENOUGH — the kind of
     * patrol, the post it set out from, the hour it started, and the discard
     * where there is one. The same facts the day's own page opens with.
     */
    private static function titleOf(Patrol $patrol): string
    {
        $parts = [$patrol->getTypeLabel()];

        $station = $patrol->getStation();
        if (null !== $station && '' !== $station) {
            $parts[] = $station;
        }

        $started = $patrol->getStartedAt();
        if (null !== $started) {
            $parts[] = $started->format('H:i');
        }

        if (PatrolStatusEnum::Discarded === $patrol->getStatus()) {
            $parts[] = $patrol->isHeld() ? 'discarded, held' : 'discarded';
        }

        return implode(' · ', $parts);
    }

    /**
     * WHERE THE DAY GOES — the register, on the month that day falls in and
     * narrowed the way the calendar is, so "+N more" lands on the same question
     * the grid was answering rather than on everything ever recorded.
     */
    private function registerUrl(AreaOfInterest $area, string $localDate, ?string $type): string
    {
        $query = ['uuid' => (string) $area->getUuidString(), 'month' => substr($localDate, 0, 7)];
        if (null !== $type) {
            $query['type'] = $type;
        }

        return $this->urls->generate('patrol_list', $query);
    }

    /**
     * THE SIX WEEKS A GRID CAN DRAW, as a half-open window. A superset of what
     * the renderer lays out — a four-row February is inside it — so the feed
     * never has to know how many rows the month came to.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private static function gridWindow(YearMonth $month): array
    {
        $first = $month->firstDay();
        $weekday = (int) $first->format('N');
        $start = $first->modify(\sprintf('-%d days', $weekday - 1));

        return [$start, $start->modify(\sprintf('+%d days', self::GRID_DAYS))];
    }

    /**
     * THE SCOPE, SPLIT: `<area uuid>` or `<area uuid>:<type key>`. Anything
     * else is unreadable rather than half-understood.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function subjectOf(?string $scope): array
    {
        if (null === $scope || '' === $scope) {
            return [null, null];
        }

        $parts = explode(':', $scope);
        if (\count($parts) > 2 || '' === $parts[0]) {
            return [null, null];
        }

        $type = $parts[1] ?? '';

        return [$parts[0], '' === $type ? null : $type];
    }

    private function areaFor(string $areaUuid): ?AreaOfInterest
    {
        if (!Uuid::isValid($areaUuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($areaUuid)]);
    }
}
