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
use Uhifadhi\Patrol\Controller\PatrolRecordController;
use Uhifadhi\Patrol\Controller\PatrolTaxonomyController;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;

final class PatrolModuleProviderTest extends TestCase
{
    public function testDeclaresThePatrolsModule(): void
    {
        $provider = new PatrolModuleProvider('operations');

        self::assertSame('patrols', $provider->slug());
        self::assertSame('Patrols', $provider->name());
        self::assertSame('operations', $provider->category());
        self::assertSame('GPS field tracks', $provider->dataSource());
        self::assertSame('footprints', $provider->icon());
        self::assertSame('patrol_dashboard', $provider->entryRoute());
    }

    public function testDeclaresTheRecordAndManagePermissionsForTheHostToAssign(): void
    {
        $permissions = new PatrolModuleProvider('operations')->permissions();

        // Two tiers, declared here and granted to nobody by the bundle: recording
        // a patrol, and managing this area's observation taxonomy.
        self::assertCount(2, $permissions);

        // The exact attribute the recording screens check.
        self::assertSame(PatrolRecordController::RECORD_PERMISSION, $permissions[0]->value);
        self::assertSame('patrols.record', $permissions[0]->value);
        self::assertSame('Patrols', $permissions[0]->umbrella);
        self::assertSame('Record', $permissions[0]->action);

        // The exact attribute the taxonomy admin checks.
        self::assertSame(PatrolTaxonomyController::MANAGE_PERMISSION, $permissions[1]->value);
        self::assertSame('patrols.manage', $permissions[1]->value);
        self::assertSame('Patrols', $permissions[1]->umbrella);
        self::assertSame('Manage', $permissions[1]->action);
    }

    /**
     * The sentence the host's permission matrix prints under the name. "Patrols ·
     * Record" says which words this module chose; the sentence says what ticking
     * the box hands over, and it is the only part of the row an administrator can
     * actually decide from.
     */
    public function testTheDeclaredPermissionCarriesTheSentenceTheMatrixPrints(): void
    {
        $permissions = new PatrolModuleProvider('operations')->permissions();

        self::assertSame(
            'Record patrols: import a GPS track or log one by hand, and add the observations made along the way.',
            $permissions[0]->description,
        );
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('biodiversity', new PatrolModuleProvider('biodiversity')->category());
    }
}
