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

namespace Uhifadhi\Module;

use Uhifadhi\Entity\Department;

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Module\DepartmentKpiProviderInterface, carried in
 * autoload-dev so the bundle compiles and tests standalone; the real interface replaces it in-app.
 * Kept BYTE-FOR-BYTE in step with the host's copy — it is a contract, and a stub that drifts is
 * worse than no stub.
 *
 * THE seam a module bundle implements to put figures on a department's performance surfaces.
 *
 * The model canon, restated as a contract:
 *
 *  - A department has no numbers of its own. Everything a performance page shows is an attached
 *    module's KPIs, so this interface is the ONLY way a figure gets there.
 *  - The figures are summed over the areas the module is switched on in — the implementation's
 *    business, since only the module knows what an "area" means to it.
 *  - They are SLICED BY THE RECORDING PERSON'S POSITION: a row counts for a department when the
 *    person who recorded it holds a position filed under that department. One shared module read
 *    by two departments therefore yields two different numbers from the same rows, and neither
 *    department is fenced out of the other's — the split is reporting, never permission.
 *
 * HOW AN IMPLEMENTOR IS COLLECTED. {@see DepartmentKpiService} reads the `uhifadhi.department_kpi`
 * tag, and the tag is applied EXPLICITLY at both ends:
 *
 *  - a MODULE BUNDLE tags its provider in its extension, because a reusable bundle is not
 *    autoconfigured (Symfony's bundle best practices forbid it) — exactly as it already does for
 *    `uhifadhi.module`;
 *  - a HOST service carries `#[AutoconfigureTag(DepartmentKpiProviderInterface::TAG)]` ON ITS OWN
 *    CLASS.
 *
 * That last line is a real constraint, not a style note: Symfony's
 * `RegisterAutoconfigureAttributesPass` reads attributes off the DEFINITION'S OWN CLASS only, and
 * PHP does not inherit attributes from an implemented interface — so an `#[AutoconfigureTag]`
 * written here would be silently dead, and the only symptom would be every figure quietly
 * disappearing from every performance surface. Tagging via `Kernel::registerForAutoconfiguration()`
 * (as `ModuleProviderInterface` does) would collect implementors automatically and is the natural
 * home for this if a host-side provider is ever written.
 * {@see \Uhifadhi\Tests\Integration\DepartmentKpiTagTest} pins all of it.
 */
interface DepartmentKpiProviderInterface
{
    public const string TAG = 'uhifadhi.department_kpi';

    /**
     * The slug of the module whose figures these are — the same slug the module's
     * ModuleProviderInterface declares.
     *
     * The host asks a provider for numbers ONLY when a department attaches this module, so a
     * detached module's plates simply leave the page rather than going to zero.
     */
    public function moduleSlug(): string;

    /**
     * This department's figures for the period containing `$now`, plus the period before it for
     * the month-over-month move.
     *
     * `$now` is handed in rather than read, so a performance page is testable and a period
     * picker is a parameter and not a second code path.
     *
     * Returning `[]` is a legitimate answer — an attached module with nothing to report is a
     * dashed slot, and MUST NOT be a zero.
     *
     * @return list<DepartmentKpi>
     */
    public function kpisFor(Department $department, \DateTimeImmutable $now): array;
}
