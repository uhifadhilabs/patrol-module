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
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;

/**
 * The patrols widget dashboard: the KPI strip, the coverage map payload, the
 * filter chips, the patrol log, the feed, both charts and the month calendar —
 * all rendered from real rows, with the deployment's own type vocabulary
 * (TestKernel configures the synthetic "walk"/"boat" types).
 */
final class DashboardPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Patrol $walkWithObservations;
    private Patrol $boat;

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

        $this->area = new AreaOfInterest()->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $lead = new User()->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($lead);

        // Today's patrol: a recorded track and two en-route observations.
        $this->walkWithObservations = new Patrol($this->area, 'walk')
            ->setStation('North post')
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setDistanceKm(14.2)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70],[12.35,-5.68]]}');
        $this->em->persist($this->walkWithObservations);
        foreach (['maintenance', 'maintenance'] as $category) {
            $this->em->persist(new Observation($this->walkWithObservations, $category));
        }

        // A second walk, same day, so the type count is 2 and the calendar cell
        // carries two pills.
        $secondWalk = new Patrol($this->area, 'walk')
            ->setStation('North post')
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('today 05:55'))
            ->setEndedAt(new \DateTimeImmutable('today 11:35'))
            ->setDistanceKm(12.8);
        $this->em->persist($secondWalk);

        // A different type, a different station.
        $this->boat = new Patrol($this->area, 'boat')
            ->setStation('South landing')
            ->setLead($lead)
            ->setStartedAt(new \DateTimeImmutable('today 07:20'))
            ->setEndedAt(new \DateTimeImmutable('today 11:30'))
            ->setDistanceKm(58.3)
            ->setTrack('{"type":"LineString","coordinates":[[12.40,-5.60],[12.45,-5.58]]}');
        $this->em->persist($this->boat);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testTheDashboardRendersEveryWidgetFromRealRows(): void
    {
        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuid()->toRfc4122().'/modules/patrols');

        self::assertResponseIsSuccessful();

        // Page header: "<Area> — Patrols", per the design's title convention.
        self::assertSelectorTextContains('h1.pg', 'demo reserve — Patrols');

        // KPI strip: this month's count, its per-type breakdown, the distance
        // sum, and PL·03's coverage as a whole percent. The two recorded tracks
        // (~20 km between them) sweep a 2 km buffer over roughly a tenth of the
        // ~1 100 km² fixture square — the plate says 9 %.
        self::assertSelectorTextContains('[data-kpi="month"] .kpi b', '3');
        self::assertSelectorTextContains('[data-kpi="month"] .kpi span', '2 walking round');
        self::assertSelectorTextContains('[data-kpi="distance"] .kpi b', '85');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi b', '9%');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi span', 'of area within 2 km of a track');
        self::assertSelectorTextContains('[data-kpi="last"] .kpi span', $this->boat->getRef());

        // Filter chips: one per configured type, each with its live count.
        self::assertStringContainsString('walking round · 2', (string) $crawler->filter('[data-w="map"]')->text());
        self::assertStringContainsString('boat · 1', (string) $crawler->filter('[data-w="map"]')->text());

        // Patrol log: one row per patrol, with the ref, the explicit lowercase
        // start ("sat 22 aug · 06:10"), the observation chip and Open →.
        self::assertCount(3, $crawler->filter('[data-patrol-log] tbody tr[data-patrol]'));
        // Plus one row the rows controller reveals when a filter hides them all.
        self::assertCount(1, $crawler->filter('[data-patrol-log] tbody tr.patrol-hidden'));
        // Scoped to the log: the feed row (PL·07) carries the same identity, so
        // the coverage map can spotlight a track from either list.
        $row = $crawler->filter('[data-patrol-log] [data-patrol="'.$this->walkWithObservations->getUuid()->toRfc4122().'"]');
        self::assertCount(1, $row);
        self::assertStringContainsString($this->walkWithObservations->getRef(), $row->text());
        self::assertSame(
            strtolower(new \DateTimeImmutable('today 06:10')->format('D j M')).' · 06:10',
            trim($row->filter('[data-patrol-start]')->text()),
        );
        self::assertStringContainsString('2 obs', $row->text());
        self::assertStringContainsString('Open', $row->text());

        // Feed: the same patrols as rows with initials, station · lead and type.
        self::assertCount(3, $crawler->filter('[data-patrol-feed] .patrol-feed-row'));
        self::assertSelectorTextContains('[data-patrol-feed] .patrol-avatar', 'AA');

        // Charts: five week groups (two bars each, one per type) and one bar per
        // station, ranked.
        self::assertCount(5 * 2, $crawler->filter('[data-patrol-weekly] svg rect'));
        self::assertCount(2, $crawler->filter('[data-patrol-stations] svg rect'));
        self::assertStringContainsString('North post', (string) $crawler->filter('[data-patrol-stations]')->text());

        // Calendar: 42 cells, today ringed, a pill per patrol on its day.
        self::assertCount(42, $crawler->filter('[data-patrol-calendar] .patrol-dc'));
        self::assertCount(1, $crawler->filter('[data-patrol-calendar] .patrol-dc.patrol-today'));
        self::assertCount(3, $crawler->filter('[data-patrol-calendar] .patrol-daypill'));

        // Coverage map: both viewers carry the Stimulus controller, and the
        // payload holds the area boundary plus every track.
        $maps = $crawler->filter('[data-controller="uhifadhi--patrol-module--coverage-map"]');
        self::assertCount(2, $maps);
        $payload = json_decode(
            (string) $maps->first()->attr('data-uhifadhi--patrol-module--coverage-map-payload-value'),
            true,
        );
        self::assertIsArray($payload);
        self::assertIsString($payload['boundary'] ?? null);
        self::assertStringContainsString('MultiPolygon', $payload['boundary']);
        self::assertIsArray($payload['patrols'] ?? null);
        // Only the two patrols that actually recorded a track are drawn.
        self::assertCount(2, $payload['patrols']);
        self::assertStringContainsString('LineString', json_encode($payload['patrols'], \JSON_THROW_ON_ERROR));
        // Each drawn track knows which patrol it is (the map tooltip and the
        // row-hover spotlight both need it) and which colour to wear.
        foreach ($payload['patrols'] as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('ref', $entry);
            self::assertArrayHasKey('uuid', $entry);
            self::assertArrayHasKey('color', $entry);
        }

        // The map controls are NOT server-rendered: the host's platform chrome
        // module builds zoom, DIM, the base-layer menu and fullscreen into the
        // frame, so neither repo keeps a copy of that markup. What this page
        // must ship is the frame the chrome mounts into.
        self::assertCount(2, $crawler->filter('.patrol-viewer .patrol-canvas'));
        self::assertCount(0, $crawler->filter('.patrol-zoomui'));

        // The filter chips are real buttons carrying the type they select, so
        // one filter can drive the map AND the log.
        $chips = $crawler->filter('[data-w="map"] .patrol-chiprow button[data-patrol-type]');
        self::assertCount(3, $chips); // all + the two configured types
        self::assertSame('all', $chips->first()->attr('data-patrol-type'));

        // The feed rows (PL·07) carry the same identity as the log rows: the
        // design's "hover a row to highlight" works from either list.
        self::assertCount(3, $crawler->filter('[data-patrol-feed] [data-patrol][data-patrol-type]'));

        // Station markers: the design labels each station on the map. A station
        // has no coordinates of its own, so only stations whose patrols recorded
        // a track can be placed — and the rows state their station so the
        // station menu filters the list as well as the map.
        self::assertIsArray($payload['stations'] ?? null);
        self::assertSame(['South landing', 'North post'], array_column($payload['stations'], 'name'));
        self::assertCount(
            1,
            $crawler->filter('[data-patrol-log] .patrol-chiprow button[data-patrol-station="North post"]'),
        );
        self::assertCount(3, $crawler->filter('[data-patrol-log] tbody tr[data-patrol-station]'));
    }

    /**
     * PL·03 with nothing to measure: an area whose month holds only hand-logged
     * patrols has no geometry to buffer, so the plate shows the design's empty
     * state — an em dash and the same caption — never a false 0 %.
     */
    public function testTheCoverageKpiShowsTheEmptyStateWithoutARecordedTrack(): void
    {
        $bare = new AreaOfInterest()->setName('sketch reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($bare);
        $this->em->persist(new Patrol($bare, 'walk')
            ->setStation('North post')
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setDistanceKm(9.4));
        $this->em->flush();

        $this->client->request('GET', '/areas/'.$bare->getUuid()->toRfc4122().'/modules/patrols');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi b', '—');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi span', 'of area within 2 km of a track');
    }
}
