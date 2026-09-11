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

namespace Uhifadhi\Patrol\Tests\Unit\Shell;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Shell\PatrolModuleTabs;

/**
 * THE MODULE'S DATA PLACES — three, and none of them configures anything.
 */
final class PatrolModuleTabsTest extends TestCase
{
    public function testItDeclaresItsOwnSlug(): void
    {
        self::assertSame(PatrolModuleProvider::SLUG, new PatrolModuleTabs()->slug());
    }

    public function testItDeclaresTheOverviewTheFullLogAndTheObservationKinds(): void
    {
        $tabs = new PatrolModuleTabs()->tabs();

        self::assertSame(['Overview', 'Patrols', 'Observation kinds'], array_map(static fn ($tab) => $tab->label, $tabs));
        self::assertSame(['patrol_dashboard', 'patrol_list', 'patrol_kinds_overview'], array_map(static fn ($tab) => $tab->routeName, $tabs));
    }

    /** The read-only kinds page is lit by its own route, and by nothing else. */
    public function testTheKindsTabLightsOnlyForItsOwnRoute(): void
    {
        [, , $kinds] = new PatrolModuleTabs()->tabs();

        self::assertTrue($kinds->lightsFor('patrol_kinds_overview'));
        self::assertFalse($kinds->lightsFor('patrol_kinds'));
        self::assertFalse($kinds->lightsFor('patrol_dashboard'));
    }

    /**
     * A LIST AND ITS DETAIL SCREENS ARE ONE PLACE: opening a patrol, or one of
     * its observations, does not leave the place patrols live in.
     */
    public function testTheListTabStaysLitOnTheDetailScreens(): void
    {
        [$overview, $list] = new PatrolModuleTabs()->tabs();

        self::assertTrue($overview->lightsFor('patrol_dashboard'));
        self::assertFalse($overview->lightsFor('patrol_list'));

        self::assertTrue($list->lightsFor('patrol_list'));
        self::assertTrue($list->lightsFor('patrol_show'));
        self::assertTrue($list->lightsFor('patrol_observation_show'));
        self::assertFalse($list->lightsFor('patrol_dashboard'));
    }

    /** The word "register" is nowhere on the surface this module draws. */
    public function testNoTabSaysRegister(): void
    {
        foreach (new PatrolModuleTabs()->tabs() as $tab) {
            self::assertStringNotContainsStringIgnoringCase('register', $tab->label);
            self::assertStringNotContainsStringIgnoringCase('register', $tab->routeName);
        }
    }
}
