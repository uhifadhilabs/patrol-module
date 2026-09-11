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
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * THE THIRD DATA PLACE — every observation kind this area files under, and what
 * has been filed against each word. Read-only: the page lists, selects and
 * counts, and writes nothing.
 */
final class KindsOverviewPageTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

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

    public function testItListsEveryKindIncludingTheRetiredOnes(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Wildlife');
        $this->admin()->createKind($area, 'Carcass');
        $retired = $this->admin()->createKind($area, 'Fire Scar');
        $this->admin()->deactivateKind($retired);

        $crawler = $this->client->request('GET', $this->overviewUrl($area));

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.krow'));
        // A retired kind stays on the page, dimmed, at the bottom.
        self::assertCount(1, $crawler->filter('.krow.gone'));
        self::assertStringContainsString('Fire Scar', $crawler->filter('.krow.gone')->text());
    }

    public function testItOpensTheFirstKindByDefault(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Wildlife');
        $this->admin()->createKind($area, 'Carcass');

        $crawler = $this->client->request('GET', $this->overviewUrl($area));

        self::assertCount(1, $crawler->filter('.krow.on'));
        self::assertStringContainsString('Wildlife', $crawler->filter('.krow.on')->text());
        self::assertStringContainsString('all of Wildlife', $crawler->filter('.kmatrix')->text());
    }

    public function testTheKindInTheUrlIsTheOneThatOpens(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Wildlife');
        $carcass = $this->admin()->createKind($area, 'Carcass');

        $crawler = $this->client->request('GET', $this->overviewUrl($area).'/'.$carcass->getUuid()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Carcass', $crawler->filter('.krow.on')->text());
        self::assertStringContainsString('all of Carcass', $crawler->filter('.kmatrix')->text());
    }

    public function testTheCountsAreTheObservationsFiledUnderEachWord(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Wildlife');
        $this->admin()->createSubcategory($kind, 'Sighting');
        $this->admin()->createSubcategory($kind, 'Spoor');

        // Three sightings this month, one last month; one filed under the kind
        // itself this month. Nothing was ever filed under "spoor".
        $this->anObservation($area, 'sighting', 'first day of this month 09:00');
        $this->anObservation($area, 'sighting', 'first day of this month 10:00');
        $this->anObservation($area, 'sighting', 'first day of this month 11:00');
        $this->anObservation($area, 'sighting', 'first day of last month 09:00');
        $this->anObservation($area, 'wildlife', 'first day of this month 12:00');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->overviewUrl($area));

        $all = $crawler->filter('.kmatrix tr.all')->text();
        self::assertStringContainsString('all of Wildlife', $all);
        // this month · last month · all time
        self::assertSame(['4', '1', '5'], $this->cells($crawler->filter('.kmatrix tr.all td')));

        $sighting = $crawler->filter('.kmatrix tr')->eq(2);
        self::assertStringContainsString('Sighting', $sighting->text());
        self::assertSame(['3', '1', '4'], $this->cells($sighting->filter('td')));

        // The left pane states this month's total for the kind.
        self::assertStringContainsString('4', $crawler->filter('.krow.on')->text());
        // The busiest sub-category of the kind on screen is the full wash.
        self::assertStringContainsString('--w:1', $crawler->filter('.kmatrix .kheat')->first()->attr('style') ?? '');
    }

    public function testNothingOnThePageWrites(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Wildlife');
        $this->admin()->createSubcategory($kind, 'Sighting');

        $crawler = $this->client->request('GET', $this->overviewUrl($area));

        // Only the shell's own chrome — the theme toggle and the sidebar's
        // menus — draws a control, and all of it sits outside the page body.
        self::assertCount(0, $crawler->filter('.page form'));
        self::assertCount(0, $crawler->filter('.page button'));
        // One way to the writing screen, and it is a link.
        self::assertCount(1, $crawler->filter('.kp-foot a'));
        self::assertStringContainsString('Edit in Configure', $crawler->filter('.kp-foot a')->text());
    }

    // ── the helpers ──────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function cells(Crawler $tds): array
    {
        return \array_slice(
            array_map(static fn (\DOMNode $td): string => trim($td->textContent), array_values(iterator_to_array($tds))),
            1,
        );
    }

    private function overviewUrl(AreaOfInterest $area): string
    {
        return \sprintf('/areas/%s/modules/patrols/observation-kinds', $area->getUuidString());
    }

    private function anObservation(AreaOfInterest $area, string $category, string $loggedAt): Observation
    {
        $patrol = new Patrol($area, Vocabulary::type($this->em, $area, 'walk'))
            ->setStartedAt(new \DateTimeImmutable($loggedAt));
        $this->em->persist($patrol);

        $observation = new Observation($patrol, $category)->setLoggedAt(new \DateTimeImmutable($loggedAt));
        $this->em->persist($observation);

        return $observation;
    }

    private function admin(): TaxonomyAdminService
    {
        $admin = static::getContainer()->get('test_public.'.TaxonomyAdminService::class);
        self::assertInstanceOf(TaxonomyAdminService::class, $admin);

        return $admin;
    }

    /** An area running Patrols — persisted, then switched on in the registry. */
    private function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name)->setSource('test fixture')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}',
        );
        $this->em->persist($area);
        $this->everyAreaRunsPatrols($this->em);

        return $area;
    }
}
