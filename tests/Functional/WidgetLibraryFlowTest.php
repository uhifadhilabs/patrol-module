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
use UhifadhiLabs\Patrol\Entity\Patrol;
use UhifadhiLabs\Patrol\Entity\WidgetPreference;

/**
 * The widget library end to end: the editing surface where every widget is the
 * REAL widget at full size, and the preferences it writes — which the dashboard
 * then obeys. Preferences are per user, so every request here is signed in.
 */
final class WidgetLibraryFlowTest extends WebTestCase
{
    /** Every widget the module ships, in the design's own order. */
    private const array WIDGET_IDS = ['kpis', 'map', 'log', 'feed', 'chweek', 'chstation', 'cal'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private User $ranger;

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

        $this->ranger = new User()->setEmail('ranger@example.test')
            ->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($this->ranger);

        // One real patrol so the previews render live rows, not empty states.
        $this->em->persist(new Patrol($this->area, 'walk')
            ->setStation('North post')
            ->setLead($this->ranger)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 12:30'))
            ->setDistanceKm(14.2)
            ->setTrack('{"type":"LineString","coordinates":[[12.25,-5.75],[12.30,-5.70]]}'));

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

    public function testTheLibraryRendersEveryWidgetAsARealCardWithItsControls(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', $this->libraryUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', 'demo reserve — Patrols · widget library');

        // One card per widget, each carrying the design's chrome: a drag grip, a
        // state chip, the width chips and the add/remove toggle.
        $cards = $crawler->filter('[data-patrol-widget]');
        self::assertCount(\count(self::WIDGET_IDS), $cards);
        self::assertSame(
            self::WIDGET_IDS,
            $cards->each(static fn ($card) => (string) $card->attr('data-patrol-widget')),
        );
        self::assertCount(\count(self::WIDGET_IDS), $crawler->filter('[data-patrol-widget] [data-patrol-grip]'));
        self::assertCount(\count(self::WIDGET_IDS), $crawler->filter('[data-patrol-widget] [data-patrol-toggle]'));

        // The map card offers full width; the weekly chart does not.
        self::assertSame(
            ['12', '9', '6', '3'],
            $crawler->filter('[data-patrol-widget="map"] [data-patrol-span]')
                ->each(static fn ($chip) => (string) $chip->attr('data-patrol-span')),
        );
        self::assertSame(
            ['9', '6', '3'],
            $crawler->filter('[data-patrol-widget="chweek"] [data-patrol-span]')
                ->each(static fn ($chip) => (string) $chip->attr('data-patrol-span')),
        );

        // The preview IS the widget: the same partials the dashboard renders, with
        // live data — not a thumbnail.
        self::assertCount(1, $crawler->filter('[data-patrol-widget="kpis"] [data-kpi="month"]'));
        self::assertCount(1, $crawler->filter('[data-patrol-widget="log"] [data-patrol-log] tbody tr'));
        self::assertCount(42, $crawler->filter('[data-patrol-widget="cal"] .patrol-dc'));
        self::assertStringContainsString('North post', (string) $crawler->filter('[data-patrol-widget="chstation"]')->text());
    }

    public function testTheDashboardLinksToTheLibrary(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request('GET', $this->dashboardUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(
            $this->libraryUrl(),
            $crawler->filter('a:contains("Customize widgets")')->attr('href'),
        );
    }

    public function testSavingPreferencesPersistsAndTheDashboardObeysThem(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => ['cal', 'kpis', 'chweek'],
            'widgets' => [
                'cal' => ['on' => true, 'cols' => 6],
                'map' => ['on' => false, 'cols' => 12],
                'chweek' => ['on' => true, 'cols' => 3],
            ],
        ]);

        self::assertResponseStatusCodeSame(204);

        $stored = $this->em->getRepository(WidgetPreference::class)->findOneBy([
            'areaUuid' => $this->area->getUuid(),
            'userId' => $this->ranger->getId(),
        ]);
        self::assertInstanceOf(WidgetPreference::class, $stored);
        $order = $stored->getPrefs()['order'];
        self::assertIsArray($order);
        self::assertSame(['cal', 'kpis', 'chweek'], \array_slice($order, 0, 3));

        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertResponseIsSuccessful();

        // Chosen order first, the hidden widget gone, the chosen spans applied.
        $rendered = $crawler->filter('.patrol-wgrid > [data-w]')
            ->each(static fn ($widget) => (string) $widget->attr('data-w'));
        self::assertSame(['cal', 'kpis', 'chweek', 'log', 'feed', 'chstation'], $rendered);
        self::assertCount(0, $crawler->filter('[data-w="map"]'));
        self::assertStringContainsString('patrol-w6', (string) $crawler->filter('[data-w="cal"]')->attr('class'));
        self::assertStringContainsString('patrol-w3', (string) $crawler->filter('[data-w="chweek"]')->attr('class'));

        // The library shows the same state back: the removed widget reads "not
        // shown" and its toggle offers to add it again.
        $crawler = $this->client->request('GET', $this->libraryUrl());
        self::assertSelectorTextContains('[data-patrol-widget="map"] [data-patrol-state]', 'not shown');
        self::assertSelectorTextContains('[data-patrol-widget="map"] [data-patrol-toggle]', 'Add to dashboard');
        self::assertSame(
            'on',
            $crawler->filter('[data-patrol-widget="cal"] [data-patrol-span="6"]')->attr('data-patrol-chosen'),
        );
    }

    public function testResetRestoresTheDesignDefaults(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => ['cal'],
            'widgets' => ['map' => ['on' => false, 'cols' => 12]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', $this->libraryUrl().'/reset');
        self::assertResponseStatusCodeSame(204);

        self::assertCount(0, $this->em->getRepository(WidgetPreference::class)->findAll());

        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertSame(
            self::WIDGET_IDS,
            $crawler->filter('.patrol-wgrid > [data-w]')->each(static fn ($w) => (string) $w->attr('data-w')),
        );
    }

    public function testAnUnknownWidgetIdIsRefused(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => [],
            'widgets' => ['tracker' => ['on' => true, 'cols' => 6]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(WidgetPreference::class)->findAll());
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $this->client->loginUser($this->ranger);
        $this->client->request(
            'POST',
            $this->libraryUrl(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'not json at all',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testPreferencesAreNotReadableWithoutASignedInUser(): void
    {
        $this->client->request('GET', $this->libraryUrl());

        // A layout belongs to a person, so the controller refuses; the firewall
        // turns that refusal for an anonymous visitor into "authenticate first".
        self::assertResponseStatusCodeSame(401);
    }

    public function testOnePersonsLayoutIsNotAnothers(): void
    {
        $other = new User()->setEmail('other@example.test')->setFirstName('Ben')->setLastName('Beta');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => [],
            'widgets' => ['map' => ['on' => false, 'cols' => 12]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertCount(1, $crawler->filter('[data-w="map"]'));
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): void
    {
        $this->client->request(
            'POST',
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function dashboardUrl(): string
    {
        return '/areas/'.$this->area->getUuid()->toRfc4122().'/modules/patrols';
    }

    private function libraryUrl(): string
    {
        return $this->dashboardUrl().'/widgets';
    }
}
