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

namespace Uhifadhi\Patrol\Tests\Integration;

use Uhifadhi\Patrol\Widget\PatrolWidgets;
use Uhifadhi\Widget\Registry\WidgetSurfaceRegistry;
use Uhifadhi\Widget\Service\WidgetEndpoint;
use Uhifadhi\Widget\Service\WidgetService;

/**
 * THE PATROLS DASHBOARD IS A DECLARED SURFACE OF THE REAL WIDGET FRAMEWORK.
 *
 * Before this module joined the fleet it compiled against doubles of the old
 * application's widget classes, which meant the one thing it could never prove
 * was the thing that matters: that an installation booting
 * uhifadhi/widget-module finds this module's dashboard in the registry. A
 * surface nothing registers is a surface `widget:prune` reads as an orphan — it
 * would delete every layout anybody ever saved of a patrols dashboard, in every
 * area, and the module would never notice.
 *
 * So the assertion is about the REGISTRY rather than about the catalogue: the
 * catalogue is a value object any unit test can build, and being findable is the
 * part that only exists once the tag is on the service.
 */
final class WidgetSurfaceTest extends IntegrationTestCase
{
    public function testTheDashboardIsRegisteredAsASurfaceOfTheRealFramework(): void
    {
        $registry = self::getContainer()->get('test_public.'.WidgetSurfaceRegistry::class);
        self::assertInstanceOf(WidgetSurfaceRegistry::class, $registry);

        self::assertTrue(
            $registry->has(PatrolWidgets::SURFACE),
            'the patrols dashboard is not in the widget registry, so widget:prune reads its layouts as orphans',
        );
        self::assertSame(
            PatrolWidgets::SURFACE,
            $registry->catalog(PatrolWidgets::SURFACE)?->surface,
        );
    }

    /**
     * The registry holds THIS module's surface and no stranger's.
     *
     * The kernel boots team and area for their models, and both are modules with
     * dashboards of their own; {@see OnlyThisModulesSurfacesPass} takes their
     * tags off, so what is asserted above stays an assertion about patrols
     * rather than about a dependency's release notes.
     */
    public function testNobodyElsesSurfaceIsInThisSuitesRegistry(): void
    {
        $registry = self::getContainer()->get('test_public.'.WidgetSurfaceRegistry::class);
        self::assertInstanceOf(WidgetSurfaceRegistry::class, $registry);

        self::assertSame([PatrolWidgets::SURFACE], $registry->surfaces());
    }

    public function testTheFrameworkItselfIsTheRealOneAndNotADouble(): void
    {
        $container = self::getContainer();

        self::assertInstanceOf(WidgetService::class, $container->get('test_public.'.WidgetService::class));
        self::assertInstanceOf(WidgetEndpoint::class, $container->get('test_public.'.WidgetEndpoint::class));
    }
}
