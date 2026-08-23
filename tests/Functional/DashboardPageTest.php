<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Access\Entity\User;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;

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
        // sum, and the deferred coverage plate rendered as an em dash.
        self::assertSelectorTextContains('[data-kpi="month"] .kpi b', '3');
        self::assertSelectorTextContains('[data-kpi="month"] .kpi span', '2 walking round');
        self::assertSelectorTextContains('[data-kpi="distance"] .kpi b', '85');
        self::assertSelectorTextContains('[data-kpi="coverage"] .kpi b', '—');
        self::assertSelectorTextContains('[data-kpi="last"] .kpi span', $this->boat->getRef());

        // Filter chips: one per configured type, each with its live count.
        self::assertStringContainsString('walking round · 2', (string) $crawler->filter('[data-w="map"]')->text());
        self::assertStringContainsString('boat · 1', (string) $crawler->filter('[data-w="map"]')->text());

        // Patrol log: one row per patrol, with the ref, the explicit lowercase
        // start ("sat 22 aug · 06:10"), the observation chip and Open →.
        self::assertCount(3, $crawler->filter('[data-patrol-log] tbody tr'));
        $row = $crawler->filter('[data-patrol="'.$this->walkWithObservations->getUuid()->toRfc4122().'"]');
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
        $maps = $crawler->filter('[data-controller="uhifadhilabs--patrol-module--coverage-map"]');
        self::assertCount(2, $maps);
        $payload = json_decode(
            (string) $maps->first()->attr('data-uhifadhilabs--patrol-module--coverage-map-payload-value'),
            true,
        );
        self::assertIsArray($payload);
        self::assertIsString($payload['boundary'] ?? null);
        self::assertStringContainsString('MultiPolygon', $payload['boundary']);
        self::assertIsArray($payload['patrols'] ?? null);
        // Only the two patrols that actually recorded a track are drawn.
        self::assertCount(2, $payload['patrols']);
        self::assertStringContainsString('LineString', json_encode($payload['patrols'], \JSON_THROW_ON_ERROR));
    }
}
