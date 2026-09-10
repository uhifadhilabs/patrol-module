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

use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * DEVKIT's content collector, played by a fixture. devkit installs through
 * require-dev and is absent here, so the tag it reads is what a test has to read
 * instead — and reading the tag rather than the service id is the point: a
 * provider that was never tagged is invisible to devkit, and must be invisible
 * here too.
 *
 * It collects every declaration in the kernel, this module's and the core's
 * alike, which is what makes it usable for seeding a module whose content is
 * built on another's: `dependsOn()` names the key, and the key is what this is
 * indexed by.
 */
final readonly class CollectedContentProviders
{
    /**
     * @param iterable<ContentProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, ContentProviderInterface> keyed by the provider's key
     */
    public function byKey(): array
    {
        $byKey = [];
        foreach ($this->providers as $provider) {
            $byKey[$provider->key()] = $provider;
        }

        return $byKey;
    }
}
