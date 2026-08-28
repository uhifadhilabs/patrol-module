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

namespace Uhifadhi\Model;

/**
 * A whole layout of one surface, named and described: "adopt this design
 * wholesale".
 *
 * A widget library lets a person assemble a dashboard one widget at a time,
 * which is the right tool once they know what they want and a poor one on the
 * first visit. A preset is the other door: the design directions a surface was
 * drawn in, each applicable in one click. The catalogue's groups already ARE
 * those directions — a preset is the same direction expressed as a layout.
 *
 * The layout is total: a widget LISTED is on, at the width listed, in the listed
 * position; a widget ABSENT is off. There is no third state, so adopting a
 * preset can never leave a stray widget from the previous arrangement behind.
 *
 * Like a {@see WidgetCatalog} this is CODE, not input: a preset naming a widget
 * the surface does not ship, or a width it does not offer, throws at
 * construction (the width check needs the catalogue, so it happens when the
 * preset is attached to one).
 */
final readonly class WidgetPreset
{
    /**
     * Widget id => span. A PHP array preserves insertion order, so this is the
     * ordered layout AND it cannot name the same widget twice.
     *
     * @var array<string, int>
     */
    public array $layout;

    /**
     * @param string             $id          stable across releases: it is what the apply URL names
     * @param string             $description one line, in the design's own words: what this direction costs
     * @param array<string, int> $layout      widget id => span, in the order the design lays them out
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        array $layout,
    ) {
        if ('' === $id || '' === $label) {
            throw new \InvalidArgumentException('A widget preset needs an id and a label.');
        }
        if ([] === $layout) {
            throw new \InvalidArgumentException(\sprintf('Preset "%s" must show at least one widget — "everything off" is not a design.', $id));
        }
        foreach ($layout as $widgetId => $cols) {
            if ('' === $widgetId) {
                throw new \InvalidArgumentException(\sprintf('Preset "%s" lists a widget with no id.', $id));
            }
            if (!\in_array($cols, Widget::GRID_SPANS, true)) {
                throw new \InvalidArgumentException(\sprintf('Preset "%s" gives "%s" a span of %d, which is not one of the grid\'s.', $id, $widgetId, $cols));
            }
        }

        $this->layout = $layout;
    }

    /**
     * The widgets this design shows, in its own order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->layout);
    }

    /** Whether this design shows the widget at all. */
    public function shows(string $id): bool
    {
        return isset($this->layout[$id]);
    }

    /** The span this design gives the widget. */
    public function cols(string $id): int
    {
        return $this->layout[$id]
            ?? throw new \InvalidArgumentException(\sprintf('Preset "%s" does not show the widget "%s".', $this->id, $id));
    }
}
