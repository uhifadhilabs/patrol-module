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
use Uhifadhi\Patrol\Tests\Fixtures\Vocabulary;

/**
 * A MONTH WHOSE LONGEST PATROL IS SHORTER THAN AN HOUR STILL DRAWS.
 *
 * "Effort by ranger" plots hours, and hours are a float — the only chart on the
 * surface whose maximum can be a fraction. Its axis has to round that maximum
 * UP to a multiple of three, so a month of one-minute patrols gets an axis of
 * three hours and a bar of a couple of pixels; an axis that rounded a fraction
 * DOWN would be zero, and every bar on the chart a division by it.
 *
 * The library previews the whole catalogue, so it renders the effort widget
 * whatever the reader's own composition says — which makes it the one page that
 * proves a widget draws at all.
 */
final class ShortPatrolEffortTest extends WebTestCase
{
    use EveryAreaRunsPatrols;

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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->ranger = new User()->setPassword('x')->setEmail('ranger@example.test')
            ->setFirstName('Ada')->setLastName('Alpha');
        $this->em->persist($this->ranger);

        // The month's ONE credited patrol: closed, with a committed lead, and one
        // minute long — 0.0167 h, the fraction the axis has to cope with.
        $this->em->persist(new Patrol($this->area, Vocabulary::type($this->em, $this->area, 'walk'))
            ->setStationRecord(Vocabulary::station($this->em, $this->area, 'North post'))
            ->setLead($this->ranger)
            ->setStartedAt(new \DateTimeImmutable('today 06:10'))
            ->setEndedAt(new \DateTimeImmutable('today 06:11')));

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

    public function testTheEffortChartDrawsAMeasurableBarForAOneMinutePatrol(): void
    {
        $this->client->loginUser($this->ranger);
        $crawler = $this->client->request(
            'GET',
            '/areas/'.$this->area->getUuidString().'/modules/patrols/widgets',
        );

        self::assertResponseIsSuccessful();

        $effort = $crawler->filter('template[data-widget-template="effort"]');
        self::assertCount(1, $effort);
        self::assertStringContainsString('Effort by ranger', $effort->html());

        // The axis rounds a fraction of an hour up to three, so the bar is a real
        // width: a positive number of pixels, inside the plot's 315.
        $widths = $effort->filter('rect')->each(static fn (Crawler $rect): string => (string) $rect->attr('width'));
        self::assertCount(1, $widths, 'one credited ranger, one bar.');
        self::assertTrue(is_numeric($widths[0]), 'the bar width is a number: '.$widths[0]);
        self::assertGreaterThan(0, (float) $widths[0]);
        self::assertLessThanOrEqual(315.0, (float) $widths[0]);

        // And the axis it was measured against is labelled in whole hours.
        self::assertSame(
            ['0', '1', '2', '3'],
            $effort->filter('text[text-anchor="middle"]')->each(static fn (Crawler $t): string => trim($t->text())),
        );
    }
}
