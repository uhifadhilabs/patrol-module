<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use UhifadhiLabs\PatrolBundle\Module\PatrolModuleProvider;

final class PatrolModuleProviderTest extends TestCase
{
    public function testDeclaresThePatrolsModule(): void
    {
        $provider = new PatrolModuleProvider('pressure');

        self::assertSame('patrols', $provider->slug());
        self::assertSame('Patrols', $provider->name());
        self::assertSame('pressure', $provider->category());
        self::assertSame('GPS field tracks', $provider->dataSource());
        self::assertSame('footprints', $provider->icon());
        self::assertSame('patrol_dashboard', $provider->entryRoute());
        self::assertSame([], $provider->permissions());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('biodiversity', new PatrolModuleProvider('biodiversity')->category());
    }
}
