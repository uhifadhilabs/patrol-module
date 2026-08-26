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

namespace UhifadhiLabs\Patrol\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * The bundle's semantic configuration — how a host declares its patrol
 * vocabulary in config/packages/patrol.yaml:
 *
 *   patrol:
 *     types:                            # default: foot, vehicle, drone
 *       foot:    { label: Foot }
 *       vehicle: { label: Vehicle }
 *       drone:   { label: Drone }
 *     observation_categories:           # default: wildlife, sign, infrastructure
 *       wildlife: { label: Wildlife sighting }
 *       sign:     { label: Sign / evidence }
 *
 * The words are host config, never code: a deployment names its own patrol
 * types and observation categories (the taxonomy pattern). Static so the tree
 * is testable with a plain Processor and shared verbatim by configure().
 */
final class PatrolConfiguration
{
    /** @var array<string, string> */
    public const array DEFAULT_TYPES = ['foot' => 'Foot', 'vehicle' => 'Vehicle', 'drone' => 'Drone'];

    /** @var array<string, string> */
    public const array DEFAULT_OBSERVATION_CATEGORIES = [
        'wildlife' => 'Wildlife sighting',
        'sign' => 'Sign / evidence',
        'infrastructure' => 'Infrastructure',
    ];

    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The patrol root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('module_category')
                    ->info('Catalogue category the Patrols module is filed under in each area.')
                    ->defaultValue('pressure')->cannotBeEmpty()
                ->end()
                ->booleanNode('dev_tools')
                    ->info('Register dev-only tooling (patrol:seed:*). Off by default; the recipe enables it via when@dev/when@test — never in prod.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('types')
                    ->info('Patrol types this deployment uses (key = stored value, label = what the UI shows).')
                    ->useAttributeAsKey('key')
                    ->defaultValue(array_map(static fn (string $label): array => ['label' => $label], self::DEFAULT_TYPES))
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('observation_categories')
                    ->info('Observation categories this deployment uses (key = stored value, label = what the UI shows).')
                    ->useAttributeAsKey('key')
                    ->defaultValue(array_map(static fn (string $label): array => ['label' => $label], self::DEFAULT_OBSERVATION_CATEGORIES))
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                /*
                 * There is deliberately NO photo_dir and NO photo_max_bytes here
                 * any more. Where field photographs live, how big they may be
                 * and which types count as a photograph are properties of the
                 * DEPLOYMENT's evidence storage, not of this module — every
                 * module that stores evidence must answer them the same way, and
                 * two modules with two size caps is a deployment that cannot say
                 * what it accepts. They are configured once, under `storage:`
                 * (uhifadhilabs/storage-module).
                 */
                ->floatNode('gap_threshold_minutes')
                    ->info('A pause between consecutive GPX points longer than this counts as a GPS gap — flagged on import, stored with the track, never smoothed.')
                    ->defaultValue(5.0)
                ->end()
            ->end();
    }
}
