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
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Model\PatrolBaseDefaults;

/**
 * THE PATROL TYPES SECTION — its own entry in the configure strip, its own
 * address, and the one POST that saves every row on it.
 *
 * WHAT IS BEING ASSERTED, and why each of it matters more than it looks:
 *
 *   THE BASE IS A CHOICE AND NOT A GUESS. A type with none draws a row ASKING for
 *   one, and choosing one prefills the three numbers under it from that base.
 *   Everything the handset does with a type hangs off this, so a row that quietly
 *   defaulted would be a screen deciding what a drone records.
 *
 *   THE NUMBERS BELONG TO THE TYPE. Once prefilled they are the type's own: a
 *   change to one is a change to that type and to nothing else, and the section's
 *   own sentence names where they came from.
 *
 *   ONE POST SAVES THE WHOLE SECTION. Bases, glyphs, tunables and a new type all
 *   ride one submit, because the design draws one save row.
 */
final class PatrolTypesSectionTest extends ConfigureSectionTestCase
{
    protected function section(): string
    {
        return 'types';
    }

    /** The strip carries the five sections the design draws, in the ruled order. */
    public function testTheStripCarriesEverySectionInTheRuledOrder(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('types'));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Widget library', 'Patrol types', 'Stations', 'Observation kinds', 'Settings'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Patrol types', trim($crawler->filter('.atabs a.on')->text()));
    }

    /**
     * A ROW IS THE NAME, THE WIRE KEY, THE BASE, THE COUNT AND ITS ACTIONS — and
     * a type nobody has given a base draws the asking control instead of a badge.
     */
    public function testATypeWithNoBaseAsksForOneOnItsRow(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('types'));

        $row = $crawler->filter('.stype')->first()->filter('.srow');
        self::assertSame('Walking round', trim($row->filter('.nm')->text()));
        self::assertSame('walk', trim($row->filter('.cd')->text()));
        self::assertSame('1 patrol', trim($row->filter('.n')->text()));

        $asking = $row->filter('summary.sbase.ask');
        self::assertCount(1, $asking);
        self::assertSame('choose base', trim($asking->text()));
    }

    /**
     * THE TUNABLES ARE PREFILLED FROM THE BASE, and the row says so in the words
     * the design writes under it.
     */
    public function testChoosingABasePrefillsTheThreeNumbersUnderTheRow(): void
    {
        $this->signInAsManager();
        $type = $this->firstType();

        $this->post($this->configureUrl('types'), ['base' => [$type->getUuid()->toRfc4122() => 'surface']]);
        self::assertResponseRedirects($this->configureUrl('types'));

        $this->em->clear();
        $type = $this->firstType();
        self::assertSame(PatrolBaseEnum::Surface, $type->getBase());
        self::assertSame(2, $type->getPaceMinKmh());
        self::assertSame(45, $type->getPaceMaxKmh());
        self::assertSame(150, $type->getCoverageBufferM());
        self::assertSame(ObservationPlacementEnum::AtPosition, $type->getObservationPlacement());

        $crawler = $this->client->request('GET', $this->configureUrl('types'));
        $row = $crawler->filter('.stype')->first();
        self::assertSame('surface', trim($row->filter('summary.sbase')->text()));
        self::assertStringContainsString(
            'pace 2–45 km/h, buffer 150 m, observations at the ranger’s position',
            $row->filter('.stun .from')->text(),
        );
    }

    /** An aerial type is prefilled from its own base and not from the other one. */
    public function testAnAerialTypeIsPrefilledFromTheAerialBase(): void
    {
        $this->signInAsManager();
        $type = $this->firstType();

        $this->post($this->configureUrl('types'), ['base' => [$type->getUuid()->toRfc4122() => 'aerial']]);

        $this->em->clear();
        $type = $this->firstType();
        self::assertSame(15, $type->getPaceMinKmh());
        self::assertSame(70, $type->getPaceMaxKmh());
        self::assertSame(400, $type->getCoverageBufferM());
        self::assertSame(ObservationPlacementEnum::OnMap, $type->getObservationPlacement());
    }

    /**
     * A NUMBER CHANGED ON A ROW BELONGS TO THAT TYPE ALONE — and re-posting the
     * same base never writes the default back over it.
     */
    public function testATunedNumberSurvivesTheNextSaveOfTheSameBase(): void
    {
        $this->signInAsManager();
        $type = $this->firstType();
        $uuid = $type->getUuid()->toRfc4122();

        $this->post($this->configureUrl('types'), ['base' => [$uuid => 'surface']]);
        $this->post($this->configureUrl('types'), [
            'base' => [$uuid => 'surface'],
            'pace_min' => [$uuid => '3'],
            'pace_max' => [$uuid => '18'],
            'buffer' => [$uuid => '600'],
            'placement' => [$uuid => 'on_map'],
            'glyph' => [$uuid => 'bike'],
        ]);

        $this->em->clear();
        $type = $this->firstType();
        self::assertSame(3, $type->getPaceMinKmh());
        self::assertSame(18, $type->getPaceMaxKmh());
        self::assertSame(600, $type->getCoverageBufferM());
        self::assertSame(ObservationPlacementEnum::OnMap, $type->getObservationPlacement());
        self::assertSame('bike', $type->getGlyph());
    }

    /** A hand-posted number outside the design's bounds is clamped, never refused. */
    public function testAHandPostedNumberIsClamped(): void
    {
        $this->signInAsManager();
        $uuid = $this->firstType()->getUuid()->toRfc4122();

        $this->post($this->configureUrl('types'), [
            'base' => [$uuid => 'surface'],
            'pace_min' => [$uuid => '-4'],
            'pace_max' => [$uuid => '9999'],
            'buffer' => [$uuid => '1'],
        ]);

        $this->em->clear();
        $type = $this->firstType();
        self::assertSame(PatrolBaseDefaults::MIN_PACE_KMH, $type->getPaceMinKmh());
        self::assertSame(PatrolBaseDefaults::MAX_PACE_KMH, $type->getPaceMaxKmh());
        self::assertSame(PatrolBaseDefaults::MIN_BUFFER_M, $type->getCoverageBufferM());
    }

    /** A glyph nothing ships a file for is not written. */
    public function testAGlyphOutsideTheHouseSetIsRefusedQuietly(): void
    {
        $this->signInAsManager();
        $uuid = $this->firstType()->getUuid()->toRfc4122();

        $this->post($this->configureUrl('types'), ['glyph' => [$uuid => 'helicopter']]);

        $this->em->clear();
        self::assertNull($this->firstType()->getGlyph());
    }

    /** The add panel creates a type with the base and the glyph it was given. */
    public function testTheAddPanelCreatesATypeWithItsBaseAndItsGlyph(): void
    {
        $this->signInAsManager();

        $this->post($this->configureUrl('types'), [
            'label' => 'Drone sortie',
            'add_base' => 'aerial',
            'add_glyph' => 'truck',
        ]);

        $this->em->clear();
        $created = $this->types()->findOneByAreaAndKey($this->area, 'drone-sortie');
        self::assertInstanceOf(PatrolType::class, $created);
        self::assertSame(PatrolBaseEnum::Aerial, $created->getBase());
        self::assertSame('truck', $created->getGlyph());
        self::assertSame(400, $created->getCoverageBufferM());
    }

    /** A retired type stays listed, dimmed and pilled, with its base still on it. */
    public function testARetiredTypeStaysListedAsRetired(): void
    {
        $this->signInAsManager();
        $uuid = $this->firstType()->getUuid()->toRfc4122();

        $this->post($this->configureUrl('types'), ['base' => [$uuid => 'surface']]);
        $this->post($this->configureUrl('types/'.$uuid.'/retire'), []);

        $crawler = $this->client->request('GET', $this->configureUrl('types'));
        $row = $crawler->filter('.stype .srow.gone');
        self::assertSame('Walking round', trim($row->filter('.nm')->text()));
        self::assertSame('retired', trim($row->filter('.chip.idle')->text()));
        self::assertSame('surface', trim($row->filter('.sbase')->text()));
        // THE DESIGN'S RETIRED ROW CARRIES REACTIVATE ALONE; this one keeps
        // Rename and Tunables beside it, deliberately. Renaming a retired word is
        // what an administrator does to fold a word that turned up from a handset
        // into one the area already keeps, and the design ships a retired row's
        // `.stun` block with no control that would open it — so dropping Tunables
        // would make a retired type's numbers unreadable while every patrol filed
        // under it still runs on them.
        self::assertSame(
            ['Tunables', 'Rename', 'Save', 'Reactivate'],
            $row->filter('.acts .sact')->each(static fn (Crawler $b): string => trim($b->text())),
        );
    }

    /** Editing what this area patrols on rides on `patrols.manage`. */
    public function testSomebodyWhoMayNotManageCannotSaveTheSection(): void
    {
        $this->signInAsRecorder();

        $this->client->request('POST', $this->configureUrl('types'), ['label' => 'Night sweep']);

        self::assertResponseStatusCodeSame(403);
    }

    /** The section is a body: no head, no strip and no way back of its own. */
    public function testTheSectionDrawsOneCardAndOneSaveRow(): void
    {
        $this->signInAsManager();
        $crawler = $this->client->request('GET', $this->configureUrl('types'));

        self::assertSame(
            ['Patrol types'],
            $crawler->filter('.c > .tab')->each(
                static fn (Crawler $t): string => trim(str_replace((string) $t->filter('.src')->text(''), '', $t->text())),
            ),
        );
        self::assertCount(1, $crawler->filter('.save-row'));
        self::assertSame('Add a patrol type', trim($crawler->filter('.saddcard > .hd')->text()));
        self::assertSame('+ Add type', trim($crawler->filter('.saddcard .sadd')->text()));
    }
}
