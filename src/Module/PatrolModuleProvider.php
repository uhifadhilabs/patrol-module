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

use Uhifadhi\ModuleContracts\ModulePermission;
use Uhifadhi\ModuleContracts\ModuleProviderInterface;
use Uhifadhi\ModuleContracts\ModuleProviderTrait;
use Uhifadhi\Patrol\Controller\PatrolRecordController;

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
     * controller in this bundle stamps on its routes so the seam can close them
     * where an area has parked this module — two places that must never drift,
     * so there is only one string.
     */
    public const string SLUG = 'patrols';

    /**
     * THE SEAM'S ROUTE MARKER, spelled out rather than imported — the one place
     * this bundle writes the string.
     *
     * Every controller here stamps `_uhifadhi_module: patrols` on its routes so
     * that where an area has parked this module, the seam closes its pages with
     * a 404 before a controller runs. The seam publishes the same string as
     * `UhifadhiSeamBundle::MODULE_ROUTE_DEFAULT`, and importing it would be the
     * tidier code and the wrong dependency: the seam is a **dev** requirement
     * here (see composer.json, and the `suggest` entry that says what an
     * installation loses without it), so a class-constant reference in a route
     * attribute would make it a hard one — a bundle that cannot be installed
     * without the catalogue it merely registers with.
     *
     * A route default nothing reads is inert, which is exactly what this is on
     * an installation with no seam, or with a seam older than 0.2. That the two
     * spellings agree is asserted where the seam IS installed —
     * Functional\ParkedModuleTest.
     */
    public const string SEAM_ROUTE_DEFAULT = '_uhifadhi_module';

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
        return [new ModulePermission(
            PatrolRecordController::RECORD_PERMISSION,
            'Patrols',
            'Record',
            'Record patrols: import a GPS track or log one by hand, and add the observations made along the way.',
        )];
    }
}
