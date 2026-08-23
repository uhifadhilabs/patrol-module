<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Access\Entity\User;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use UhifadhiLabs\PatrolBundle\Entity\Observation;
use UhifadhiLabs\PatrolBundle\Entity\Patrol;
use UhifadhiLabs\PatrolBundle\Enum\PatrolSourceEnum;

/**
 * The observation detail screen: the location plate (this observation's point
 * plus the parent track as context), the meta rows, the verbatim note, the one
 * derivable history entry and the deferred photos card — plus the nesting rule
 * (an observation reached through another patrol, or a patrol reached through
 * another area, is a 404).
 */
final class ObservationDetailPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private AreaOfInterest $otherArea;
    private Patrol $patrol;
    private Patrol $otherPatrol;
    private Observation $observation;

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

        $recorder = new User()->setEmail('lead@example.test')->setFirstName('Ada')->setLastName('Alpha');
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
        $this->em->persist(
            new Observation($this->patrol, 'maintenance')
                ->setNote('First note.')
                ->setLoggedAt(new \DateTimeImmutable('today 06:48')),
        );
        $this->observation = new Observation($this->patrol, 'maintenance')
            ->setNote('Fence line down over twenty metres; livestock crossing.')
            ->setPosition('{"type":"Point","coordinates":[12.28,-5.72]}')
            ->setLoggedAt(new \DateTimeImmutable('today 08:15'))
            ->setRecordedBy($recorder);
        $this->em->persist($this->observation);

        $this->otherPatrol = new Patrol($this->area, 'boat')
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('today 07:20'));
        $this->em->persist($this->otherPatrol);

        $this->em->flush();
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
        self::assertStringContainsString('File as incident', $crawler->filter('.pghead')->text());

        // Back to the parent patrol, and the crumb ends at "obs 2".
        self::assertStringContainsString('Patrol '.$this->patrol->getRef(), $crawler->filter('.backbtn')->text());
        self::assertStringContainsString('obs 2', $crawler->filter('.crumb')->text());

        // PL·01 — the plate payload carries this observation's point AND the
        // parent track, which the controller draws faded for context.
        $plate = $crawler->filter('[data-controller="uhifadhilabs--patrol-bundle--track-plate"]');
        self::assertCount(1, $plate);
        $payload = json_decode(
            (string) $plate->attr('data-uhifadhilabs--patrol-bundle--track-plate-payload-value'),
            true,
        );
        self::assertIsArray($payload);
        self::assertIsString($payload['track'] ?? null);
        self::assertStringContainsString('LineString', json_encode($payload['track'], \JSON_THROW_ON_ERROR));
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

        // PL·05 — photos are deferred: the card keeps its place, with no
        // placeholder images and no upload control.
        $photos = $crawler->filter('[data-patrol-photos]');
        self::assertCount(1, $photos);
        self::assertStringContainsString('Photos', $photos->text());
        self::assertCount(0, $photos->filter('img'));
        self::assertCount(0, $photos->filter('input'));
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
        return '/areas/'.$area->getUuid()->toRfc4122()
            .'/modules/patrols/'.$patrol->getUuid()->toRfc4122()
            .'/observations/'.$observation->getUuid()->toRfc4122();
    }
}
