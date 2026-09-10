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

namespace Uhifadhi\Patrol\Module;

use Uhifadhi\Contracts\ModulePermission;
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;
use Uhifadhi\Patrol\Controller\PatrolRecordController;
use Uhifadhi\Patrol\Controller\PatrolTaxonomyController;

/**
 * Declares the one module this bundle contributes — "Patrols". It owns its
 * pages (entryRoute), so the host links straight to the patrol dashboard
 * instead of rendering it through the generic module page.
 */
final class PatrolModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    /**
     * THE SLUG, ONCE. It is the answer below, and it is also what every
     * controller in this bundle stamps on its routes so the registry can close
     * them where an area has parked this module — two places that must never
     * drift, so there is only one string.
     */
    public const string SLUG = 'patrols';

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return self::SLUG;
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

    /**
     * Declared, never granted: the host folds this into its permission
     * catalogue for admins to assign, and it vanishes with the module on
     * uninstall. The value is the exact attribute the two recording screens
     * (import GPX, log patrol) check.
     *
     * THE SENTENCE IS THE ROW. "Patrols · Record" names the words this module
     * chose; the description says what ticking the box hands over, and it is
     * printed under the name in the host's matrix — where somebody is deciding
     * whether this person should be able to put field effort on the record.
     *
     * @return list<ModulePermission>
     */
    public function permissions(): array
    {
        return [
            new ModulePermission(
                PatrolRecordController::RECORD_PERMISSION,
                'Patrols',
                'Record',
                'Record patrols: import a GPS track or log one by hand, and add the observations made along the way.',
            ),
            new ModulePermission(
                PatrolTaxonomyController::MANAGE_PERMISSION,
                'Patrols',
                'Manage',
                'Manage this area\'s observation taxonomy: the kinds a ranger logs against and the sub-categories under them.',
            ),
        ];
    }
}
