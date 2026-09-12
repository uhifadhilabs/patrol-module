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

namespace Uhifadhi\Patrol\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Model\PatrolBaseDefaults;

/**
 * WHAT EACH BASE PREFILLS — the two sets of numbers the Patrol types section
 * writes into a type's tunables the moment its base is chosen.
 *
 * The values are the ones the section prints under each row in words ("prefilled
 * from the surface base: pace 2–45 km/h, buffer 150 m, observations at the
 * ranger's position"), so a number changed here without the sentence changing
 * with it is a screen that lies about itself.
 */
final class PatrolBaseDefaultsTest extends TestCase
{
    public function testSurfaceIsAWalkingRidingDrivingPaceAtAHundredAndFiftyMetres(): void
    {
        $defaults = PatrolBaseDefaults::of(PatrolBaseEnum::Surface);

        self::assertSame(2, $defaults->paceMinKmh);
        self::assertSame(45, $defaults->paceMaxKmh);
        self::assertSame(150, $defaults->coverageBufferM);
        self::assertSame(ObservationPlacementEnum::AtPosition, $defaults->observationPlacement);
    }

    public function testAerialIsAFlightPaceAtFourHundredMetresAndPlacedOnTheMap(): void
    {
        $defaults = PatrolBaseDefaults::of(PatrolBaseEnum::Aerial);

        self::assertSame(15, $defaults->paceMinKmh);
        self::assertSame(70, $defaults->paceMaxKmh);
        self::assertSame(400, $defaults->coverageBufferM);
        self::assertSame(ObservationPlacementEnum::OnMap, $defaults->observationPlacement);
    }

    /**
     * THE GLYPH A TYPE WEARS WHEN NOBODY PICKED ONE. A base always has one, so a
     * row is never drawn with an empty square where its mark should be.
     */
    public function testEachBaseCarriesAGlyphOfItsOwn(): void
    {
        self::assertSame('route', PatrolBaseDefaults::of(PatrolBaseEnum::Surface)->glyph);
        self::assertSame('aerial', PatrolBaseDefaults::of(PatrolBaseEnum::Aerial)->glyph);
    }

    /**
     * THE HOUSE SET, AND NOTHING OUTSIDE IT. The strip the section draws offers
     * exactly these five marks, in this order, and a posted name that is not one
     * of them is not a glyph this module ships a file for.
     */
    public function testTheGlyphStripIsTheHouseSetInTheOrderTheSectionDrawsIt(): void
    {
        self::assertSame(['route', 'footprints', 'bike', 'car', 'truck'], PatrolBaseDefaults::GLYPHS);

        foreach ([PatrolBaseEnum::Surface, PatrolBaseEnum::Aerial] as $base) {
            self::assertContains(PatrolBaseDefaults::of($base)->glyph, [...PatrolBaseDefaults::GLYPHS, 'aerial']);
        }
    }

    /** Every mark the section can draw is a file this module ships. */
    public function testEveryGlyphTheSectionCanDrawHasAFile(): void
    {
        $directory = \dirname(__DIR__, 3).'/assets/icons/patrol';

        foreach ([...PatrolBaseDefaults::GLYPHS, 'aerial', 'circle-question'] as $glyph) {
            self::assertFileExists($directory.'/'.$glyph.'.svg');
        }
    }
}
