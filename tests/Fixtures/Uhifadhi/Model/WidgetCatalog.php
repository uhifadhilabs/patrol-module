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
 * THE catalogue of one dashboard surface: which widgets it ships, what each is
 * called, which headed section of the library it belongs to, how wide it sits by
 * default and how wide a person may make it.
 *
 * A surface registers exactly one of these and the dashboard, the widget library
 * and the save endpoint all read it, so a widget can never exist on one screen
 * and not the other. The `surface` string is what a stored preference row is
 * keyed by, so it is stable across releases: two surfaces may both ship a widget
 * called "map" and neither ever sees the other's row.
 *
 * Declaration order is the default order; the design's own layout is simply the
 * order the widgets are listed in.
 *
 * PRESETS are optional: a surface may also name whole layouts a person can adopt
 * in one click ({@see WidgetPreset}). A surface that ships none is complete
 * without them — the library's preset strip is guarded on `presets|length` and
 * simply is not drawn.
 *
 * THE ACTIVE-PRESET MODEL (2026-08): a dashboard renders exactly ONE preset —
 * one the surface ships, or one the person saved. There is no anonymous layout,
 * so the catalogue's own composition (declaration order, each widget's $on and
 * $cols) is itself a preset: {@see builtins()} leads the strip with it whenever
 * no declared preset already IS that layout. Built-ins are immutable; the only
 * way one changes is a copy into the person's own presets.
 *
 * ADDITIONS ARE APPENDED WITH DEFAULTS. Every constructor argument past
 * `$presets` was added after module bundles were already building against this
 * class, so every existing `new WidgetCatalog($surface, $groups, $widgets)` and
 * `…, $presets)` call keeps compiling and keeps meaning what it meant.
 */
final readonly class WidgetCatalog
{
    /** @var array<string, WidgetGroup> */
    private array $groups;

    /** @var array<string, Widget> */
    private array $widgets;

    /** @var array<string, WidgetPreset> */
    private array $presets;

    /** The id {@see builtins()} gives the catalogue's own composition when it has to name it. */
    public const string DEFAULT_PRESET_ID = 'default';

    /**
     * The catalogue's own composition AS A PRESET, or null where it is already
     * one of the declared designs (or where there is nothing on by default —
     * "everything off" is not a design). Built ONCE, here, so every caller gets
     * the same object and the strip's leading card is a stable identity rather
     * than a fresh one per render.
     */
    private ?WidgetPreset $shipped;

    /**
     * @param string             $surface            identifies the dashboard, e.g. 'departments'
     * @param list<WidgetGroup>  $groups             the library's headed sections, in the order it draws them
     * @param list<Widget>       $widgets            the surface's widgets, in the order the design lays them out
     * @param list<WidgetPreset> $presets            whole layouts this surface offers, in the order the library lists them
     * @param string|null        $defaultPreset      id of the built-in a person who has never chosen opens on;
     *                                               null means the first built-in, which is the catalogue's own composition
     * @param string|null        $defaultLabel       what to call the catalogue's own composition when it leads the strip
     * @param string|null        $defaultDescription its one line — what this shipped screen is
     */
    public function __construct(
        public string $surface,
        array $groups,
        array $widgets,
        array $presets = [],
        private ?string $defaultPreset = null,
        ?string $defaultLabel = null,
        ?string $defaultDescription = null,
    ) {
        if ('' === $surface) {
            throw new \InvalidArgumentException('A widget catalogue must name its surface.');
        }
        if ([] === $groups || [] === $widgets) {
            throw new \InvalidArgumentException(\sprintf('The "%s" widget catalogue must ship at least one group and one widget.', $surface));
        }

        $byGroupId = [];
        foreach ($groups as $group) {
            if (isset($byGroupId[$group->id])) {
                throw new \InvalidArgumentException(\sprintf('The "%s" widget catalogue declares the group "%s" twice.', $surface, $group->id));
            }
            $byGroupId[$group->id] = $group;
        }

        $byWidgetId = [];
        foreach ($widgets as $widget) {
            if (isset($byWidgetId[$widget->id])) {
                throw new \InvalidArgumentException(\sprintf('The "%s" widget catalogue declares the widget "%s" twice.', $surface, $widget->id));
            }
            if (!isset($byGroupId[$widget->group])) {
                throw new \InvalidArgumentException(\sprintf('Widget "%s" is filed under "%s", which the "%s" catalogue does not declare.', $widget->id, $widget->group, $surface));
            }
            $byWidgetId[$widget->id] = $widget;
        }

        // A preset is validated HERE rather than in its own constructor because
        // only the catalogue knows which widgets exist and what each may span: a
        // design that names a retired widget, or hands a half-width chart the
        // full row, is a programming error and must not boot.
        $byPresetId = [];
        foreach ($presets as $preset) {
            if (isset($byPresetId[$preset->id])) {
                throw new \InvalidArgumentException(\sprintf('The "%s" widget catalogue declares the preset "%s" twice.', $surface, $preset->id));
            }
            foreach ($preset->layout as $widgetId => $cols) {
                if (!isset($byWidgetId[$widgetId])) {
                    throw new \InvalidArgumentException(\sprintf('Preset "%s" shows "%s", which the "%s" catalogue does not ship.', $preset->id, $widgetId, $surface));
                }
                if (!\in_array($cols, $byWidgetId[$widgetId]->spans, true)) {
                    throw new \InvalidArgumentException(\sprintf('Preset "%s" gives "%s" a span of %d, which that widget does not offer.', $preset->id, $widgetId, $cols));
                }
            }
            $byPresetId[$preset->id] = $preset;
        }

        $this->groups = $byGroupId;
        $this->widgets = $byWidgetId;
        $this->presets = $byPresetId;

        // THE ACTIVE-PRESET MODEL has no room for a layout that is not a preset,
        // so the composition this surface ships with becomes one — unless a
        // declared design already IS it, in which case naming it twice would be
        // two cards saying the same thing.
        $shipped = $this->defaultLayout();
        $alreadyNamed = [] === $shipped || isset($byPresetId[self::DEFAULT_PRESET_ID]);
        foreach ($byPresetId as $preset) {
            $alreadyNamed = $alreadyNamed || $preset->layout === $shipped;
        }

        $this->shipped = $alreadyNamed ? null : new WidgetPreset(
            self::DEFAULT_PRESET_ID,
            $defaultLabel ?? 'Default layout',
            $defaultDescription
                ?? 'The layout this surface ships with — every widget it puts on a dashboard before anyone changes anything.',
            $shipped,
        );
    }

    /**
     * Every widget id, in catalogue (default) order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->widgets);
    }

    public function has(string $id): bool
    {
        return isset($this->widgets[$id]);
    }

    public function get(string $id): Widget
    {
        return $this->widgets[$id]
            ?? throw new \InvalidArgumentException(\sprintf('The "%s" surface ships no widget "%s".', $this->surface, $id));
    }

    /**
     * The library's headed sections, in the order it draws them.
     *
     * @return list<WidgetGroup>
     */
    public function groups(): array
    {
        return array_values($this->groups);
    }

    /**
     * The whole layouts this surface offers, in the order the library lists them.
     * Empty is a perfectly good answer: presets are optional furniture.
     *
     * @return list<WidgetPreset>
     */
    public function presets(): array
    {
        return array_values($this->presets);
    }

    /**
     * Null rather than a throw: the id arrives in a URL, so it is untrusted.
     *
     * It answers for EVERY built-in, which since the active-preset model includes
     * the catalogue's own composition ({@see builtins()}) — a strictly wider set
     * than {@see presets()}, so no existing caller loses an answer it had.
     */
    public function preset(string $id): ?WidgetPreset
    {
        if (isset($this->presets[$id])) {
            return $this->presets[$id];
        }

        foreach ($this->builtins() as $builtin) {
            if ($builtin->id === $id) {
                return $builtin;
            }
        }

        return null;
    }

    /**
     * THE COMPOSITION THIS SURFACE SHIPS WITH, as a layout: every widget whose
     * definition says `on`, in declaration order, at its default span.
     *
     * @return array<string, int>
     */
    public function defaultLayout(): array
    {
        $layout = [];
        foreach ($this->widgets as $id => $widget) {
            if ($widget->on) {
                $layout[$id] = $widget->cols;
            }
        }

        return $layout;
    }

    /**
     * EVERY built-in preset this surface ships, in the order the library lists
     * them. The composition the surface ships with is one of them: if no declared
     * preset already IS that layout, it LEADS the strip as one, because the
     * active-preset model has no room for a layout that is not a preset.
     *
     * A catalogue with no widgets on by default (every `on: false`) has no such
     * composition to name — "everything off" is not a design — so it simply gets
     * its declared presets.
     *
     * @return list<WidgetPreset>
     */
    public function builtins(): array
    {
        $declared = array_values($this->presets);
        if (null === $this->shipped) {
            return $declared;
        }

        array_unshift($declared, $this->shipped);

        return $declared;
    }

    /**
     * The built-in a person who has never chosen opens on. A surface that names
     * one that no longer exists falls back to the first, exactly as a stored
     * reference does — a retired design must never leave a dashboard blank.
     */
    public function defaultPresetId(): string
    {
        $builtins = $this->builtins();
        if ([] === $builtins) {
            return self::DEFAULT_PRESET_ID;
        }
        if (null !== $this->defaultPreset && null !== $this->preset($this->defaultPreset)) {
            return $this->defaultPreset;
        }

        return $builtins[0]->id;
    }

    /**
     * The spans this widget may take, widest first.
     *
     * @return list<int>
     */
    public function spans(string $id): array
    {
        return $this->get($id)->spans;
    }

    /** The allowed span nearest the asked-for one; ties go to the wider. */
    public function clamp(string $id, int $cols): int
    {
        $best = null;
        foreach ($this->spans($id) as $span) {
            if (null === $best || abs($span - $cols) < abs($best - $cols)) {
                $best = $span;
            }
        }

        return $best ?? $this->get($id)->cols;
    }
}
