<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use UhifadhiLabs\Patrol\Controller\PatrolRecordController;
use UhifadhiLabs\Patrol\Module\PatrolModuleProvider;

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
    }

    public function testDeclaresTheRecordPermissionForTheHostToAssign(): void
    {
        $permissions = new PatrolModuleProvider('pressure')->permissions();

        self::assertCount(1, $permissions);
        // The exact attribute the recording screens check — declared here, and
        // granted to nobody by the bundle.
        self::assertSame(PatrolRecordController::RECORD_PERMISSION, $permissions[0]->value);
        self::assertSame('patrols.record', $permissions[0]->value);
        self::assertSame('Patrols', $permissions[0]->umbrella);
        self::assertSame('Record', $permissions[0]->action);
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('biodiversity', new PatrolModuleProvider('biodiversity')->category());
    }
}
