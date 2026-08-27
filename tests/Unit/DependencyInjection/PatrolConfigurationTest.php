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

namespace UhifadhiLabs\Patrol\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use UhifadhiLabs\Patrol\DependencyInjection\PatrolConfiguration;

final class PatrolConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $builder = new TreeBuilder('patrol');
        PatrolConfiguration::define($builder->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($builder->buildTree(), ['patrol' => $config]);

        return $processed;
    }

    public function testDefaultsShipAGenericVocabulary(): void
    {
        $config = $this->process([]);

        self::assertSame('pressure', $config['module_category']);
        self::assertFalse($config['dev_tools']);
        self::assertSame(['foot', 'vehicle', 'drone'], array_keys((array) $config['types']));
        self::assertSame(
            ['wildlife', 'sign', 'infrastructure'],
            array_keys((array) $config['observation_categories']),
        );
        self::assertSame(5.0, $config['gap_threshold_minutes']);
        // The retention window the field app's discard sheet promises rangers.
        self::assertSame(90, $config['discard_retention_days']);
    }

    public function testADeploymentSetsItsOwnRetentionWindow(): void
    {
        self::assertSame(30, $this->process(['discard_retention_days' => 30])['discard_retention_days']);
        // Zero is legal and means "purge on the next sweep" — a deployment that
        // keeps nothing is a policy, not a mistake.
        self::assertSame(0, $this->process(['discard_retention_days' => 0])['discard_retention_days']);
    }

    public function testANegativeRetentionWindowIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['discard_retention_days' => -1]);
    }

    public function testAHostNamesItsOwnVocabulary(): void
    {
        $config = $this->process([
            'types' => ['boat' => ['label' => 'Boat'], 'horseback' => ['label' => 'Horseback']],
            'observation_categories' => ['maintenance' => ['label' => 'Maintenance need']],
            'gap_threshold_minutes' => 7.5,
        ]);

        self::assertSame(['boat', 'horseback'], array_keys((array) $config['types']));
        $types = (array) $config['types'];
        self::assertSame(['label' => 'Boat'], $types['boat']);
        self::assertSame(['maintenance'], array_keys((array) $config['observation_categories']));
        self::assertSame(7.5, $config['gap_threshold_minutes']);
    }

    public function testATypeWithoutALabelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['types' => ['boat' => []]]);
    }
}
