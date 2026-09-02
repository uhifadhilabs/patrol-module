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

namespace UhifadhiLabs\Patrol\Tests\Integration\Overview;

use Uhifadhi\Model\Widget;
use Uhifadhi\Overview\AttentionItem;
use Uhifadhi\Overview\AttentionProviderInterface;
use Uhifadhi\Overview\AttentionSeverity;
use Uhifadhi\Overview\ContributesStylesheetInterface;
use Uhifadhi\Overview\MapLayer;
use Uhifadhi\Overview\MapLayerProviderInterface;
use Uhifadhi\Overview\NowTile;
use Uhifadhi\Overview\NowTileProviderInterface;
use Uhifadhi\Overview\OverviewContributorInterface;
use Uhifadhi\Overview\PulseEvent;
use Uhifadhi\Overview\PulseProviderInterface;
use UhifadhiLabs\Patrol\Enum\PatrolStatusEnum;
use UhifadhiLabs\Patrol\Module\PatrolModuleProvider;
use UhifadhiLabs\Patrol\Overview\PatrolAttention;
use UhifadhiLabs\Patrol\Overview\PatrolMapLayers;
use UhifadhiLabs\Patrol\Overview\PatrolNowTiles;
use UhifadhiLabs\Patrol\Overview\PatrolOverviewContributor;
use UhifadhiLabs\Patrol\Overview\PatrolPulse;
use UhifadhiLabs\Patrol\UhifadhiLabsPatrolBundle;

/**
 * THE FIVE SEAMS, on one morning.
 *
 * The point of every assertion below is that the module SAYS NOTHING IT CANNOT
 * MEASURE: no tile where there is nothing to count, no attention row for a fact
 * the module does not hold, no legend entry without a layer behind it — and no
 * layer that disappears from the legend on a quiet day.
 */
final class PatrolContributionsTest extends PatrolOverviewTestCase
{
    /** @return list<NowTile> */
    private function tiles(): array
    {
        $provider = $this->service(PatrolNowTiles::class);
        \assert($provider instanceof PatrolNowTiles);

        return $provider->nowTilesFor($this->area, $this->now());
    }

    /** @return list<AttentionItem> */
    private function attention(): array
    {
        $provider = $this->service(PatrolAttention::class);
        \assert($provider instanceof PatrolAttention);

        return $provider->attentionFor($this->area, $this->now());
    }

    /** @return list<MapLayer> */
    private function layers(): array
    {
        $provider = $this->service(PatrolMapLayers::class);
        \assert($provider instanceof PatrolMapLayers);

        return $provider->mapLayersFor($this->area, $this->now());
    }

    /** @return list<PulseEvent> */
    private function pulse(string $since = '2026-03-20T18:00:00+00:00'): array
    {
        $provider = $this->service(PatrolPulse::class);
        \assert($provider instanceof PatrolPulse);

        return $provider->pulseFor($this->area, new \DateTimeImmutable($since), $this->now());
    }

    private function contributor(): PatrolOverviewContributor
    {
        $contributor = $this->service(PatrolOverviewContributor::class);
        \assert($contributor instanceof PatrolOverviewContributor);

        return $contributor;
    }

    // ---- the contributor --------------------------------------------------

    public function testTheGroupIsTheModuleAndTheSlugIsTheOneTheHostInstalls(): void
    {
        $contributor = $this->contributor();

        // The host only asks this contributor for widgets where a module of this
        // slug is switched on, so the two must be the same word.
        self::assertSame(new PatrolModuleProvider('pressure')->slug(), $contributor->moduleSlug());
        self::assertSame($contributor->moduleSlug(), $contributor->group()->id);
        self::assertSame('Patrols · uhifadhilabs/patrol-module', $contributor->group()->label);
    }

    public function testEveryWidgetIsFiledUnderTheContributorsOwnGroup(): void
    {
        $contributor = $this->contributor();

        self::assertSame(
            ['pl_now', 'pl_today', 'pl_gaps', 'pl_obsq', 'pl_column'],
            array_map(static fn (Widget $w): string => $w->id, $contributor->widgets()),
        );
        foreach ($contributor->widgets() as $widget) {
            self::assertSame($contributor->group()->id, $widget->group);
        }
    }

    public function testTheColumnAndTheDayCardOfferAThirdOfTheRow(): void
    {
        $spans = [];
        foreach ($this->contributor()->widgets() as $widget) {
            $spans[$widget->id] = $widget->spans;
        }

        // The module-columns direction is a page of one column per module, and a
        // third of the row is the only span three columns have.
        self::assertContains(4, $spans['pl_column']);
        self::assertContains(4, $spans['pl_today']);
        // Nothing else here reads at a third of the row.
        self::assertNotContains(4, $spans['pl_now']);
        self::assertNotContains(4, $spans['pl_gaps']);
        self::assertNotContains(4, $spans['pl_obsq']);
    }

    public function testEveryWidgetHasAPartialUnderTheModulesOwnNamespace(): void
    {
        $contributor = $this->contributor();

        foreach ($contributor->widgets() as $widget) {
            $partial = \sprintf($contributor->partialPattern(), $widget->id);
            self::assertStringStartsWith('@UhifadhiLabsPatrol/overview/', $partial);
            /** @var \Twig\Environment $twig */
            $twig = static::getContainer()->get('twig');
            self::assertTrue($twig->getLoader()->exists($partial), $partial.' is missing');
        }
    }

    /**
     * THE ONE SURFACE SOMEBODY ELSE RENDERS THIS MODULE'S MARKUP ON. Every
     * patrol page of this module's own extends `base.html.twig`, which links
     * patrol.css; the area overview does not, so without this the category
     * chips, the type dots and the stale-ping colours on a contributed plate
     * render naked. The interface is optional — a contributor with no CSS
     * simply does not implement it — so what is being pinned here is that this
     * one does.
     */
    public function testTheContributorTellsTheHostWhichStylesheetItsPlatesWear(): void
    {
        $contributor = $this->contributor();

        self::assertInstanceOf(ContributesStylesheetInterface::class, $contributor);
        // What AssetMapper serves the bundle's public/ under — the SAME string
        // base.html.twig links, because both read the bundle's own constant and
        // the path is therefore written once.
        self::assertSame('bundles/uhifadhilabspatrol/patrol.css', $contributor->stylesheet());
        self::assertSame(UhifadhiLabsPatrolBundle::STYLESHEET, $contributor->stylesheet());
        self::assertStringContainsString(
            "constant('UhifadhiLabs\\\\Patrol\\\\UhifadhiLabsPatrolBundle::STYLESHEET')",
            (string) file_get_contents(__DIR__.'/../../../templates/base.html.twig'),
        );
    }

    public function testTheContextIsOneReadingOfTheMorning(): void
    {
        $this->makePatrol('naabi', 'walk', '2026-03-21T06:00:00+00:00', status: PatrolStatusEnum::Recording);

        $context = $this->contributor()->context($this->area, $this->now());

        self::assertSame(
            ['out', 'handsets', 'today', 'gaps', 'observations', 'types', 'typeColors', 'stalePingMinutes', 'coverageBufferKm', 'dashboardUrl'],
            array_keys($context),
        );
        self::assertSame(1, is_countable($context['out']) ? \count($context['out']) : -1);
        // The card's own copy states the threshold; it reads it from the same
        // constant the staleness test uses, so the number on screen and the
        // number in code cannot drift.
        self::assertSame(90, $context['stalePingMinutes']);
    }

    // ---- the right-now strip ---------------------------------------------

    public function testAModuleWithNothingToSayPutsNoTileInTheStrip(): void
    {
        // Absent is not zero: a tile reading 0 would claim the module measured
        // the area's live patrols and found none. An area whose register is
        // empty has never patrolled at all — there is no day to measure.
        self::assertSame([], $this->tiles());
    }

    /**
     * A ZERO DAY IS A DAY. Once the area has a register, "nothing walked yet"
     * is something the module MEASURED — so the day's tile stays on the strip
     * and reads 0, exactly as the design's strip always carries it. A tile that
     * vanishes on a quiet morning tells an area manager that the module is
     * broken rather than that the morning is quiet.
     */
    public function testAQuietDayInAnAreaThatPatrolsStillCarriesTheDaysTile(): void
    {
        // Yesterday's round: the register exists, and today nothing has closed.
        $this->makePatrol('a', 'walk', '2026-03-20T06:00:00+00:00', '2026-03-20T09:00:00+00:00')->setDistanceKm(11.0);
        $this->em->flush();

        $tiles = $this->tiles();

        self::assertCount(1, $tiles);
        self::assertSame('PL·N2', $tiles[0]->index);
        // ZERO KILOMETRES IS A MEASUREMENT, not an unknown: nothing closed, so
        // nothing was walked. The em dash is for a day whose patrols closed and
        // recorded no distance — a different statement.
        self::assertSame('0', $tiles[0]->value);
        self::assertSame('km', $tiles[0]->unit);
        self::assertSame('0 patrols closed · 0 observations', $tiles[0]->subline);
    }

    public function testTheOutTileCountsWhoIsOutAndNamesTheHandsetToRaise(): void
    {
        $pinging = $this->makePatrol('endulen', 'walk', '2026-03-21T05:20:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($pinging, [['2026-03-21T11:30:00+00:00', 35.05, -2.95]]);
        $silent = $this->makePatrol('naabi', 'boat', '2026-03-21T10:37:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($silent, [['2026-03-21T09:32:00+00:00', 35.05, -2.95]]);

        $tile = $this->tiles()[0];

        self::assertSame('PL·N1', $tile->index);
        self::assertSame('2', $tile->value);
        self::assertSame('1 walking round · 1 boat', $tile->subline);
        self::assertSame('Naabi no ping 2 h 10', $tile->alarm);
        self::assertSame(NowTile::TONE_BAD, $tile->tone);
        // The one thing on this surface that a polling endpoint refreshes.
        self::assertTrue($tile->live);
    }

    public function testAQuietMorningWithEverybodyPingingRaisesNoAlarm(): void
    {
        $patrol = $this->makePatrol('endulen', 'walk', '2026-03-21T05:20:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($patrol, [['2026-03-21T11:30:00+00:00', 35.05, -2.95]]);

        $tile = $this->tiles()[0];

        self::assertNull($tile->alarm);
        self::assertSame(NowTile::TONE_HOT, $tile->tone);
    }

    public function testTheWalkedTileSaysAnEmDashWhereNobodyMeasuredTheDistance(): void
    {
        $this->makePatrol('a', 'walk', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00');

        $tile = $this->tiles()[0];

        self::assertSame('PL·N2', $tile->index);
        self::assertSame('—', $tile->value);
        self::assertNull($tile->unit);
        // What IS known is still said.
        self::assertSame('1 patrol closed · 0 observations', $tile->subline);
    }

    public function testTheWalkedTileCarriesItsUnitWhenTheDistanceWasRecorded(): void
    {
        $this->makePatrol('a', 'walk', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00')->setDistanceKm(96.0);
        $this->em->flush();

        $tile = $this->tiles()[0];

        self::assertSame('96', $tile->value);
        self::assertSame('km', $tile->unit);
    }

    // ---- needs attention --------------------------------------------------

    public function testAGoodDayLooksLikeOne(): void
    {
        $patrol = $this->makePatrol('endulen', 'walk', '2026-03-21T05:20:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($patrol, [['2026-03-21T11:30:00+00:00', 35.05, -2.95]]);

        self::assertSame([], $this->attention());
    }

    public function testASilentPatrolIsAlwaysUrgentAndCarriesWhatARadioOperatorWouldAskNext(): void
    {
        $lead = $this->makeUser('Leah', 'Saitoti');
        $patrol = $this->makePatrol('naabi', 'boat', '2026-03-21T10:37:00+00:00', status: PatrolStatusEnum::Recording);
        $patrol->setLead($lead);
        $this->em->flush();
        $this->ping($patrol, [['2026-03-21T09:32:00+00:00', 35.05, -2.95]]);

        $item = $this->attention()[0];

        // A row about a person is never softer than Now.
        self::assertSame(AttentionSeverity::Now, $item->severity);
        self::assertSame('live position', $item->kind);
        self::assertSame(\sprintf('%s has not pinged for 2 h 10.', $patrol->getRef()), $item->headline);
        self::assertSame('Boat patrol out of Naabi since 10:37, led by Leah Saitoti.', $item->detail);
        self::assertSame(['Naabi', 'last ping 09:32'], $item->meta);
        self::assertSame('2 h 10', $item->ageLabel);
    }

    public function testAZoneUnenteredForAWeekIsThisWeeksWorkAndAFortnightIsTodays(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $this->makeZone('South', -3.0, -2.95);
        // North entered 8 days ago, South 20.
        $this->makePatrol('a', 'walk', '2026-03-13T06:00:00+00:00', '2026-03-13T09:00:00+00:00')
            ->setTrack('{"type":"LineString","coordinates":[[35.0,-2.92],[35.1,-2.92]]}');
        $this->makePatrol('b', 'walk', '2026-03-01T06:00:00+00:00', '2026-03-01T09:00:00+00:00')
            ->setTrack('{"type":"LineString","coordinates":[[35.0,-2.98],[35.1,-2.98]]}');
        $this->em->flush();

        $items = $this->attention();
        $byZone = [];
        foreach ($items as $item) {
            $byZone[$item->meta[0]] = $item;
        }

        self::assertSame(AttentionSeverity::Soon, $byZone['North']->severity);
        self::assertSame('8 d', $byZone['North']->ageLabel);
        self::assertSame(AttentionSeverity::Now, $byZone['South']->severity);
        self::assertSame('coverage gap', $byZone['South']->kind);
    }

    public function testAZoneBeingPatrolledIsNotOnTheList(): void
    {
        $this->makeZone('North', -2.95, -2.9);
        $this->makePatrol('a', 'walk', '2026-03-19T06:00:00+00:00', '2026-03-19T09:00:00+00:00')
            ->setTrack('{"type":"LineString","coordinates":[[35.0,-2.92],[35.1,-2.92]]}');
        $this->em->flush();

        // Two days is a zone being patrolled. A list that carried it would be a
        // list people scroll past.
        self::assertSame([], $this->attention());
    }

    public function testAZoneNoTrackEverEnteredSaysNeverAndSortsAsTheOldest(): void
    {
        $this->makeZone('North', -2.95, -2.9);

        $item = $this->attention()[0];

        self::assertSame(AttentionSeverity::Now, $item->severity);
        self::assertSame('No patrol has ever entered North.', $item->headline);
        self::assertSame('never', $item->ageLabel);
        self::assertSame('No track has been recorded here this month, so there is no coverage share to state.', $item->detail);
    }

    public function testNothingIsRaisedAboutObservationsBecauseNothingIsKnown(): void
    {
        $patrol = $this->makePatrol('a', 'walk', '2026-03-01T06:00:00+00:00', '2026-03-01T09:00:00+00:00');
        $this->makeObservation($patrol, '2026-03-02T06:00:00+00:00');

        // Whether an observation has been filed as an incident is recorded by the
        // incidents module, not here. Raising every one as unfiled would put a
        // queue of invented work in front of a ranger.
        self::assertSame([], $this->attention());
    }

    // ---- the operational plate -------------------------------------------

    public function testEveryLayerShipsItsLegendEntryEvenWithNothingToDraw(): void
    {
        $layers = $this->layers();

        self::assertSame(['patrols.live', 'patrols.today', 'patrols.buffer'], array_map(static fn (MapLayer $l): string => $l->id, $layers));
        foreach ($layers as $layer) {
            self::assertSame('Patrols', $layer->groupLabel);
            self::assertSame('FeatureCollection', $layer->features['type']);
            self::assertSame([], $layer->features['features']);
        }
        // Operational layers are on; the analysis one is one click away.
        self::assertTrue($layers[0]->on);
        self::assertTrue($layers[1]->on);
        self::assertFalse($layers[2]->on);
    }

    public function testALivePatrolIsDrawnAsItsTrailAndTheRingAtItsHead(): void
    {
        $patrol = $this->makePatrol('endulen', 'walk', '2026-03-21T05:20:00+00:00', status: PatrolStatusEnum::Recording);
        $this->ping($patrol, [
            ['2026-03-21T11:00:00+00:00', 35.01, -2.95],
            ['2026-03-21T11:30:00+00:00', 35.05, -2.95],
        ]);

        $live = $this->layers()[0];

        self::assertSame(1, $live->count);
        self::assertTrue($live->live);

        // The collection travels to the plate as JSON, so it is read back as
        // JSON: the trail, then the ring at its head.
        $json = json_encode($live->features, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"kind":"trail"', $json);
        self::assertStringContainsString('"kind":"ping"', $json);
        self::assertLessThan(mb_strpos($json, '"kind":"ping"'), (int) mb_strpos($json, '"kind":"trail"'));
        self::assertStringContainsString('"LineString"', $json);
        // The module's own type colour, so a walking patrol is the same green
        // here as on the module's own coverage map.
        self::assertStringContainsString('"color":"#3ED9A8"', $json);
    }

    public function testAHandLoggedPatrolIsCountedAndNotDrawn(): void
    {
        // No geometry: it is left out of the plate rather than drawn as a guess,
        // and it still closed today.
        $this->makePatrol('a', 'walk', '2026-03-21T06:00:00+00:00', '2026-03-21T09:00:00+00:00');

        $today = $this->layers()[1];

        self::assertSame(1, $today->count);
        self::assertSame([], $today->features['features']);
    }

    // ---- the pulse --------------------------------------------------------

    public function testTheMovesAreOpenedClosedAndLogged(): void
    {
        $patrol = $this->makePatrol('endulen', 'walk', '2026-03-21T06:10:00+00:00', '2026-03-21T09:30:00+00:00');
        $patrol->setDistanceKm(41.8);
        $this->em->flush();
        $this->makeObservation($patrol, '2026-03-21T09:14:00+00:00', note: 'Lion tracks at a boma');

        $moves = array_map(static fn (PulseEvent $e): string => $e->move, $this->pulse());

        self::assertSame(['patrol opened', 'patrol closed', 'observation logged'], $moves);
    }

    public function testAClosedPatrolSaysWhatItCameBackWith(): void
    {
        $patrol = $this->makePatrol('endulen', 'walk', '2026-03-21T06:10:00+00:00', '2026-03-21T09:30:00+00:00');
        $patrol->setDistanceKm(41.8);
        $this->em->flush();
        $this->makeObservation($patrol, '2026-03-21T09:14:00+00:00');

        $closed = array_values(array_filter($this->pulse(), static fn (PulseEvent $e): bool => 'patrol closed' === $e->move))[0];

        self::assertSame($patrol->getRef(), $closed->recordRef);
        self::assertSame('Closed at Endulen — 41.8 km, 1 observation', $closed->summary);
        self::assertSame('closed', $closed->state);
        self::assertSame('#3ED9A8', $closed->swatch);
    }

    public function testAPatrolThatRecordedNoDistanceSaysSoRatherThanZero(): void
    {
        $this->makePatrol('endulen', 'walk', '2026-03-21T06:10:00+00:00', '2026-03-21T09:30:00+00:00');

        $closed = array_values(array_filter($this->pulse(), static fn (PulseEvent $e): bool => 'patrol closed' === $e->move))[0];

        self::assertStringContainsString('distance not recorded', $closed->summary);
    }

    public function testAPatrolSinceDiscardedStillOpenedAndDidNotClose(): void
    {
        $this->makePatrol('endulen', 'walk', '2026-03-21T06:10:00+00:00', '2026-03-21T09:30:00+00:00', PatrolStatusEnum::Discarded);

        $moves = $this->pulse();

        // The discard is a later judgement about the record, not a claim that
        // the shift never began — so the opening stays, and says so.
        self::assertSame(['patrol opened'], array_map(static fn (PulseEvent $e): string => $e->move, $moves));
        self::assertStringContainsString('since discarded', $moves[0]->summary);
        self::assertSame('discarded', $moves[0]->state);
    }

    public function testMovesOutsideTheWindowAreNotMoves(): void
    {
        $this->makePatrol('endulen', 'walk', '2026-03-19T06:10:00+00:00', '2026-03-19T09:30:00+00:00');

        self::assertSame([], $this->pulse());
    }

    // ---- the wiring -------------------------------------------------------

    public function testTheFiveContributionsAreTaggedWithTheTagsTheHostReads(): void
    {
        // The bundle spells these tags as LITERALS, because the constants live on
        // host classes that are not on this bundle's classpath at build time.
        // This is the pin that keeps the two in step.
        $tags = [
            'patrol.overview.contributor' => OverviewContributorInterface::TAG,
            'patrol.overview.now_tiles' => NowTileProviderInterface::TAG,
            'patrol.overview.attention' => AttentionProviderInterface::TAG,
            'patrol.overview.map_layers' => MapLayerProviderInterface::TAG,
            'patrol.overview.pulse' => PulseProviderInterface::TAG,
        ];

        self::assertSame([
            'patrol.overview.contributor' => 'uhifadhi.overview.widget_provider',
            'patrol.overview.now_tiles' => 'uhifadhi.overview.now_tile',
            'patrol.overview.attention' => 'uhifadhi.overview.attention',
            'patrol.overview.map_layers' => 'uhifadhi.map.layer',
            'patrol.overview.pulse' => 'uhifadhi.overview.pulse',
        ], $tags);
    }

    public function testEveryContributionAnswersToTheModulesOwnSlug(): void
    {
        $slug = PatrolOverviewContributor::SLUG;

        foreach (['patrol.overview.now_tiles', 'patrol.overview.attention', 'patrol.overview.map_layers', 'patrol.overview.pulse'] as $id) {
            $provider = $this->service(match ($id) {
                'patrol.overview.now_tiles' => PatrolNowTiles::class,
                'patrol.overview.attention' => PatrolAttention::class,
                'patrol.overview.map_layers' => PatrolMapLayers::class,
                default => PatrolPulse::class,
            });
            \assert($provider instanceof NowTileProviderInterface || $provider instanceof AttentionProviderInterface || $provider instanceof MapLayerProviderInterface || $provider instanceof PulseProviderInterface);
            // A provider whose slug the area has not installed is never asked, so
            // a slug that did not match the module's would go silently unread.
            self::assertSame($slug, $provider->moduleSlug(), $id);
        }
    }
}
