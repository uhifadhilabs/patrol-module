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

namespace Uhifadhi\Patrol\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PATROL'S PLATES ARE SIZED BY PATROL'S OWN DESIGN, and by one property.
 *
 * A plate carries a real height off `--map-plate-height` and refuses to stretch,
 * so a screen states its height by setting that property on the card the plate
 * is in. Sizing one with `min-height` plus `flex: 1` is what made a plate grow
 * to whatever the tallest sibling in its row happened to be.
 *
 * A TEXT CHECK over the shipped sheet, and that is the limit of what it
 * promises: it catches a plate sized some other way or against some other
 * screen's number, never a plate that renders wrongly for another reason.
 * Rendered fidelity is a sweep, not a unit test.
 */
final class PlateSizingTest extends TestCase
{
    /** The height the patrols coverage map states in the design. */
    private const string COVERAGE_HEIGHT = 'min(56vh, 540px)';

    public function testTheCoveragePlateIsTheHeightTheDesignStates(): void
    {
        self::assertMatchesRegularExpression(
            '/\.patrol-coverage-plate \{[^}]*--map-plate-height: '.preg_quote(self::COVERAGE_HEIGHT, '/').'/',
            self::stylesheet(),
        );
    }

    /** The height a record page's map states in the design. */
    private const string RECORD_HEIGHT = '400px';

    /**
     * A RECORD PAGE'S PLATE IS 400px AND THE CARD ENDS WHERE THE MAP ENDS — the
     * design's `.recgrid > .c.plate-fill`, whose viewer is `flex: none; height:
     * 400px` and whose card is `align-self: start`. A map is a fixed-size plate
     * of imagery, not a column of facts, so the facts beside it are free to run
     * past it rather than dragging the imagery down with them.
     *
     * The height is said with the ONE property a plate is sized by; the card's
     * refusal to stretch is the card's own, because the row above stretches its
     * columns and a plate can no longer refuse for itself.
     */
    public function testTheRecordPlateIsTheHeightTheDesignStates(): void
    {
        $sheet = self::stylesheet();

        self::assertMatchesRegularExpression(
            '/\.patrol-detail-row > \.patrol-plate-fill \{[^}]*--map-plate-height: '.preg_quote(self::RECORD_HEIGHT, '/').'/',
            $sheet,
        );
        self::assertMatchesRegularExpression(
            '/\.patrol-detail-row > \.patrol-plate-fill \{[^}]*align-self: start/',
            $sheet,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.patrol-detail-row > \.patrol-plate-fill \{[^}]*min-height/',
            $sheet,
            'A floor under a fixed height is a floor that can only fight it.',
        );
    }

    /**
     * And BOTH record pages wear the hook, or the rule above dresses one screen
     * and the design's other map is a different size from its own twin.
     *
     * @return iterable<string, array{string}>
     */
    public static function recordPages(): iterable
    {
        yield 'patrol' => ['patrol/show.html.twig'];
        yield 'observation' => ['observation/show.html.twig'];
    }

    #[DataProvider('recordPages')]
    public function testBothRecordPagesWearThatHook(string $template): void
    {
        $markup = file_get_contents(\dirname(__DIR__, 3).'/templates/'.$template);
        self::assertIsString($markup);

        self::assertStringContainsString('patrol-plate patrol-plate-fill', $markup);
    }

    /**
     * THE PLATE'S OWN LAYOUT IS THE ATLAS'S. A module that sizes it any other
     * way is the module whose map reads differently from every other one — and
     * with a real height on the plate, a min-height or a flex here does nothing
     * except mislead the next reader.
     */
    public function testThisSheetSizesNoPlateAnyOtherWay(): void
    {
        $sheet = self::stylesheet();

        self::assertDoesNotMatchRegularExpression('/\.map-plate[^{]*\{[^}]*min-height/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\.map-plate[^{]*\{[^}]*flex:/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\.map-plate[^{]*\{[^}]*height: *[^v]/', $sheet);
    }

    /**
     * A sibling module's plate height appears nowhere here: a screen sized
     * against another module's screen drifts while every test stays green.
     */
    public function testNoSiblingModulesPlateHeightIsStatedHere(): void
    {
        self::assertStringNotContainsString('min(46vh,440px)', self::stylesheet());
        self::assertStringNotContainsString('min(46vh, 440px)', self::stylesheet());
    }

    /**
     * THE PLATE'S INTERNALS ARE THE ATLAS'S TOO — the imagery frame, the
     * chrome, the legend and Leaflet's own controls.
     */
    public function testThisSheetStatesNothingAboutThePlatesInternals(): void
    {
        // Rules, not prose: the sheet's own header names host vocabulary it
        // deliberately leaves alone, and naming it is the opposite of styling it.
        foreach (['viewer', 'leaflet', 'map-chrome', 'map-legend'] as $theirs) {
            self::assertDoesNotMatchRegularExpression(
                '/^[^\/\n][^\n]*\.'.preg_quote($theirs, '/').'[^\n]*\{/m',
                self::stylesheet(),
                \sprintf('.%s is the atlas\'s; a module that styles it makes its own map the odd one out.', $theirs),
            );
        }
    }

    private static function stylesheet(): string
    {
        $sheet = file_get_contents(\dirname(__DIR__, 3).'/public/patrol.css');
        self::assertIsString($sheet);

        return $sheet;
    }
}
