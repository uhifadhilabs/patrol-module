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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\ObservationPhoto;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Team\Entity\User;

/**
 * The observation detail screen: the location plate (this observation's point
 * plus the parent track as context), the meta rows, the verbatim note, the one
 * derivable history entry and the deferred photos card — plus the nesting rule
 * (an observation reached through another patrol, or a patrol reached through
 * another area, is a 404).
 */
final class ObservationDetailPageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $patrol;
    private Patrol $otherPatrol;
    private Patrol $lonePatrol;
    private Observation $observation;
    private Observation $firstObservation;
    private Observation $loneObservation;

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

        $recorder = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($recorder);

        $this->patrol = new Patrol($this->area, 'walk')
            ->setStation('North post')
            ->setLead($recorder)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setSource(PatrolSourceEnum::Gpx)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70],[12.35,-5.68]]}');
        $this->em->persist($this->patrol);

        // Two observations, so the meta row can honestly say "2 of 2".
        $this->firstObservation = new Observation($this->patrol, 'maintenance')
            ->setNote('First note.')
            ->setLoggedAt(new \DateTimeImmutable('today 06:48'));
        $this->em->persist($this->firstObservation);
        $this->observation = new Observation($this->patrol, 'maintenance')
            ->setNote('Fence line down over twenty metres; livestock crossing.')
            ->setPosition('{"type":"Point","coordinates":[12.28,-5.72]}')
            ->setLoggedAt(new \DateTimeImmutable('today 08:15'))
            ->setRecordedBy($recorder);
        $this->em->persist($this->observation);

        // A patrol with exactly ONE observation: nothing to circle, so no arrows.
        $this->lonePatrol = new Patrol($this->area, 'walk')
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('today 05:00'));
        $this->em->persist($this->lonePatrol);
        $this->loneObservation = new Observation($this->lonePatrol, 'maintenance')
            ->setNote('The only note.')
            ->setLoggedAt(new \DateTimeImmutable('today 05:20'));
        $this->em->persist($this->loneObservation);

        $this->otherPatrol = new Patrol($this->area, 'boat')
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('today 07:20'));
        $this->em->persist($this->otherPatrol);

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

    public function testTheObservationDetailRendersThePlateMetaNoteAndHistory(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('h1.pg', 'Observation 2 — Maintenance need');
        $subtitle = $crawler->filter('.pgsub')->text();
        self::assertStringContainsString('maintenance need', $subtitle);
        self::assertStringContainsString($this->patrol->getRef().' walking round patrol', $subtitle);
        self::assertStringContainsString('A. Alpha', $subtitle);
        // The File-as-incident button exists only when a host installs an
        // incidents module exposing `incident_new` (the seam is the route name
        // + prefill query keys). This kernel has none, so the honest page shows
        // no dead control — the design's graceful absence.
        self::assertStringNotContainsString('File as incident', $crawler->filter('.pghead')->text());

        // Back to the parent patrol, and the crumb ends at "obs 2".
        self::assertStringContainsString('Patrol '.$this->patrol->getRef(), $crawler->filter('.backbtn')->text());
        self::assertStringContainsString('obs 2', $crawler->filter('.crumb')->text());

        // PL·01 — the plate payload carries this observation's point AND the
        // parent track, which the controller draws faded for context.
        $plate = $crawler->filter('[data-controller="uhifadhi--patrol-module--track-plate"]');
        self::assertCount(1, $plate);
        $payload = json_decode(
            (string) $plate->attr('data-uhifadhi--patrol-module--track-plate-payload-value'),
            true,
        );
        self::assertIsArray($payload);
        self::assertIsString($payload['track'] ?? null);
        self::assertStringContainsString('LineString', json_encode($payload['track'], \JSON_THROW_ON_ERROR));
        // The area outline travels with the plate here too.
        self::assertIsString($payload['boundary'] ?? null);
        self::assertStringContainsString('MultiPolygon', $payload['boundary']);
        $ring = $payload['observation'] ?? null;
        self::assertIsArray($ring);
        self::assertSame(2, $ring['n'] ?? null);
        self::assertStringContainsString('Point', json_encode($ring, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('obs 2 · maintenance need · 08:15', $crawler->filter('.patrol-ol-id')->text());

        // PL·02 — meta rows, with the position printed as DMS
        // (5.72° = 5°43'12", 12.28° = 12°16'48").
        $meta = $crawler->filter('[data-patrol-observation-meta]')->text();
        self::assertStringContainsString('2 of 2 · '.$this->patrol->getRef(), $meta);
        self::assertStringContainsString('5°43\'12"S 12°16\'48"E', $meta);
        self::assertStringContainsString('A. Alpha', $meta);
        self::assertStringContainsString(
            strtolower(new \DateTimeImmutable('today 08:15')->format('D j M')).' · 08:15',
            $meta,
        );

        // PL·03 — the note, verbatim and quoted.
        self::assertStringContainsString(
            'Fence line down over twenty metres; livestock crossing.',
            $crawler->filter('[data-patrol-note] .patrol-quote')->text(),
        );

        // PL·04 — the single derivable history entry, with the recorder.
        $history = $crawler->filter('[data-patrol-history] .rln');
        self::assertCount(1, $history);
        self::assertStringContainsString('observation logged en route by A. Alpha', $history->text());

        // PL·05 — an observation with no photographs draws no tiles and no
        // placeholder images, and never an upload control (view-only by ruling).
        $photos = $crawler->filter('[data-patrol-photos]');
        self::assertCount(1, $photos);
        self::assertStringContainsString('Photos', $photos->text());
        self::assertCount(0, $photos->filter('img'));
        self::assertCount(0, $photos->filter('input'));
        // …and the meta row says none rather than staying silent.
        self::assertStringContainsString('photos', $meta);
    }

    /**
     * The photographs the phone synced, on the page — thumbnails through the
     * evidence route, each one a trigger for the shared file preview.
     */
    public function testThePhotosCardDrawsTheObservationsPhotographs(): void
    {
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c1'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c1.jpg',
        )
            ->setMimeType('image/jpeg')
            ->setThumbKey('patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c1.jpg.thumb.jpg')
            ->setTakenAt(new \DateTimeImmutable('today 08:15'));
        $this->em->persist($photo);
        // A second photograph with NO preview — the HEIC case. It must still
        // draw, falling back to the original rather than a broken image.
        $withoutThumb = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c2'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c2.heic',
        )->setMimeType('image/heic');
        $this->em->persist($withoutThumb);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-patrol-photos]');
        $tiles = $card->filter('.patrol-ph');
        self::assertCount(2, $tiles);

        // The tile draws the PREVIEW and carries the ORIGINAL for the overlay to
        // offer — both through storage-module's authenticated route, which is
        // the only way bytes leave this platform.
        $first = $tiles->eq(0);
        self::assertSame(
            '/storage/evidence/'.$photo->getThumbKey(),
            $first->filter('img')->attr('src'),
        );
        self::assertSame(
            '/storage/evidence/'.$photo->getStoragePath(),
            $first->attr('data-f-original'),
        );
        // No document-root path anywhere: nothing under /var, nothing guessable.
        self::assertStringNotContainsString('var/patrol', (string) $this->client->getResponse()->getContent());

        // The photograph that could not be previewed falls back to itself.
        self::assertSame(
            '/storage/evidence/'.$withoutThumb->getStoragePath(),
            $tiles->eq(1)->filter('img')->attr('src'),
        );

        // The count agrees with reality, in all three places the design prints it.
        self::assertStringContainsString('· 2 · from the field', $card->text());
        self::assertStringContainsString('2 photos', $crawler->filter('.pgsub')->text());
        self::assertStringContainsString('photos', $crawler->filter('[data-patrol-observation-meta]')->text());
        self::assertStringContainsString('2', $crawler->filter('[data-patrol-observation-meta]')->text());

        // Still view-only: no upload control appeared with the photographs.
        self::assertCount(0, $card->filter('input'));
    }

    /**
     * A PHOTOGRAPH OPENS WHERE EVERY PHOTOGRAPH ON THIS PLATFORM OPENS.
     *
     * The tile is not a link to the raw bytes any more: it is a trigger for
     * storage-module's file preview, the same component the Files hub opens its
     * own tiles in. This module owns none of that markup — it includes the
     * partial and fills the contract — so what is asserted here is exactly the
     * seam: the shell is on the page, and every tile speaks the contract.
     * → @UhifadhiStorage/overlay/_preview.html.twig
     */
    public function testAPhotographOpensInTheSharedFilePreview(): void
    {
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000d1'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000d1.jpg',
        )
            ->setMimeType('image/jpeg')
            ->setByteSize(2_411_724)
            ->setThumbKey('patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000d1.jpg.thumb.jpg')
            ->setTakenAt(new \DateTimeImmutable('2026-08-04 09:12'));
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();

        // The component's own shell, included once, with the behaviour it ships.
        $overlay = $crawler->filter('.f-ov[data-f-overlay]');
        self::assertCount(1, $overlay, 'the page includes the storage bundle’s preview, and does not draw one of its own');
        self::assertSame('uhifadhi--storage-module--preview', $overlay->attr('data-controller'));
        // THE DIGEST IS NOT ASSERTED, only the sheet. AssetMapper
        // content-versions a bundle's public/ files, so the href is
        // `/assets/bundles/uhifadhistorage/preview-<digest>.css` and pinning the
        // whole path would make this test fail every time that bundle edits its
        // stylesheet — which is a release note, not a defect here.
        self::assertMatchesRegularExpression(
            '#bundles/uhifadhistorage/preview(-[A-Za-z0-9_-]+)?\.css#',
            (string) $this->client->getResponse()->getContent(),
            'consuming the component means loading its vocabulary too',
        );

        $tile = $crawler->filter('[data-patrol-photos] .patrol-ph');
        self::assertCount(1, $tile);
        self::assertNotNull($tile->attr('data-f-preview'), 'the tile is a trigger');
        self::assertCount(
            0,
            $crawler->filter('a.patrol-ph'),
            'clicking a photograph opens the preview; it no longer walks off the page to the raw bytes',
        );

        // Everything the overlay shows travels in the attributes, so opening one
        // costs no request.
        self::assertSame('image/jpeg', $tile->attr('data-f-mime'));
        self::assertSame('2.4 MB', $tile->attr('data-f-size'));
        self::assertSame('made', $tile->attr('data-f-thumb'));
        self::assertSame('/storage/evidence/'.$photo->getThumbKey(), $tile->attr('data-f-img'));
        self::assertSame('/storage/evidence/'.$photo->getStoragePath(), $tile->attr('data-f-original'));
        self::assertStringContainsString('09:12', (string) $tile->attr('data-f-taken'));

        // THE OWNER IS THE FILE'S IDENTITY. A photograph belongs to an
        // observation in the Patrols module, and the preview says so.
        self::assertSame('patrols', $tile->attr('data-f-mod'));
        self::assertSame('Patrols', $tile->attr('data-f-modlabel'));
        self::assertSame($this->observation->getRef(), $tile->attr('data-f-rec'));
        self::assertSame(
            $this->url($this->area, $this->patrol, $this->observation),
            $tile->attr('data-f-rechref'),
        );

        // The file's own page belongs to the Files hub, which a host running
        // this module need not have. Nothing here promises one.
        self::assertSame('', $tile->attr('data-f-detail'));
    }

    /**
     * The phone promised more than arrived. The page says so — a count that
     * silently shows the smaller number would be a claim that nothing is missing.
     */
    public function testAnObservationStillSyncingSaysWhatHasNotArrived(): void
    {
        $this->observation->setPhotoCount(3);
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c3'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c3.jpg',
        );
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();
        $meta = $crawler->filter('[data-patrol-observation-meta]')->text();
        self::assertStringContainsString('1 of 3', $meta);
        self::assertStringContainsString('2 still syncing', $meta);
    }

    /** The parent patrol's observation rows carry the same honest count. */
    public function testThePatrolDetailRowCountsTheObservationsPhotographs(): void
    {
        $photo = new ObservationPhoto(
            $this->observation,
            Uuid::fromString('e77c0000-0000-4000-8000-0000000000c4'),
            'patrol/'.$this->patrol->getUuid()->toRfc4122().'/e77c0000-0000-4000-8000-0000000000c4.jpg',
        );
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request(
            'GET',
            '/areas/'.$this->area->getUuidString().'/modules/patrols/'.$this->patrol->getUuid()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-patrol-observations] .patrol-obs-r');
        self::assertCount(2, $rows);
        // The second observation holds the photograph; the first holds none and
        // therefore says nothing rather than "0 photos".
        self::assertStringContainsString('1 photo', $rows->eq(1)->filter('em')->text());
        self::assertStringNotContainsString('photo', $rows->eq(0)->filter('em')->text());
    }

    public function testTheArrowsCircleToTheNeighbouringObservations(): void
    {
        // The LAST of two: next wraps round to the first, prev walks back to it
        // as well — a two-observation patrol is a ring of two.
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();
        $nav = $crawler->filter('.pgact .patrol-obsnav');
        self::assertCount(1, $nav);
        self::assertStringContainsString('2 / 2', $nav->text());

        $first = $this->url($this->area, $this->patrol, $this->firstObservation);
        self::assertSame($first, $nav->filter('a[rel="prev"]')->attr('href'));
        self::assertSame($first, $nav->filter('a[rel="next"]')->attr('href'));
    }

    public function testTheArrowsWrapAtBothEnds(): void
    {
        // The FIRST of two: prev wraps backwards to the last one.
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->firstObservation));

        self::assertResponseIsSuccessful();
        $nav = $crawler->filter('.pgact .patrol-obsnav');
        self::assertStringContainsString('1 / 2', $nav->text());

        $last = $this->url($this->area, $this->patrol, $this->observation);
        self::assertSame($last, $nav->filter('a[rel="prev"]')->attr('href'));
        self::assertSame($last, $nav->filter('a[rel="next"]')->attr('href'));
        // The arrows say where they go, for anyone not reading the chevrons.
        self::assertStringContainsString(
            'Previous observation: 2 of 2',
            (string) $nav->filter('a[rel="prev"]')->attr('aria-label'),
        );
    }

    public function testAPatrolWithASingleObservationOffersNoArrows(): void
    {
        $crawler = $this->client->request(
            'GET',
            $this->url($this->area, $this->lonePatrol, $this->loneObservation),
        );

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.patrol-obsnav'));
    }

    public function testThePlatePayloadCarriesEveryObservationWithExactlyOneCurrent(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->area, $this->patrol, $this->observation));

        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $crawler
                ->filter('[data-controller="uhifadhi--patrol-module--track-plate"]')
                ->attr('data-uhifadhi--patrol-module--track-plate-payload-value'),
            true,
        );
        self::assertIsArray($payload);

        // Every sibling travels with the plate, in the SAME order the arrows
        // walk, so a ring can be clicked as well as arrowed to.
        $siblings = $payload['observations'] ?? null;
        self::assertIsArray($siblings);
        self::assertCount(2, $siblings);
        self::assertSame([1, 2], array_column($siblings, 'n'));
        self::assertSame(
            [
                $this->url($this->area, $this->patrol, $this->firstObservation),
                $this->url($this->area, $this->patrol, $this->observation),
            ],
            array_column($siblings, 'url'),
        );
        // Exactly one is the one being viewed.
        self::assertSame([false, true], array_column($siblings, 'current'));
        // The sibling with no recorded position says so rather than inventing one.
        $sibling = $siblings[0];
        self::assertIsArray($sibling);
        self::assertArrayHasKey('position', $sibling);
        self::assertNull($sibling['position']);

        $current = $siblings[1];
        self::assertIsArray($current);
        self::assertIsString($current['position'] ?? null);
        self::assertStringContainsString('Point', $current['position']);
        self::assertSame('Maintenance need', $current['category'] ?? null);
    }

    public function testAnObservationReachedThroughAnotherPatrolIsNotFound(): void
    {
        $this->client->request('GET', $this->url($this->area, $this->otherPatrol, $this->observation));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnObservationReachedThroughAnotherAreaIsNotFound(): void
    {
        $this->client->request('GET', $this->url($this->otherArea, $this->patrol, $this->observation));

        self::assertResponseStatusCodeSame(404);
    }

    private function url(AreaOfInterest $area, Patrol $patrol, Observation $observation): string
    {
        return '/areas/'.$area->getUuidString()
            .'/modules/patrols/'.$patrol->getUuid()->toRfc4122()
            .'/observations/'.$observation->getUuid()->toRfc4122();
    }
}
