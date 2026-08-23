<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Enum\PatrolSourceEnum;
use UhifadhiLabs\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * Logging a patrol by hand: the same permission gate as the import screen, the
 * happy path (a MANUAL patrol with no track — a hand-entered record must never
 * impersonate a recorded one) and the two rules the form enforces before it
 * saves anything.
 */
final class LogFlowTest extends WebTestCase
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
        $this->client->request('GET', $this->logUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testLoggingIsDeniedWithoutTheRecordPermission(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('POST', $this->logUrl(), $this->validSubmission());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->patrolCount());
    }

    public function testAnAnonymousRequestIsNotServedTheScreen(): void
    {
        $this->client->request('GET', $this->logUrl());

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheFormShowsTheDeferredSectionsWithoutPretendingTheyWork(): void
    {
        $this->client->loginUser($this->recorder);
        $crawler = $this->client->request('GET', $this->logUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-patrol-log] input[name=startedAt][type=datetime-local]'));
        self::assertCount(1, $crawler->filter('[data-patrol-log] input[name=endedAt][type=datetime-local]'));
        // The observations repeater and the route sketch are drawn but inert.
        self::assertStringContainsString('+ add observation', $crawler->filter('[data-patrol-log] .patrol-inert')->text());
        self::assertStringContainsString('Sketch, don', $crawler->filter('[data-patrol-sketch] .use')->text());
    }

    public function testALoggedPatrolIsStoredAsManualAndOpensAfterSaving(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request('POST', $this->logUrl(), $this->validSubmission());

        $patrol = $this->onlyPatrol();
        self::assertResponseRedirects(
            '/areas/'.$this->area->getUuid()->toRfc4122().'/modules/patrols/'.$patrol->getUuid()->toRfc4122(),
        );

        // A hand-entered record carries no track and says so.
        self::assertSame(PatrolSourceEnum::Manual, $patrol->getSource());
        self::assertNull($patrol->getTrack());
        self::assertSame('boat', $patrol->getType());
        self::assertSame('North post', $patrol->getStation());
        self::assertSame($this->recorder->getId(), $patrol->getLead()?->getId());
        self::assertSame('B. Beta, C. Gamma', $patrol->getTeam());
        self::assertSame('2026-08-22 05:55', $patrol->getStartedAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-08-22 11:35', $patrol->getEndedAt()?->format('Y-m-d H:i'));
        self::assertSame(12.8, $patrol->getDistanceKm());

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-patrol-flash]', 'logged');
    }

    public function testAPatrolWithoutATypeIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request('POST', $this->logUrl(), ['type' => 'unlisted', 'startedAt' => '2026-08-22T05:55']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->patrolCount());
        self::assertSelectorTextContains('[data-patrol-error]', 'Choose a patrol type');
    }

    public function testAPatrolWithoutAStartIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request('POST', $this->logUrl(), ['type' => 'walk', 'startedAt' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->patrolCount());
        self::assertSelectorTextContains('[data-patrol-error]', 'time it started');
    }

    public function testAPatrolThatEndsBeforeItStartedIsRefused(): void
    {
        $this->client->loginUser($this->recorder);
        $this->client->request('POST', $this->logUrl(), [
            'type' => 'walk',
            'startedAt' => '2026-08-22T11:35',
            'endedAt' => '2026-08-22T05:55',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->patrolCount());
        self::assertSelectorTextContains('[data-patrol-error]', 'cannot end before it started');
    }

    /** @return array<string, string> */
    private function validSubmission(): array
    {
        return [
            'type' => 'boat',
            'station' => 'North post',
            'lead' => (string) $this->recorder->getId(),
            'team' => 'B. Beta, C. Gamma',
            'startedAt' => '2026-08-22T05:55',
            'endedAt' => '2026-08-22T11:35',
            'distanceKm' => '12.8',
            'note' => 'lake shore round',
        ];
    }

    private function logUrl(): string
    {
        return '/areas/'.$this->area->getUuid()->toRfc4122().'/modules/patrols/log';
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
