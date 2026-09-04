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

namespace Uhifadhi\Patrol\Twig;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `patrol_url(route, parameters)` — the URL of a screen, or NULL where the
 * installation did not mount it.
 *
 * WHY A MODULE NEEDS THIS AT ALL. Every patrol screen prints a breadcrumb, and a
 * patrol breadcrumb names screens this module does not own: the area register
 * and the area itself belong to uhifadhi/area-module, and the per-area module
 * grid belongs to the seam and is not built yet. Twig's own `path()` THROWS on a
 * route that is not registered, so a template naming any of them is a template
 * that takes the whole page down in an installation that mounted one screen
 * fewer.
 *
 * That is not a hypothetical. Area's own screens are optional — its README calls
 * both of its shell seams "route-tolerant: unmount a route and its tab or row is
 * simply absent rather than every page failing" — and this is the same tolerance
 * applied to a crumb rather than to a tab.
 *
 * A NULL IS RENDERED AS PLAIN TEXT, never as a dead link and never as a silently
 * missing step: the trail still says where the page sits, and only the parts a
 * visitor can actually travel to are clickable.
 *
 * Registered as a Twig extension rather than resolved in each controller because
 * a crumb is page furniture: six screens print one, they print the same prefix,
 * and threading it through six render calls would be six chances to build it
 * differently.
 */
final class PatrolTrailExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('patrol_url', $this->url(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function url(string $route, array $parameters = []): ?string
    {
        try {
            return $this->urls->generate($route, $parameters);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
