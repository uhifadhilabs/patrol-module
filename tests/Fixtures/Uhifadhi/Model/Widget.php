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
 * One widget a dashboard surface ships: what it is called, which group the
 * widget library files it under, how wide it sits by default and how wide a
 * person may make it.
 *
 * A definition is CODE, not input — a surface writes it once and the container
 * boots with it — so a malformed one throws here, at construction, rather than
 * degrading quietly the way an untrusted stored preference does.
 */
final readonly class Widget
{
    /**
     * The twelfths the grid offers, widest first. A widget picks the subset it is
     * designed for; a plate that only ever reads as a half-width chart simply
     * never lists 12.
     */
    public const array GRID_SPANS = [12, 9, 6, 3];

    /** @var list<int> */
    public array $spans;

    /**
     * @param string      $id    stable across releases: it is what a stored preference names
     * @param string      $group id of a {@see WidgetGroup} the same catalogue declares
     * @param int         $cols  the span the surface's design gives it
     * @param list<int>   $spans the spans a person may choose, widest first
     * @param bool        $on    whether a person who never opened the library sees it
     * @param string|null $note  ONE line for the add-widget picker: what this widget shows. Added
     *                           after the fact and last, with a default, so every existing
     *                           `new Widget(...)` call keeps compiling untouched. A widget without
     *                           one simply shows no line — the picker renders the widget itself,
     *                           which is the answer the line only summarises.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $group,
        public int $cols = 12,
        array $spans = self::GRID_SPANS,
        public bool $on = true,
        public ?string $note = null,
    ) {
        if ('' === $id || '' === $label || '' === $group) {
            throw new \InvalidArgumentException('A widget needs an id, a label and a group.');
        }
        if ([] === $spans) {
            throw new \InvalidArgumentException(\sprintf('Widget "%s" must offer at least one span.', $id));
        }
        foreach ($spans as $span) {
            if (!\in_array($span, self::GRID_SPANS, true)) {
                throw new \InvalidArgumentException(\sprintf('Widget "%s" offers span %d, which is not one of the grid\'s.', $id, $span));
            }
        }
        // Widest first: the library draws the width chips in declaration order, so
        // a catalogue that listed them backwards would render backwards on that
        // one surface and nowhere else.
        $widestFirst = $spans;
        rsort($widestFirst);
        if ($spans !== $widestFirst) {
            throw new \InvalidArgumentException(\sprintf('Widget "%s" must declare its spans widest first.', $id));
        }
        if (!\in_array($cols, $spans, true)) {
            throw new \InvalidArgumentException(\sprintf('Widget "%s" defaults to a span of %d, which it does not offer.', $id, $cols));
        }

        $this->spans = $spans;
    }
}
