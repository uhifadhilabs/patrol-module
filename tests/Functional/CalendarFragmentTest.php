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

namespace Uhifadhi\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Team\Entity\User;

/**
 * The calendar's month fragment (PL·11 ‹ ›): one month of REAL day cells served
 * on its own, the month walk unbounded in both directions, an empty month still
 * a full grid, and a month the endpoint cannot read rejected rather than guessed.
 */
final class CalendarFragmentTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    /**
     * A month safely in the past: "today" then falls outside it whenever the
     * suite runs, so the fixtures never drift with the clock.
     */
    private const string MONTH = '2019-08';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $firstDay;
    private Patrol $lastDay;
    private Patrol $manual;
    private Patrol $trailing;
    private Patrol $far;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->otherArea = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[10.2,-5.8],[10.5,-5.8],[10.5,-5.5],[10.2,-5.5],[10.2,-5.8]]]]}',
        );
        $this->em->persist($this->otherArea);

        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($lead);

        // The month's BOUNDARIES: its very first and very last day.
        $this->firstDay = new Patrol($this->area, 'walk')
            ->setStation('North post')
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('2019-08-01 06:10'))
            ->setEndedAt(new \DateTimeImmutable('2019-08-01 09:40'))
            ->setDistanceKm(14.2);
        $this->em->persist($this->firstDay);
        $this->em->persist(new Observation($this->firstDay, 'maintenance'));

        $this->lastDay = new Patrol($this->area, 'boat')
            ->setStation('South landing')
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('2019-08-31 18:30'))
            ->setEndedAt(new \DateTimeImmutable('2019-08-31 20:00'))
            ->setDistanceKm(58.3);
        $this->em->persist($this->lastDay);

        // A HAND-LOGGED patrol (no GPX, no track): it is an ordinary calendar
        // item — the month is patrol effort, not GPX files.
        $this->manual = new Patrol($this->area, 'walk')
            ->setSource(PatrolSourceEnum::Manual)
            ->setStation('North post')
            ->setStartedAt(new \DateTimeImmutable('2019-08-14 07:00'))
            ->setEndedAt(new \DateTimeImmutable('2019-08-14 11:15'));
        $this->em->persist($this->manual);

        // September's first days FALL INSIDE August's grid, in the dimmed
        // trailing cells the design draws — they belong there.
        $this->trailing = new Patrol($this->area, 'walk')
            ->setStartedAt(new \DateTimeImmutable('2019-09-03 06:00'));
        $this->em->persist($this->trailing);

        // A month the grid cannot reach at all, and another area's patrol in the
        // same month — neither may appear in this area's August grid.
        $this->far = new Patrol($this->area, 'walk')
            ->setStartedAt(new \DateTimeImmutable('2019-10-05 06:00'));
        $this->em->persist($this->far);
        $this->em->persist(new Patrol($this->otherArea, 'walk')
            ->setStartedAt(new \DateTimeImmutable('2019-08-14 06:00')));

        $this->em->flush();

        $this->everyAreaRunsPatrols($this->em);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    private function url(?string $month = self::MONTH, ?AreaOfInterest $area = null): string
    {
        $area ??= $this->area;

        return '/areas/'.$area->getUuidString().'/modules/patrols/calendar'
            .(null === $month ? '' : '?month='.$month);
    }

    public function testTheFragmentIsOneMonthOfRealDayCells(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();

        // The fragment is the BODY only — no page chrome around it.
        self::assertSelectorNotExists('h1.pg');
        self::assertStringNotContainsString('PL&middot;11', (string) $this->client->getResponse()->getContent());

        // The grid: 42 Monday-start cells. August 2019 opens on Jul 29.
        self::assertCount(42, $crawler->filter('.patrol-dc'));
        self::assertSame('august 2019', $crawler->filter('.patrol-calmonth')->attr('data-patrol-cal-label'));
        self::assertSame('2019-08', $crawler->filter('.patrol-calmonth')->attr('data-patrol-cal-month'));
        // Three leading days of July and eight trailing days of September, drawn
        // dimmed — 31 days of August in between.
        self::assertCount(3 + 8, $crawler->filter('.patrol-dc.patrol-out'));
        // Not the month that holds today: nothing is ringed.
        self::assertCount(0, $crawler->filter('.patrol-dc.patrol-today'));

        // The two boundary patrols, the hand-logged one, and September's — which
        // the grid genuinely shows, in a dimmed trailing cell. NOT the other
        // area's patrol, and not a month the grid cannot reach.
        self::assertCount(4, $crawler->filter('.patrol-daypill'));
        $refs = $crawler->filter('.patrol-daypill .chip')->each(static fn ($node): string => trim($node->text()));
        self::assertContains($this->firstDay->getRef(), $refs);
        self::assertContains($this->lastDay->getRef(), $refs);
        self::assertContains($this->manual->getRef(), $refs);
        self::assertContains($this->trailing->getRef(), $refs);
        self::assertNotContains($this->far->getRef(), $refs);
        self::assertCount(1, $crawler->filter('.patrol-dc.patrol-out .patrol-daypill'));
    }

    public function testEveryCalendarItemOpensThatPatrol(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        $expected = '/areas/'.$this->area->getUuidString()
            .'/modules/patrols/'.$this->firstDay->getUuid()->toRfc4122();
        $hrefs = $crawler->filter('a.patrol-daypill')->each(static fn ($node): string => (string) $node->attr('href'));

        self::assertCount(4, $hrefs);
        self::assertContains($expected, $hrefs);
    }

    public function testEachItemCarriesItsHoverCard(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        self::assertCount(4, $crawler->filter('.patrol-daypill .patrol-pop'));

        $card = $crawler->filter('a.patrol-daypill[href$="'.$this->firstDay->getUuid()->toRfc4122().'"] .patrol-pop');
        $text = (string) $card->text();
        // Who was out, the type by its label, the start, distance, duration and
        // the observation count — the design's own vocabulary, lowercase.
        self::assertStringContainsString('A. Alpha', $text);
        self::assertStringContainsString('North post', $text);
        self::assertStringContainsString('walking round', $text);
        self::assertStringContainsString('06:10', $text);
        self::assertStringContainsString('14.2 km', $text);
        self::assertStringContainsString('3 h 30', $text);
        self::assertStringContainsString('observations', $text);
        self::assertStringContainsString('Open', $text);

        // The type colour comes from the ONE server-side palette
        // (PatrolDashboardService::typeColors) — never a second one in CSS or JS.
        self::assertStringContainsString(
            '--patrol-track: #3ED9A8',
            (string) $card->filter('.patrol-tchip')->attr('style'),
        );

        // A hand-logged patrol has no lead: the card falls back to its station
        // rather than showing an empty line.
        $manualCard = $crawler->filter('a.patrol-daypill[href$="'.$this->manual->getUuid()->toRfc4122().'"] .patrol-pop');
        self::assertStringContainsString('North post', (string) $manualCard->text());
    }

    public function testTheNavStepsToTheNeighbouringMonths(): void
    {
        $crawler = $this->client->request('GET', $this->url());

        $months = $crawler->filter('.patrol-calnav button')->each(
            static fn ($node): string => (string) $node->attr('data-patrol-cal-goto'),
        );
        self::assertSame(['2019-07', '2019-09'], $months);
        self::assertSame('august 2019', trim($crawler->filter('.patrol-calnav .mchip.on')->text()));
    }

    public function testAMonthWithNoPatrolsIsStillAFullGrid(): void
    {
        $crawler = $this->client->request('GET', $this->url('2031-11'));

        self::assertResponseIsSuccessful();
        self::assertCount(42, $crawler->filter('.patrol-dc'));
        self::assertCount(0, $crawler->filter('.patrol-daypill'));
        self::assertSame('november 2031', $crawler->filter('.patrol-calmonth')->attr('data-patrol-cal-label'));
    }

    public function testTheWalkIsUnboundedInBothDirections(): void
    {
        foreach (['1998-01', '2099-12'] as $month) {
            $this->client->request('GET', $this->url($month));
            self::assertResponseIsSuccessful();
        }
    }

    public function testWithoutAMonthTheFragmentIsTheCurrentOne(): void
    {
        $crawler = $this->client->request('GET', $this->url(null));

        self::assertResponseIsSuccessful();
        self::assertSame(
            new \DateTimeImmutable()->format('Y-m'),
            $crawler->filter('.patrol-calmonth')->attr('data-patrol-cal-month'),
        );
        self::assertCount(1, $crawler->filter('.patrol-dc.patrol-today'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedMonths(): iterable
    {
        yield 'not a month at all' => ['august'];
        yield 'month out of range' => ['2019-13'];
        yield 'month zero' => ['2019-00'];
        yield 'a full date' => ['2019-08-14'];
        yield 'short year' => ['26-08'];
        yield 'injection attempt' => ['2026-08%22%3E%3Cscript%3E'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedMonths')]
    public function testAMalformedMonthIsRejected(string $month): void
    {
        $this->client->request('GET', $this->url($month));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The widget on the DASHBOARD is the same partial, already rendered for the
     * current month: the page is complete before any JavaScript runs, and the
     * nav it ships points at this endpoint.
     */
    public function testTheDashboardShipsTheCurrentMonthWiredToThisEndpoint(): void
    {
        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/patrols');

        self::assertResponseIsSuccessful();

        $widget = $crawler->filter('[data-patrol-calendar]');
        self::assertCount(1, $widget);
        self::assertSame(
            'uhifadhi--patrol-module--calendar',
            $widget->attr('data-controller'),
        );
        self::assertSame(
            '/areas/'.$this->area->getUuidString().'/modules/patrols/calendar',
            $widget->attr('data-uhifadhi--patrol-module--calendar-url-value'),
        );

        // It opens on the current month, and both ‹ › are wired to step from it.
        $current = new \DateTimeImmutable()->modify('first day of this month');
        self::assertSame($current->format('Y-m'), $widget->filter('.patrol-calmonth')->attr('data-patrol-cal-month'));
        self::assertSame(
            [$current->modify('-1 month')->format('Y-m'), $current->modify('+1 month')->format('Y-m')],
            $widget->filter('.patrol-calnav button')->each(
                static fn ($node): string => (string) $node->attr('data-patrol-cal-goto'),
            ),
        );
        self::assertStringContainsString(
            'uhifadhi--patrol-module--calendar#go',
            (string) $widget->filter('.patrol-calnav button')->first()->attr('data-action'),
        );
        // The card's caption is a target, so it follows a swapped-in month.
        self::assertCount(1, $widget->filter('.tab [data-uhifadhi--patrol-module--calendar-target="label"]'));
    }

    public function testAnUnknownAreaIsNotFound(): void
    {
        $this->client->request(
            'GET',
            '/areas/'.new \Symfony\Component\Uid\UuidV4()->toRfc4122().'/modules/patrols/calendar?month='.self::MONTH,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherAreaSeesOnlyItsOwnPatrols(): void
    {
        $crawler = $this->client->request('GET', $this->url(area: $this->otherArea));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.patrol-daypill'));
    }
}
