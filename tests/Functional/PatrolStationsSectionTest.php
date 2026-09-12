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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Patrol\Entity\Station;

/**
 * THE STATIONS SECTION — its own entry in the configure strip, its own address,
 * and the writes behind it.
 *
 * A station is never deleted: a patrol filed against one keeps it, so retiring
 * takes it off the handset at the next sync and leaves the row, its wire key and
 * every record intact.
 */
final class PatrolStationsSectionTest extends ConfigureSectionTestCase
{
    protected function section(): string
    {
        return 'stations';
    }

    /** The section is lit in the strip and its body is a body. */
    public function testTheSectionIsLitAndDrawsOneCard(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('stations'));

        self::assertResponseIsSuccessful();
        self::assertSame('Stations', trim($crawler->filter('.atabs a.on')->text()));
        self::assertSame(
            ['Stations'],
            $crawler->filter('.c > .tab')->each(
                static fn (Crawler $t): string => trim(str_replace((string) $t->filter('.src')->text(''), '', $t->text())),
            ),
        );
        self::assertSame('Add a station', trim($crawler->filter('.saddcard > .hd')->text()));
        self::assertSame('+ Add station', trim($crawler->filter('.saddcard .sadd')->text()));
    }

    /**
     * A ROW IS THE NAME, THE WIRE KEY, THE COUNT AND ITS ACTIONS, as the design
     * draws it.
     */
    public function testARowCarriesItsCountAndItsActions(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('stations'));

        $row = $crawler->filter('.srow')->first();
        self::assertSame('North post', trim($row->filter('.nm')->text()));
        self::assertSame('north-post', trim($row->filter('.cd')->text()));
        self::assertSame('1 patrol', trim($row->filter('.n')->text()));
        self::assertSame(
            ['Rename', 'Save', 'Retire'],
            $row->filter('.acts .sact')->each(static fn (Crawler $b): string => trim($b->text())),
        );
    }

    /**
     * THE FIELD IS NOT ON THE ROW UNTIL RENAME IS PRESSED. The design's row is
     * text and two buttons; an input sitting open on every row turns a list of
     * words into a page of form controls. It is disclosed by the row's own Rename
     * control and by nothing else — HTML's own disclosure.
     */
    public function testTheRenameFieldIsDisclosedByTheRenameControl(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('stations'));

        $row = $crawler->filter('.srow')->first();
        self::assertCount(0, $row->filter('.acts > .fld'));

        $rename = $row->filter('.acts details');
        self::assertCount(1, $rename);
        self::assertNull($rename->attr('open'), 'The row opens closed.');
        self::assertSame('Rename', trim($rename->filter('summary')->text()));
        self::assertCount(1, $rename->filter('input.fld[name="label"]'));
    }

    /** A new station, then renamed, then retired — and never deleted. */
    public function testAStationIsAddedRenamedAndRetiredWithoutLosingAPatrol(): void
    {
        $this->signInAsManager();

        $this->post($this->configureUrl('stations'), ['label' => 'Ridge Camp']);
        self::assertResponseRedirects($this->configureUrl('stations'));

        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);

        $this->post($this->configureUrl('stations/'.$station->getUuid()->toRfc4122().'/rename'), ['label' => 'Lake Post']);
        $this->em->clear();
        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);
        self::assertSame('Lake Post', $station->getLabel());
        self::assertSame('ridge-camp', $station->getKey(), 'A rename never touches the wire value.');

        $this->post($this->configureUrl('stations/'.$station->getUuid()->toRfc4122().'/retire'), []);
        $this->em->clear();
        $station = $this->stations()->findOneByAreaAndKey($this->area, 'ridge-camp');
        self::assertInstanceOf(Station::class, $station);
        self::assertFalse($station->isActive());

        // Dimmed and pilled on the page, never gone.
        $crawler = $this->client->request('GET', $this->configureUrl('stations'));
        $retired = $crawler->filter('.srow.gone');
        self::assertSame('Lake Post', trim($retired->filter('.nm')->text()));
        self::assertSame('retired', trim($retired->filter('.chip.idle')->text()));
        self::assertSame(
            ['Rename', 'Save', 'Reactivate'],
            $retired->filter('.acts .sact')->each(static fn (Crawler $b): string => trim($b->text())),
        );
    }

    /** Two words the same is refused rather than quietly made twice. */
    public function testASecondStationWithTheSameNameIsRefused(): void
    {
        $this->signInAsManager();

        $this->post($this->configureUrl('stations'), ['label' => 'north POST']);

        self::assertResponseRedirects($this->configureUrl('stations'));
        self::assertCount(1, $this->stations()->findByArea($this->area));
    }

    /** Editing the words rides on the same authority the numbers do. */
    public function testSomebodyWhoMayNotManageCannotAddAStation(): void
    {
        $this->signInAsRecorder();

        $this->client->request('POST', $this->configureUrl('stations'), ['label' => 'Ridge Camp']);

        self::assertResponseStatusCodeSame(403);
    }
}
