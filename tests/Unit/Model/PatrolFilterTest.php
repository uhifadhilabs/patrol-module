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

namespace Uhifadhi\Patrol\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Patrol\Model\PatrolFilter;

/**
 * THE ONE FILTER, read from a request and written back into one.
 *
 * Every field is untrusted: an empty or unreadable value widens the view rather
 * than narrowing it, and an array where a word belongs is the one thing the
 * framework refuses outright.
 */
final class PatrolFilterTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-22 09:00:00');
    }

    public function testAnEmptyRequestOpensOnTheMonthContainingNowAndNarrowsNothing(): void
    {
        $filter = PatrolFilter::fromRequest(new Request(), $this->now);

        self::assertNull($filter->type);
        self::assertNull($filter->station);
        self::assertNull($filter->zone);
        self::assertSame('2026-08-01', $filter->month->format('Y-m-d'));
        self::assertFalse($filter->isNarrowed());
        self::assertSame(['month' => '2026-08'], $filter->toQuery());
    }

    public function testEveryAxisIsReadFromTheQuery(): void
    {
        $filter = PatrolFilter::fromRequest(
            new Request(['type' => 'foot', 'station' => 'North post', 'zone' => 'Open water', 'month' => '2026-07']),
            $this->now,
        );

        self::assertSame('foot', $filter->type);
        self::assertSame('North post', $filter->station);
        self::assertSame('Open water', $filter->zone);
        self::assertSame('2026-07-01', $filter->month->format('Y-m-d'));
        self::assertTrue($filter->isNarrowed());
        self::assertSame(
            ['type' => 'foot', 'station' => 'North post', 'zone' => 'Open water', 'month' => '2026-07'],
            $filter->toQuery(),
        );
    }

    public function testAnEmptyValueIsNoFilterAtAll(): void
    {
        $filter = PatrolFilter::fromRequest(new Request(['type' => '', 'station' => ' ', 'zone' => '']), $this->now);

        self::assertNull($filter->type);
        self::assertNull($filter->station);
        self::assertNull($filter->zone);
    }

    public function testAMonthThatDoesNotParseWidensToTheMonthContainingNow(): void
    {
        $filter = PatrolFilter::fromRequest(new Request(['month' => 'last-tuesday']), $this->now);

        self::assertSame('2026-08-01', $filter->month->format('Y-m-d'));
    }

    /**
     * A query bag holding an ARRAY where a word belongs is a bad request, and
     * `InputBag::getString()` says so — the framework answers 400 rather than
     * letting an array reach a comparison.
     *
     * @see vendor/symfony/http-foundation/InputBag.php
     */
    public function testAnArrayWhereAWordBelongsIsARefusedRequest(): void
    {
        $this->expectException(BadRequestException::class);

        PatrolFilter::fromRequest(new Request(['type' => ['foot', 'boat']]), $this->now);
    }

    public function testTheWindowIsTheChosenMonthHalfOpen(): void
    {
        [$from, $until] = PatrolFilter::fromRequest(new Request(['month' => '2026-02']), $this->now)->window();

        self::assertSame('2026-02-01 00:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 00:00:00', $until->format('Y-m-d H:i:s'));
    }

    /**
     * What every chip links to: the same question narrowed on ONE axis, with
     * the month and the other axes kept — a chip that silently re-pinned the
     * month would be a different question than the one clicked.
     */
    public function testAChipNarrowsOneAxisAndKeepsTheRest(): void
    {
        $filter = PatrolFilter::fromRequest(new Request(['station' => 'North post', 'month' => '2026-07']), $this->now);

        self::assertSame(
            ['type' => 'boat', 'station' => 'North post', 'month' => '2026-07'],
            $filter->onlyType('boat')->toQuery(),
        );
        self::assertSame(
            ['station' => 'North post', 'zone' => 'Open water', 'month' => '2026-07'],
            $filter->onlyZone('Open water')->toQuery(),
        );
        self::assertSame(['month' => '2026-07'], $filter->withoutStation()->toQuery());
    }

    public function testTheMonthOptionKeepsEveryOtherChoice(): void
    {
        $filter = PatrolFilter::fromRequest(new Request(['type' => 'foot']), $this->now);

        self::assertSame(
            ['type' => 'foot', 'month' => '2026-06'],
            $filter->inMonth(new \DateTimeImmutable('2026-06-14'))->toQuery(),
        );
    }

    /**
     * THE PREDICATE THE WHOLE SCREEN IS NARROWED BY — one place, so the map, the
     * log and the charts cannot disagree about which patrols are in view.
     */
    public function testThePredicateNarrowsOnEveryAxisAtOnce(): void
    {
        $filter = PatrolFilter::fromRequest(
            new Request(['type' => 'foot', 'station' => 'North post', 'zone' => 'Open water']),
            $this->now,
        );

        self::assertTrue($filter->matches('foot', 'North post', 'Open water'));
        self::assertFalse($filter->matches('boat', 'North post', 'Open water'));
        self::assertFalse($filter->matches('foot', 'South landing', 'Open water'));
        self::assertFalse($filter->matches('foot', 'North post', ''));
    }

    public function testAFilterThatNarrowsNothingMatchesEverything(): void
    {
        $filter = PatrolFilter::fromRequest(new Request(), $this->now);

        self::assertTrue($filter->matches('foot', '', ''));
        self::assertTrue($filter->matches('boat', 'North post', 'Open water'));
    }
}
