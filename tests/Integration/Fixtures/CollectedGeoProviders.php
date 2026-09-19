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

namespace Uhifadhi\Patrol\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Performance\PerformanceGeoProviderInterface;

/**
 * THE PERFORMANCE PAGE'S GROUND COLLECTOR, played by a fixture — the core
 * publishes the seam and nothing in it consumes the tag yet, so the tag is
 * what a test has to read.
 *
 * READING THE TAG AND NOT THE SERVICE ID IS THE POINT. A provider that was
 * never tagged is a perfect class the page never mentions, which is the
 * classic silent failure of this platform's seams; asking the container for
 * `patrol.performance_geo` would pass with the tag deleted.
 */
final readonly class CollectedGeoProviders
{
    /**
     * @param iterable<PerformanceGeoProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, PerformanceGeoProviderInterface> keyed by the module's slug
     */
    public function byModule(): array
    {
        $byModule = [];
        foreach ($this->providers as $provider) {
            $byModule[$provider->moduleSlug()] = $provider;
        }

        return $byModule;
    }
}
