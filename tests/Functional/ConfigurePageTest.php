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
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Service\PatrolSettingsService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;

/**
 * ONE CONFIGURE BUTTON, ONE CONFIGURE PAGE — the shell's page over this
 * module's declared sections, and the one POST behind its Settings body.
 */
final class ConfigurePageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;

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

        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 09:10')));

        $this->everyAreaRunsPatrols($this->em);
    }

    private function configureUrl(?string $section = null): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/patrols/configure'
            .(null === $section ? '' : '/'.$section);
    }

    /** One card of the section body, found by the word in its caption. */
    private static function card(Crawler $crawler, string $heading): Crawler
    {
        return $crawler->filter('.c')->reduce(
            static fn (Crawler $card): bool => str_starts_with(trim($card->filter('.tab')->text('')), $heading),
        );
    }

    private function signInAsManager(): void
    {
        $manager = new User()->setPassword('x')->setEmail(FixedRecordVoter::MANAGER_EMAIL)
            ->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->client->loginUser($manager);
    }

    /**
     * THE BARE ADDRESS IS THE SURFACE'S SETTINGS, by the platform's ruled
     * order — Widget library first, Settings last.
     */
    public function testTheConfigurePageOpensOnSettingsAndNamesThisModule(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('demo reserve — Patrols · configure', $crawler->filter('h1.pg')->text());
        self::assertSame(
            ['Widget library', 'Observation kinds', 'Settings'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Settings', trim($crawler->filter('.atabs a.on')->text()));
    }

    /** The section body is the module's, and it is a body: no head, no strip of its own. */
    public function testTheSettingsBodyDrawsTheDesignsFourCards(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl());

        self::assertSame(
            ['Patrol types', 'Observation categories', 'Stations', 'Thresholds'],
            $crawler->filter('.c > .tab')->each(
                static fn (Crawler $t): string => trim(str_replace((string) $t->filter('.src')->text(''), '', $t->text())),
            ),
        );

        self::assertStringContainsString('North post', self::card($crawler, 'Stations')->text());
    }

    /**
     * SET·01 AND SET·03 ARE ROWS WITH COUNTS AND ACTIONS, as the design draws
     * them: the label, the wire key, how many patrols are filed under it, and
     * the two buttons.
     */
    public function testTheTwoWordListsDrawARowPerWordWithItsCountAndItsActions(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl());

        $stations = self::card($crawler, 'Stations');
        $row = $stations->filter('.srow')->first();
        self::assertSame('North post', trim($row->filter('.nm')->text()));
        self::assertSame('north-post', trim($row->filter('.cd')->text()));
        self::assertSame('1 patrol', trim($row->filter('.n')->text()));
        self::assertSame(
            ['Rename', 'Retire'],
            $row->filter('.acts .sact')->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertSame('+ New station', trim($stations->filter('.sadd')->text()));

        // This area already had a type before the page was opened (the fixture
        // patrol's), so the installation's seed does NOT reach back into it —
        // which is the whole of what "seed a NEW area only" means.
        $types = self::card($crawler, 'Patrol types');
        self::assertSame(
            ['Walking round'],
            $types->filter('.srow .nm')->each(static fn (Crawler $n): string => trim($n->text())),
        );
        self::assertSame('+ New patrol type', trim($types->filter('.sadd')->text()));

        // The not-built notes are gone from both.
        self::assertStringNotContainsString('not built yet', $stations->text());
        self::assertStringNotContainsString('not built yet', $types->text());
    }

    /** A new station, then renamed, then retired — and never deleted. */
    public function testAStationIsAddedRenamedAndRetiredWithoutLosingAPatrol(): void
    {
        $this->signInAsManager();
        $token = $this->token();

        $this->client->request('POST', $this->configureUrl('settings/stations'), ['_token' => $token, 'label' => 'Ridge Camp']);
        self::assertResponseRedirects($this->configureUrl());

        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);

        $this->client->request('POST', $this->configureUrl('settings/stations/'.$station->getUuid()->toRfc4122().'/rename'), [
            '_token' => $token,
            'label' => 'Lake Post',
        ]);
        $this->em->clear();
        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);
        self::assertSame('Lake Post', $station->getLabel());
        self::assertSame('ridge-camp', $station->getKey(), 'A rename never touches the wire value.');

        $this->client->request('POST', $this->configureUrl('settings/stations/'.$station->getUuid()->toRfc4122().'/retire'), ['_token' => $token]);
        $this->em->clear();
        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);
        self::assertFalse($station->isActive());

        // Dimmed and pilled on the page, never gone.
        $crawler = $this->client->request('GET', $this->configureUrl());
        $retired = self::card($crawler, 'Stations')->filter('.srow.gone');
        self::assertSame('Lake Post', trim($retired->filter('.nm')->text()));
        self::assertSame('retired', trim($retired->filter('.chip.idle')->text()));
        self::assertSame(
            ['Rename', 'Reactivate'],
            $retired->filter('.acts .sact')->each(static fn (Crawler $b): string => trim($b->text())),
        );
    }

    /** A patrol type is added, and the row says nobody has used it yet. */
    public function testANewPatrolTypeStartsWithNoPatrolsFiledUnderIt(): void
    {
        $this->signInAsManager();

        $this->client->request('POST', $this->configureUrl('settings/types'), ['_token' => $this->token(), 'label' => 'Drone sortie']);

        $crawler = $this->client->request('GET', $this->configureUrl());
        $rows = self::card($crawler, 'Patrol types')->filter('.srow');
        $last = $rows->eq($rows->count() - 1);
        self::assertSame('Drone sortie', trim($last->filter('.nm')->text()));
        self::assertSame('drone-sortie', trim($last->filter('.cd')->text()));
        self::assertSame('0 patrols', trim($last->filter('.n')->text()));
    }

    /** Two words the same is refused rather than quietly made twice. */
    public function testASecondStationWithTheSameNameIsRefused(): void
    {
        $this->signInAsManager();

        $this->client->request('POST', $this->configureUrl('settings/stations'), ['_token' => $this->token(), 'label' => 'north POST']);

        self::assertResponseRedirects($this->configureUrl());
        self::assertCount(1, $this->stations()->findByArea($this->area));
    }

    /** Editing the words rides on the same authority the numbers do. */
    public function testSomebodyWhoMayNotManageCannotAddAStation(): void
    {
        $recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($recorder);
        $this->em->flush();
        $this->client->loginUser($recorder);

        $this->client->request('POST', $this->configureUrl('settings/stations'), ['label' => 'Ridge Camp']);

        self::assertResponseStatusCodeSame(403);
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', $this->configureUrl());

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function stations(): StationRepository
    {
        $repository = $this->em->getRepository(Station::class);
        self::assertInstanceOf(StationRepository::class, $repository);

        return $repository;
    }

    /** Until an area saves, it runs on the installation's numbers. */
    public function testAnAreaThatHasNeverSavedShowsTheInstallationsNumbers(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl());

        self::assertStringContainsString('the installation', self::card($crawler, 'Thresholds')->text());
    }

    /** One POST, and the area runs on its own numbers from then on. */
    public function testSavingTheSettingsWritesTheAreasOwnNumbers(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl());
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->configureUrl('settings'), [
            '_token' => $token,
            'gap_threshold_minutes' => '12',
            'discard_retention_days' => '30',
        ]);

        self::assertResponseRedirects($this->configureUrl());

        $crawler = $this->client->request('GET', $this->configureUrl());
        self::assertSame('12', $crawler->filter('input[name="gap_threshold_minutes"]')->attr('value'));
        self::assertSame('30', $crawler->filter('input[name="discard_retention_days"]')->attr('value'));
        self::assertStringContainsString('this area’s own', self::card($crawler, 'Thresholds')->text());
    }

    /** A form is not a security boundary: a hand-posted number is clamped. */
    public function testAPostedNumberOutsideTheDesignsBoundsIsClamped(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl());
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->configureUrl('settings'), [
            '_token' => $token,
            'gap_threshold_minutes' => '99999',
            'discard_retention_days' => '0',
        ]);

        $crawler = $this->client->request('GET', $this->configureUrl());
        self::assertSame(
            (string) PatrolSettingsService::MAX_GAP_MINUTES,
            $crawler->filter('input[name="gap_threshold_minutes"]')->attr('value'),
        );
        self::assertSame(
            (string) PatrolSettingsService::MIN_RETENTION_DAYS,
            $crawler->filter('input[name="discard_retention_days"]')->attr('value'),
        );
    }

    /** Changing what an area runs on rides on `patrols.manage`. */
    public function testSomebodyWhoMayNotManageCannotSaveTheSettings(): void
    {
        $recorder = new User()->setPassword('x')->setEmail(FixedRecordVoter::RECORDER_EMAIL)
            ->setFirstName('Rita')->setLastName('Recorder');
        $this->em->persist($recorder);
        $this->em->flush();
        $this->client->loginUser($recorder);

        $this->client->request('POST', $this->configureUrl('settings'), [
            'gap_threshold_minutes' => '12',
            'discard_retention_days' => '30',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /** The word "register" is nowhere on the configure page either. */
    public function testNothingOnTheConfigurePageSaysRegister(): void
    {
        $this->signInAsManager();
        $this->client->request('GET', $this->configureUrl());

        self::assertStringNotContainsStringIgnoringCase(
            'register',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
