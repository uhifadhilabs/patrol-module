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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * The GPX import screen end to end. Creating patrols is the privilege, so access
 * is the first thing under test — the real AuthorizationChecker through a real
 * firewall, with the host's voter played by FixedRecordVoter. Then the screen
 * itself (the upload frame), the parse step (what the file actually contained,
 * gaps included), the confirm step (persisted from the file, never from the
 * form) and the refusal of a file that is not a track.
 */
final class ImportFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private User $recorder;
    private User $staff;

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
            '{"type":"MultiPolygon","coordinates":[[[[-30.1,-1.1],[-29.9,-1.1],[-29.9,-0.9],[-30.1,-0.9],[-30.1,-1.1]]]]}',
        );
        $this->em->persist($this->area);

        $this->recorder = new User()->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->staff = new User()->setEmail('staff@example.test')
            ->setFirstName('Sam')->setLastName('Staff');
        $this->em->persist($this->recorder);
        $this->em->persist($this->staff);
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

    public function testTheScreenIsDeniedWithoutTheRecordPermission(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', $this->importUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousRequestIsNotServedTheScreen(): void
    {
        $this->client->request('GET', $this->importUrl());

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testImportingIsDeniedWithoutTheRecordPermission(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('POST', $this->importUrl(), ['type' => 'walk'], ['gpx' => $this->fixtureUpload()]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->patrolCount());
    }

    public function testARecorderSeesTheUploadStepAndTheDeploymentsVocabulary(): void
    {
        $this->client->loginUser($this->recorder);
        $crawler = $this->client->request('GET', $this->importUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.patrol-dropz', 'Drop a .gpx track here');
        self::assertCount(1, $crawler->filter('[data-patrol-upload] input[type=file][name=gpx]'));
        self::assertSelectorTextContains('.patrol-dropz', 'one ingest service, two doors');

        // The type chips are the deployment's patrol.types, as a real radio
        // group with the first one already chosen.
        $chips = $crawler->filter('[data-patrol-details] .patrol-chip-radio input');
        self::assertCount(2, $chips);
        self::assertSame('walk', $chips->eq(0)->attr('value'));
        self::assertNotNull($chips->eq(0)->attr('checked'));

        // Lead is a relation, so it is chosen from the deployment's people.
        self::assertGreaterThan(0, $crawler->filter('[data-patrol-details] select[name=lead] option')->count());

        // Nothing is parsed yet, so the preview card does not exist.
        self::assertCount(0, $crawler->filter('[data-patrol-preview]'));
    }

    public function testAnUploadedTrackIsPreviewedBeforeAnythingIsSaved(): void
    {
        $this->client->loginUser($this->recorder);
        $crawler = $this->client->request(
            'POST',
            $this->importUrl(),
            ['type' => 'walk', 'station' => 'North post'],
            ['gpx' => $this->fixtureUpload()],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->patrolCount(), 'A preview must never persist anything.');

        // PL·03 — what the file actually contained: four points, its recorded
        // span, and the ONE silence longer than the 5-minute threshold.
        $preview = $crawler->filter('[data-patrol-preview]');
        self::assertCount(1, $preview);
        self::assertStringContainsString('4 points', $preview->filter('.patrol-ol-id')->text());
        self::assertStringContainsString('06:00–06:25', $preview->filter('.patrol-ol-id')->text());
        self::assertStringContainsString('valid gpx', $preview->text());
        self::assertStringContainsString('1 gap > 5 min', $preview->filter('.patrol-legend')->text());
        self::assertStringContainsString('short_track.gpx', $crawler->filter('.patrol-filechip')->text());
        self::assertStringContainsString('parsed', $crawler->filter('.patrol-filechip')->text());

        // The XML survives to the confirm step without a second upload.
        self::assertCount(1, $crawler->filter('input[type=hidden][name=gpxData]'));
    }

    public function testConfirmingPersistsThePatrolFromTheFileAndOpensIt(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request(
            'POST',
            $this->importUrl(),
            [
                'type' => 'walk',
                'station' => 'North post',
                'lead' => (string) $this->recorder->getId(),
                'team' => 'B. Beta',
                'note' => 'ridge circuit',
                'gpxData' => base64_encode($this->fixtureXml()),
                'confirm' => '1',
            ],
        );

        $patrol = $this->onlyPatrol();
        self::assertResponseRedirects(
            '/areas/'.$this->area->getUuid()->toRfc4122().'/modules/patrols/'.$patrol->getUuid()->toRfc4122(),
        );

        // Time, distance and route come from the FILE; the form contributed
        // only what a file cannot know.
        self::assertSame(PatrolSourceEnum::Gpx, $patrol->getSource());
        self::assertSame(4, $patrol->getPointCount());
        self::assertSame(1, $patrol->getGapCount());
        self::assertNotNull($patrol->getDistanceKm());
        self::assertEqualsWithDelta(0.529, $patrol->getDistanceKm(), 0.01);
        self::assertSame('2026-03-01 06:00', $patrol->getStartedAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-03-01 06:25', $patrol->getEndedAt()?->format('Y-m-d H:i'));
        self::assertSame('North post', $patrol->getStation());
        self::assertSame('B. Beta', $patrol->getTeam());
        self::assertSame($this->recorder->getId(), $patrol->getLead()?->getId());
        self::assertStringContainsString('LineString', (string) $patrol->getTrack());

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-patrol-flash]', 'imported from the GPX track');
    }

    public function testAFileThatIsNotATrackIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $crawler = $this->client->request(
            'POST',
            $this->importUrl(),
            ['type' => 'walk'],
            ['gpx' => new UploadedFile(
                $this->tempFile('<not-gpx>this is not a track</not-gpx>'),
                'notes.gpx',
                'application/gpx+xml',
                null,
                true,
            )],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->patrolCount());
        self::assertStringContainsString('invalid gpx', $crawler->filter('.patrol-filechip')->text());
        self::assertSelectorTextContains('[data-patrol-error]', 'no track points');
        self::assertCount(0, $crawler->filter('[data-patrol-preview]'));
    }

    private function importUrl(): string
    {
        return '/areas/'.$this->area->getUuid()->toRfc4122().'/modules/patrols/import';
    }

    private function fixtureXml(): string
    {
        return (string) file_get_contents(\dirname(__DIR__).'/Fixtures/gpx/short_track.gpx');
    }

    private function fixtureUpload(): UploadedFile
    {
        return new UploadedFile(
            $this->tempFile($this->fixtureXml()),
            'short_track.gpx',
            'application/gpx+xml',
            null,
            true,
        );
    }

    /** UploadedFile moves/reads the path it is given; the fixture itself stays put. */
    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'patrol-gpx-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }

    private function patrolCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(Patrol::class)->findAll());
    }

    private function onlyPatrol(): Patrol
    {
        $this->em->clear();
        $patrols = $this->em->getRepository(Patrol::class)->findAll();
        self::assertCount(1, $patrols);

        return $patrols[0];
    }
}
