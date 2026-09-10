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

namespace Uhifadhi\Patrol\Model;

use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Patrol\Service\PatrolDashboardService;

/**
 * THE ONE FILTER. The design captions the coverage plate "one filter drives map
 * AND log", and this object IS that filter: four axes — type, station, zone and
 * month — read once from the request, so there is exactly one place a question
 * about which patrols are on screen can be answered.
 *
 * IT IS A QUERY, NOT A CONVERSATION. Type, station and zone used to narrow the
 * screen in the browser, through a `patrol:filter` document event that every
 * widget answered. That made the map and the log agree with each other and with
 * nothing else: a link could not be shared, a reload lost the choice, the
 * server-rendered counts described a month nobody was looking at, and the
 * charts never narrowed at all. All four axes are now query parameters, which
 * is what the incidents register already does, so one request draws the map, the
 * log and the charts.
 *
 * EVERY FIELD IS UNTRUSTED, and every unreadable value degrades to "no filter"
 * rather than throwing: a hand-edited query string must answer with more
 * patrols, never a stack trace.
 *
 * IT HOLDS NO AREA. The area is a route parameter every patrol query already
 * takes explicitly, so leaving it out keeps this a value object that unit-tests
 * without a database behind it.
 */
final readonly class PatrolFilter
{
    /**
     * @param string|null        $type    one configured patrol type key; null is every type
     * @param string|null        $station one station's free-text name; null is every station
     * @param string|null        $zone    one zone's name, as the spatial join reports it; null is every zone
     * @param \DateTimeImmutable $month   the first instant of the month on screen
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public ?string $type = null,
        public ?string $station = null,
        public ?string $zone = null,
    ) {
    }

    /**
     * READ A REQUEST. `getString()` is deliberate throughout: a query bag holding
     * an ARRAY where a word belongs is a bad request, and this coerces it to ''
     * rather than letting an array reach a comparison.
     */
    public static function fromRequest(Request $request, \DateTimeImmutable $now): self
    {
        return new self(
            self::monthFrom($request, $now),
            self::word($request, 'type'),
            self::word($request, 'station'),
            self::word($request, 'zone'),
        );
    }

    /**
     * The half-open window the month on screen covers — the window every "this
     * month" figure is scoped to, decided by the service that defines a month.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, untilExclusive]
     */
    public function window(): array
    {
        return PatrolDashboardService::monthRange($this->month);
    }

    /** The same question narrowed to one patrol type — what a type chip links to. */
    public function onlyType(string $type): self
    {
        return new self($this->month, $type, $this->station, $this->zone);
    }

    /** The same question with the type cleared — what the "all" chip links to. */
    public function withoutType(): self
    {
        return new self($this->month, null, $this->station, $this->zone);
    }

    public function onlyStation(string $station): self
    {
        return new self($this->month, $this->type, $station, $this->zone);
    }

    public function withoutStation(): self
    {
        return new self($this->month, $this->type, null, $this->zone);
    }

    public function onlyZone(string $zone): self
    {
        return new self($this->month, $this->type, $this->station, $zone);
    }

    public function withoutZone(): self
    {
        return new self($this->month, $this->type, $this->station, null);
    }

    /** The same choices in a different month — what a month option links to. */
    public function inMonth(\DateTimeImmutable $month): self
    {
        return new self($month->modify('first day of this month')->setTime(0, 0), $this->type, $this->station, $this->zone);
    }

    /** Whether anything at all is narrowing the screen beyond its month. */
    public function isNarrowed(): bool
    {
        return null !== $this->type || null !== $this->station || null !== $this->zone;
    }

    /**
     * WHICH PATROLS ARE IN VIEW, in one predicate, so the map, the log and the
     * charts read the same answer. A patrol with no station or no zone carries
     * the empty string, which no chosen value equals — an unzoned patrol is
     * absent from a zone's view rather than present in every one.
     */
    public function matches(string $type, string $station, string $zone): bool
    {
        return (null === $this->type || $this->type === $type)
            && (null === $this->station || $this->station === $station)
            && (null === $this->zone || $this->zone === $zone);
    }

    /**
     * The query parameters that reproduce this filter — what every chip, option
     * and month link carries. The month rides along with the rest, so narrowing
     * by station keeps the month somebody is reading and the month dropdown can
     * change it while keeping everything else.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        if (null !== $this->type) {
            $query['type'] = $this->type;
        }
        if (null !== $this->station) {
            $query['station'] = $this->station;
        }
        if (null !== $this->zone) {
            $query['zone'] = $this->zone;
        }
        $query['month'] = $this->month->format('Y-m');

        return $query;
    }

    private static function word(Request $request, string $key): ?string
    {
        $value = trim($request->query->getString($key));

        return '' !== $value ? $value : null;
    }

    private static function monthFrom(Request $request, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $month = trim($request->query->getString('month'));
        if ('' !== $month) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return $now->modify('first day of this month')->setTime(0, 0);
    }
}
