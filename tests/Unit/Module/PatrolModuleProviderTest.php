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

    /**
     * THE MODULE DECLARES NO FLAT PERMISSIONS ANY MORE. What it lets somebody
     * act on is declared as concerns, through the access seam
     * ({@see \Uhifadhi\Patrol\Access\PatrolConcerns}) — a thing to act on
     * with the verbs it supports, instead of one string per action. Two
     * catalogues naming the same power would let an organization grant it
     * twice and revoke it once, so this one is empty on purpose.
     */
    public function testDeclaresNoFlatPermissionsBecauseItDeclaresConcerns(): void
    {
        self::assertSame([], new PatrolModuleProvider('operations')->permissions());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('biodiversity', new PatrolModuleProvider('biodiversity')->category());
    }
}
