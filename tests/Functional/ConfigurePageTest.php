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
use Uhifadhi\Patrol\Service\PatrolSettingsService;
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

        $this->em->persist(new Patrol($this->area, 'walk')
            ->setStation('North post')
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

        // The stations card reads back the stations patrols have named, because
        // a station is still free text rather than a record this area keeps.
        self::assertStringContainsString('North post', self::card($crawler, 'Stations')->text());
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
