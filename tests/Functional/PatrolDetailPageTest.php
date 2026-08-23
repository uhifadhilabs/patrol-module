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

namespace UhifadhiLabs\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;

/**
 * The patrol detail screen: the track plate and its payload, the meta rows,
 * the single derivable history entry and the numbered observation rows — plus
 * the area-nesting rule (a patrol reached through the wrong area is a 404).
 */
final class PatrolDetailPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $patrol;
    private Patrol $manualPatrol;
    private Observation $firstObservation;

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

        $this->otherArea = new AreaOfInterest()->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[10.2,-5.8],[10.5,-5.8],[10.5,-5.5],[10.2,-5.5],[10.2,-5.8]]]]}',
        );
        $this->em->persist($this->otherArea);

        $lead = new User()->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($lead);

        // A GPX-born patrol: a recorded track, honesty metadata, a roster and
        // two positioned observations.
        $this->patrol = new Patrol($this->area, 'walk')
            ->setStation('North post')
            ->setLead($lead)
            ->setTeam('B. Beta · C. Gamma')
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setDistanceKm(14.2)
            ->setSource(PatrolSourceEnum::Gpx)
            ->setPointCount(1482)
            ->setGapCount(2)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70],[12.35,-5.68]]}');
        $this->em->persist($this->patrol);

        $this->firstObservation = new Observation($this->patrol, 'maintenance')
            ->setNote('Culvert washed out on the ridge track.')
            ->setPosition('{"type":"Point","coordinates":[12.28,-5.72]}')
            ->setLoggedAt(new \DateTimeImmutable('today 06:48'))
            ->setRecordedBy($lead);
        $this->em->persist($this->firstObservation);

        // A category the deployment did NOT configure: the row falls back to
        // the stored key rather than rendering blank.
        $this->em->persist(
            new Observation($this->patrol, 'unlisted')
                ->setNote('Second note.')
                ->setLoggedAt(new \DateTimeImmutable('today 08:15')),
        );

        // A hand-logged patrol with no track and no observations: no Export GPX
        // action, no gps-points row, and the empty observations state.
        $this->manualPatrol = new Patrol($this->area, 'boat')
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('today 07:20'));
        $this->em->persist($this->manualPatrol);

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

    public function testThePatrolDetailRendersThePlateMetaHistoryAndObservations(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol));

        self::assertResponseIsSuccessful();

        // Header: "Patrol P-0001 — North post", with the subtitle assembled
        // from what this patrol actually knows.
        self::assertSelectorTextContains('h1.pg', 'Patrol '.$this->patrol->getRef().' — North post');
        $subtitle = $crawler->filter('.pgsub')->text();
        self::assertStringContainsString('walking round patrol', $subtitle);
        self::assertStringContainsString('A. Alpha', $subtitle);
        self::assertStringContainsString('14.2 km', $subtitle);
        self::assertStringContainsString('2 observations', $subtitle);

        // Export GPX is offered only for a recorded track.
        self::assertStringContainsString('Export GPX', $crawler->filter('.pghead')->text());

        // PL·01 — the plate carries the Stimulus controller and a payload with
        // the track plus the positioned observations as numbered rings.
        $plate = $crawler->filter('[data-controller="uhifadhilabs--patrol-module--track-plate"]');
        self::assertCount(1, $plate);
        $payload = json_decode(
            (string) $plate->attr('data-uhifadhilabs--patrol-module--track-plate-payload-value'),
            true,
        );
        self::assertIsArray($payload);
        self::assertIsString($payload['track'] ?? null);
        self::assertStringContainsString('LineString', $payload['track']);
        $rings = $payload['observations'] ?? null;
        self::assertIsArray($rings);
        // Only the observation that recorded a position can be drawn.
        self::assertCount(1, $rings);
        $ring = $rings[0];
        self::assertIsArray($ring);
        self::assertSame(1, $ring['n'] ?? null);
        // The deployment's WORD for the category, never the stored key: the ring
        // tooltip reads like the chip under it.
        self::assertSame('Maintenance need', $ring['category'] ?? null);
        self::assertStringContainsString('Point', json_encode($ring, \JSON_THROW_ON_ERROR));
        // A ring is a way into its observation page.
        self::assertIsString($ring['url'] ?? null);
        self::assertStringContainsString('/observations/', $ring['url']);
        // The plate draws the area outline the track is read against, and the
        // track wears this patrol type's one colour.
        self::assertIsString($payload['boundary'] ?? null);
        self::assertStringContainsString('MultiPolygon', $payload['boundary']);
        self::assertIsString($payload['color'] ?? null);
        // The controls are mounted by the host's platform chrome module, not
        // rendered here; the plate ships the frame they mount into.
        self::assertCount(1, $crawler->filter('.patrol-viewer .patrol-canvas'));
        self::assertCount(0, $crawler->filter('.patrol-zoomui'));
        self::assertStringContainsString($this->patrol->getRef().' · North post · walking round', $crawler->filter('.patrol-ol-id')->text());

        // PL·02 — meta rows, including the computed duration and average speed
        // (14.2 km over 6 h 20 = 2.24… km/h) and the GPS honesty row.
        $meta = $crawler->filter('[data-patrol-meta]')->text();
        self::assertStringContainsString('6 h 20', $meta);
        self::assertStringContainsString('2.2 km/h', $meta);
        self::assertStringContainsString('GPX · imported', $meta);
        self::assertStringContainsString('1,482 · 2 gaps', $meta);
        self::assertStringContainsString('B. Beta · C. Gamma', $meta);
        self::assertStringContainsString(
            strtolower(new \DateTimeImmutable('today 06:10')->format('D j M')).' · 06:10',
            $meta,
        );

        // PL·03 — the one entry derivable without an audit trail.
        $history = $crawler->filter('[data-patrol-history] .rln');
        self::assertCount(1, $history);
        self::assertStringContainsString('track imported from GPX', $history->text());

        // PL·04 — one row per observation, numbered from 1, with the category
        // label (or the raw key when unconfigured) and DMS coordinates.
        $rows = $crawler->filter('[data-patrol-observations] .patrol-obs-r');
        self::assertCount(2, $rows);
        self::assertSame('1', trim($rows->eq(0)->filter('.patrol-obs-n')->text()));
        self::assertStringContainsString('maintenance need', $rows->eq(0)->text());
        self::assertStringContainsString('Culvert washed out on the ridge track.', $rows->eq(0)->text());
        // 5.72° = 5°43'12" and 12.28° = 12°16'48".
        self::assertStringContainsString('5°43\'12"S 12°16\'48"E', $rows->eq(0)->text());
        self::assertStringContainsString('unlisted', $rows->eq(1)->text());
        self::assertSame(
            $this->url($this->area, $this->patrol).'/observations/'.$this->firstObservation->getUuid()->toRfc4122(),
            $rows->eq(0)->filter('a.open-btn')->attr('href'),
        );
    }

    public function testAHandLoggedPatrolOffersNoExportAndStatesItsEmptyObservations(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->manualPatrol));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Export GPX', $crawler->filter('.pghead')->text());
        self::assertStringContainsString('manual entry', $crawler->filter('[data-patrol-meta]')->text());
        self::assertStringNotContainsString('gps points', $crawler->filter('[data-patrol-meta]')->text());
        self::assertStringContainsString('logged manually', $crawler->filter('[data-patrol-history]')->text());
        self::assertCount(1, $crawler->filter('[data-patrol-observations] .patrol-obs-empty'));
    }

    public function testAPatrolReachedThroughAnotherAreaIsNotFound(): void
    {
        $this->client->request('GET', $this->url($this->otherArea, $this->patrol));

        self::assertResponseStatusCodeSame(404);
    }

    private function url(AreaOfInterest $area, Patrol $patrol): string
    {
        return '/areas/'.$area->getUuid()->toRfc4122().'/modules/patrols/'.$patrol->getUuid()->toRfc4122();
    }
}
