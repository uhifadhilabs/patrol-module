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
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Controller\PatrolWidgetsController;
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
        self::assertCount(1, $crawler->filter('[data-patrol-widget="log"] [data-patrol-log] tbody tr[data-patrol]'));
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

        $this->postReset();
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
        $token = $this->csrfToken();
        $this->client->request(
            'POST',
            $this->libraryUrl(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ],
            content: 'not json at all',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testSavingWithoutACsrfTokenIsRefused(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => ['cal'],
            'widgets' => ['map' => ['on' => false, 'cols' => 12]],
        ], token: '');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(WidgetPreference::class)->findAll());
    }

    public function testSavingWithAnotherAreasCsrfTokenIsRefused(): void
    {
        $other = new AreaOfInterest()->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[1.0,1.0],[1.2,1.0],[1.2,1.2],[1.0,1.2],[1.0,1.0]]]]}',
        );
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($this->ranger);
        // The token is scoped per area, so one area's library cannot rearrange
        // another's.
        $crawler = $this->client->request('GET', '/areas/'.$other->getUuid()->toRfc4122().'/modules/patrols/widgets');
        $otherToken = (string) $crawler->filter('[data-patrol-widgets]')->attr('data-patrol-csrf-token');

        $this->postJson($this->libraryUrl(), [
            'order' => ['cal'],
            'widgets' => [],
        ], token: $otherToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testResettingWithoutACsrfTokenIsRefused(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => ['cal'],
            'widgets' => ['map' => ['on' => false, 'cols' => 12]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->postReset('');

        self::assertResponseStatusCodeSame(403);
        // The layout survived the refused reset.
        self::assertCount(1, $this->em->getRepository(WidgetPreference::class)->findAll());
    }

    public function testTheLibraryCardsCarryTheChosenSpanSoTheyComposeLikeTheDashboard(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => ['chweek', 'chstation', 'map'],
            'widgets' => [
                'chweek' => ['on' => true, 'cols' => 6],
                'chstation' => ['on' => true, 'cols' => 6],
                'map' => ['on' => true, 'cols' => 9],
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        $crawler = $this->client->request('GET', $this->libraryUrl());
        $dashboard = $this->client->request('GET', $this->dashboardUrl());

        // The CARD carries the span — patrol.css lays .patrol-lib out on the same
        // twelve columns as the dashboard, so the two half-width charts sit side
        // by side in the library exactly as they do on the dashboard.
        $librarySpans = [];
        $libraryCards = $crawler->filter('.patrol-lib > [data-patrol-widget]');
        $librarySpans = array_combine(
            $libraryCards->each(static fn (Crawler $card) => (string) $card->attr('data-patrol-widget')),
            $libraryCards->each(static fn (Crawler $card) => (string) $card->attr('data-patrol-cols')),
        );
        self::assertSame('6', $librarySpans['chweek']);
        self::assertSame('6', $librarySpans['chstation']);
        self::assertSame('9', $librarySpans['map']);

        // Same widths, same order, both screens — the library IS the dashboard
        // layout with editing chrome.
        $dashboardWidgets = $dashboard->filter('.patrol-wgrid > [data-w]');
        $dashboardSpans = array_combine(
            $dashboardWidgets->each(static fn (Crawler $widget) => (string) $widget->attr('data-w')),
            $dashboardWidgets->each(static fn (Crawler $widget) => (string) $widget->attr('class')),
        );
        self::assertStringContainsString('patrol-w6', $dashboardSpans['chweek']);
        self::assertStringContainsString('patrol-w6', $dashboardSpans['chstation']);
        self::assertStringContainsString('patrol-w9', $dashboardSpans['map']);
        self::assertSame(array_keys($librarySpans), array_keys($dashboardSpans));

        // The preview wrapper survives as the dim-when-off hook, but no longer
        // narrows anything — the card is the width now, so nothing shrinks twice.
        self::assertCount(
            \count(self::WIDGET_IDS),
            $crawler->filter('[data-patrol-widget] > [data-patrol-preview]'),
        );
        // The chrome is the preview's sibling, never inside it, so switching a
        // widget off dims the widget and not the control that switches it back.
        self::assertCount(0, $crawler->filter('[data-patrol-preview] [data-patrol-toggle]'));
    }

    public function testASwitchedOffWidgetStaysInTheLibraryAtItsSpan(): void
    {
        $this->client->loginUser($this->ranger);
        $this->postJson($this->libraryUrl(), [
            'order' => [],
            'widgets' => ['map' => ['on' => false, 'cols' => 6]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $crawler = $this->client->request('GET', $this->libraryUrl());

        // Gone from the dashboard, still here to be switched back on — in flow,
        // at its span, dimmed by patrol.css reading data-patrol-on.
        $card = $crawler->filter('[data-patrol-widget="map"]');
        self::assertCount(1, $card);
        self::assertSame('0', $card->attr('data-patrol-on'));
        self::assertSame('6', $card->attr('data-patrol-cols'));
        self::assertSame('Add to dashboard', trim((string) $card->filter('[data-patrol-toggle-label]')->text()));
        self::assertCount(0, $this->client->request('GET', $this->dashboardUrl())->filter('[data-w="map"]'));
    }

    public function testAPartialOrderKeepsEveryWidgetInItsDefaultPlace(): void
    {
        $this->client->loginUser($this->ranger);
        // A stale client — it knows only three of the seven widgets. The four it
        // never mentioned must survive, ranked after the ones it did, in their
        // catalogue order.
        $this->postJson($this->libraryUrl(), [
            'order' => ['cal', 'chstation'],
            'widgets' => ['cal' => ['on' => true, 'cols' => 12]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $crawler = $this->client->request('GET', $this->dashboardUrl());
        self::assertSame(
            ['cal', 'chstation', 'kpis', 'map', 'log', 'feed', 'chweek'],
            $crawler->filter('.patrol-wgrid > [data-w]')->each(static fn ($w) => (string) $w->attr('data-w')),
        );
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

    /**
     * The library's own save call: a JSON body plus the CSRF token the page
     * rendered. Pass $token explicitly to post a wrong one (or none).
     *
     * @param array<string, mixed> $payload
     */
    private function postJson(string $url, array $payload, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        $token ??= $this->csrfToken();
        if ('' !== $token) {
            $server['HTTP_'.str_replace('-', '_', strtoupper(PatrolWidgetsController::CSRF_HEADER))] = $token;
        }

        $this->client->request(
            'POST',
            $url,
            server: $server,
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** The reset call, which carries the same token as a save. */
    private function postReset(?string $token = null): void
    {
        $server = [];
        $token ??= $this->csrfToken();
        if ('' !== $token) {
            $server['HTTP_X_CSRF_TOKEN'] = $token;
        }

        $this->client->request('POST', $this->libraryUrl().'/reset', server: $server);
    }

    /** The token the library screen mints for this area, read the way a browser would. */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', $this->libraryUrl());

        return (string) $crawler->filter('[data-patrol-widgets]')->attr('data-patrol-csrf-token');
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
