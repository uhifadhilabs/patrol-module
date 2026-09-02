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

namespace Uhifadhi\Overview;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetGroup;

/**
 * DEV/TEST-ONLY stub of uhifadhi's own class of this name, carried in the
 * bundle's autoload-dev so the module's overview contribution compiles, is
 * phpstan'd and is tested standalone. COPIED VERBATIM, signatures and
 * validation alike: the moment it drifts from the host's the tests stop
 * proving anything about the seam. NOT shipped — autoload-dev is dropped on
 * install, and inside uhifadhi the real class is loaded instead.
 *
 * THE SEAM A MODULE PUTS WIDGETS ON AN AREA'S OVERVIEW THROUGH.
 *
 * `/areas/{uuid}` is the first surface in the product whose widgets are not
 * written by whoever owns the page. The HOST owns the surface, the grid, the
 * preset framework and the identity of the area; EVERY OPERATIONAL WIDGET
 * arrives here, from a module installed in that area.
 *
 * THE OPEN/CLOSED RULE, WRITTEN DOWN. Installing `uhifadhi/permits-module`
 * in an area adds a headed section to the library, its widgets, and — through
 * the three sibling interfaces — its tiles, its items and its layers. It does
 * not touch this file, the host's overview controller, the grid, or any other
 * module. Uninstalling it removes exactly the same things, and a saved preset
 * that named one of its widgets skips it: the catalogue is assembled per area
 * and the resolver is tolerant.
 *
 * A GROUP HERE IS A CONTRIBUTOR, NOT A DESIGN DIRECTION. Everywhere else in the
 * product a headed section of a widget library is one of the five directions a
 * surface was drawn in, because one module wrote every widget. Here the division
 * that matters is PROVENANCE: a person needs to know that "Out right now" came
 * from Patrols, so that when Patrols is uninstalled its disappearance reads as
 * the system working rather than as a bug. The five directions are still there —
 * they are presets, they are just not the group axis.
 *
 * A MODULE MAY CONTRIBUTE A WHOLE COLUMN as well as individual widgets: one
 * widget that is its entire overview section, heading and cards, stacked. The
 * rule that keeps it honest is the module's to keep — a column may only include
 * widgets the module already contributes on their own, so a card can never read
 * differently in the two.
 *
 * HOW AN IMPLEMENTOR IS COLLECTED. {@see \Uhifadhi\Service\AreaOverviewCatalogue}
 * reads the {@see TAG}, and the tag is applied EXPLICITLY at both ends: a module
 * bundle tags its contributor in its extension, because a reusable bundle is not
 * autoconfigured; a host service carries `#[AutoconfigureTag(self::TAG)]` ON ITS
 * OWN CLASS, because Symfony reads autoconfigure attributes off the definition's
 * own class and PHP does not inherit attributes from an interface — an
 * `#[AutoconfigureTag]` written here would be silently dead. The same constraint
 * is documented, and pinned by a test, on
 * {@see \Uhifadhi\Module\DepartmentKpiProviderInterface}.
 */
interface OverviewContributorInterface
{
    public const string TAG = 'uhifadhi.overview.widget_provider';

    /**
     * The slug of the module these widgets belong to — the same slug its
     * ModuleProviderInterface declares. The host asks a contributor for widgets
     * ONLY when the area has that module switched on, which is what makes an
     * uninstalled module's widgets disappear from the library rather than go
     * blank. The host's own contributor answers {@see HostOverviewContributor::SLUG}.
     */
    public function moduleSlug(): string;

    /**
     * The library's headed section for this contributor: its name, and one line
     * saying what it puts on this page.
     */
    public function group(): WidgetGroup;

    /**
     * The widgets it contributes, in the order the library lists them. Each one's
     * `group` must be the id of the group above.
     *
     * @return list<Widget>
     */
    public function widgets(): array;

    /**
     * A sprintf pattern naming the Twig partial for one widget id, e.g.
     * `'@UhifadhiLabsPatrol/overview/_w_%s.html.twig'`.
     *
     * A PATTERN PER CONTRIBUTOR, not per surface: on every other surface one
     * module wrote every widget, so one pattern was enough. Here each plate is
     * rendered from its own bundle's template namespace, which is also why the
     * host template can contain no widget markup at all.
     */
    public function partialPattern(): string;

    /**
     * Everything this contributor's own partials read, for this one area.
     *
     * Returned as an array rather than resolved per widget because a module's
     * widgets share their reading of the day: computing it once is the difference
     * between one query and nine, and between two cards that agree and two that
     * were measured a second apart.
     *
     * `$now` is handed in rather than read, so every one of these cards is
     * testable at a fixed moment.
     *
     * @return array<string, mixed>
     */
    public function context(AreaOfInterest $area, \DateTimeImmutable $now): array;
}
