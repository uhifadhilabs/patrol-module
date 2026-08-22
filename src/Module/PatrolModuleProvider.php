<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Module;

use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;
use UhifadhiLabs\ModuleContracts\ModuleProviderTrait;

/**
 * Declares the one module this bundle contributes — "Patrols". It owns its
 * pages (entryRoute), so the host links straight to the patrol dashboard
 * instead of rendering it through the generic module page.
 */
final class PatrolModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return 'patrols';
    }

    public function name(): string
    {
        return 'Patrols';
    }

    public function category(): string
    {
        return $this->category;
    }

    public function dataSource(): string
    {
        return 'GPS field tracks';
    }

    public function icon(): string
    {
        return 'footprints';
    }

    public function entryRoute(): string
    {
        return 'patrol_dashboard';
    }
}
