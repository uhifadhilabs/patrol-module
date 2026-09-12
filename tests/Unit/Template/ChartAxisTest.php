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
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * THE THREE BAR CHARTS SHARE ONE AXIS RULE, and it is the design's: the axis
 * maximum is the smallest multiple of three that still covers the largest bar,
 * and never less than three — so the three gridlines land on whole numbers and
 * the plot has a width to measure against even on an empty month.
 *
 * The rule is written in Twig, so this test READS THE SHIPPED EXPRESSION out of
 * each template and evaluates that, rather than restating the formula: a chart
 * that invents its own axis fails here, and so does one whose axis collapses on
 * a value the others never see. "By station" and "per week" count patrols, whose
 * maximum is a whole number; "Effort by ranger" plots HOURS, whose maximum is a
 * fraction as soon as the month's longest patrol is shorter than an hour.
 */
final class ChartAxisTest extends TestCase
{
    /** The templates that plot bars against a rounded axis, and the value each measures. */
    private const array CHARTS = [
        'dashboard/_w_chstation.html.twig' => 'station',
        'dashboard/_w_chweek.html.twig' => 'week',
        'dashboard/_w_effort.html.twig' => 'effort',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function charts(): iterable
    {
        foreach (self::CHARTS as $template => $prefix) {
            yield $template => [$template, $prefix];
        }
    }

    #[DataProvider('charts')]
    public function testEveryBarChartRoundsItsAxisUpToAMultipleOfThree(string $template, string $prefix): void
    {
        self::assertMatchesRegularExpression(
            '/\{% set '.$prefix.'Axis = '.preg_quote(self::rule($prefix.'Max'), '/').' %\}/',
            file_get_contents(\dirname(__DIR__, 3).'/templates/'.$template) ?: '',
            $template.' must round its axis up to a multiple of three, never down.',
        );
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function maxima(): iterable
    {
        yield 'a month with nothing to plot' => [0.0, '3'];
        yield 'a one-minute patrol' => [0.0167, '3'];
        yield 'an exact multiple of three' => [3.0, '3'];
        yield 'three and a fifth hours' => [3.2, '6'];
        yield 'seven patrols from one station' => [7.0, '9'];
    }

    #[DataProvider('maxima')]
    public function testTheAxisIsTheSmallestCoveringMultipleOfThree(float $maximum, string $axis): void
    {
        $twig = new Environment(new ArrayLoader(['axis' => '{{ '.self::rule('maximum').' }}']));

        self::assertSame($axis, $twig->render('axis', ['maximum' => $maximum]));
    }

    /** The one axis expression, spelled with the name of the value it measures. */
    private static function rule(string $maximum): string
    {
        return "max(3, ({$maximum} / 3)|round(0, 'ceil') * 3)";
    }
}
