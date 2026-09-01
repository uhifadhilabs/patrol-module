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

/**
 * DEV/TEST-ONLY stub of uhifadhi's own class of this name, carried in the
 * bundle's autoload-dev so the module's overview contribution compiles, is
 * phpstan'd and is tested standalone. COPIED VERBATIM, signatures and
 * validation alike: the moment it drifts from the host's the tests stop
 * proving anything about the seam. NOT shipped — autoload-dev is dropped on
 * install, and inside uhifadhi the real class is loaded instead.
 *
 * ONE LAYER OF THE OPERATIONAL PLATE, WITH ITS LEGEND.
 *
 * THE MAP-LEGEND CONTRACT: every layer ships a legend, and the same layer
 * renders identically everywhere it is drawn. The host holds one plate; each
 * layer on it is contributed by the module that owns the data, carries its own
 * swatch, and the legend is grouped by contributor — which is the only way a
 * person can tell why a layer vanished.
 *
 * WHAT IS ON BY DEFAULT IS OPERATIONAL — boundary, stations, today's tracks,
 * open incidents. WHAT IS OFF BY DEFAULT IS SCIENTIFIC — tree-cover loss, fire
 * detections. The science is not deleted: it is one click away in the layer
 * list, with its legend, exactly where it was. It is simply no longer the first
 * thing an area manager is shown at 07:00.
 *
 * THE GEOMETRY TRAVELS AS GEOJSON, in a FeatureCollection the layer builds for
 * one area. A layer with nothing to draw returns an empty collection and still
 * ships its legend entry — "no open incidents today" is an answer, and a legend
 * that appears and disappears with the data is a legend nobody can rely on.
 */
final readonly class MapLayer
{
    /** A line: the swatch is a rule, not a block. */
    public const string STYLE_LINE = 'line';
    /** A filled shape or a point: the swatch is a block. */
    public const string STYLE_FILL = 'fill';

    /**
     * @param string               $id       unique across the plate; `<module>.<layer>` by convention
     * @param string               $swatch   the legend's colour, as CSS — A LAYER'S COLOUR IS DATA, so it
     *                                       is the same in light and dark and the module states it once
     * @param int|null             $count    what the legend prints after the label ("Open · 31"); null prints nothing
     * @param bool                 $on       whether the layer is drawn before anybody touches the legend
     * @param bool                 $live     whether the surface's one polling endpoint refreshes it
     * @param array<string, mixed> $features a GeoJSON FeatureCollection
     */
    public function __construct(
        public string $id,
        public string $moduleSlug,
        public string $groupLabel,
        public string $label,
        public string $swatch,
        public array $features,
        public string $style = self::STYLE_FILL,
        public ?int $count = null,
        public bool $on = true,
        public bool $live = false,
    ) {
        if ('' === $id || '' === $label || '' === $groupLabel) {
            throw new \InvalidArgumentException('A map layer needs an id, a label and the group its legend sits under.');
        }
        if (!\in_array($style, [self::STYLE_LINE, self::STYLE_FILL], true)) {
            throw new \InvalidArgumentException(\sprintf('Map layer "%s" asks for the swatch style "%s", which the legend does not draw.', $id, $style));
        }
        if ('FeatureCollection' !== ($features['type'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('Map layer "%s" must carry a GeoJSON FeatureCollection, empty if it has nothing to draw.', $id));
        }
    }
}
