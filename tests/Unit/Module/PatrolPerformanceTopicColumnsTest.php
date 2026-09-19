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

namespace Uhifadhi\Patrol\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Patrol\Module\PatrolPerformanceTopic;

/**
 * WHICH WAY EACH COLUMN IS GOOD — asserted without a kernel, because a
 * polarity is a property of the figure and never of a request.
 *
 * A column that lost its polarity would still render: untinted, its movement
 * uncoloured, and nothing on the page to say a claim had gone missing. That is
 * exactly the kind of silent loss a test has to hold.
 */
final class PatrolPerformanceTopicColumnsTest extends TestCase
{
    public function testTheFourPublishedColumnsAreTheDesignsFour(): void
    {
        self::assertSame(
            ['patrols.patrols', 'patrols.distance', 'patrols.coverage', 'patrols.observations'],
            array_map(static fn (MatrixColumn $column): string => $column->key, PatrolPerformanceTopic::columns()),
        );
    }

    public function testEveryColumnJudgesUpwards(): void
    {
        foreach (PatrolPerformanceTopic::columns() as $column) {
            self::assertSame(
                ColumnPolarity::Up,
                $column->polarity,
                \sprintf('%s measures work done, so more of it is better.', $column->key),
            );
            self::assertTrue($column->polarity->judges(), \sprintf('%s makes a claim, so its movement may be coloured.', $column->key));
        }
    }

    public function testOnlyTheTwoMeasuredColumnsCarryAUnit(): void
    {
        $units = [];
        foreach (PatrolPerformanceTopic::columns() as $column) {
            $units[$column->key] = $column->unit;
        }

        self::assertSame(
            ['patrols.patrols' => '', 'patrols.distance' => 'km', 'patrols.coverage' => '%', 'patrols.observations' => ''],
            $units,
            'A count is counted in nothing; kilometres and a share are not.',
        );
    }

    public function testEveryColumnSaysWhatItMeans(): void
    {
        foreach (PatrolPerformanceTopic::columns() as $column) {
            self::assertNotSame('', $column->caption, \sprintf('%s has nothing in its header title.', $column->key));
        }
    }
}
