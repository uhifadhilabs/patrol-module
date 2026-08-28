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

namespace Uhifadhi\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\WidgetCustomPreset;
use Uhifadhi\Entity\WidgetPreference;
use Uhifadhi\Exception\InvalidWidgetPreferenceException;
use Uhifadhi\Exception\UnknownWidgetPresetException;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetPreset;
use Uhifadhi\Repository\WidgetCustomPresetRepository;
use Uhifadhi\Repository\WidgetPreferenceRepository;

/**
 * The platform's widget framework: one person's layout of one dashboard surface,
 * resolved from that surface's {@see WidgetCatalog} and their stored row.
 *
 * Generalised from the patrols module's battle-tested service — same merge,
 * validation and clamping semantics, with the catalogue handed in rather than
 * hard-coded, so every dashboard in the app arranges itself the same way and a
 * module ships a catalogue rather than a copy of this algebra.
 *
 * The merge and validation rules are static and pure — stored preferences are
 * untrusted input (a browser wrote them, and a release may have retired a widget
 * since), so reading them can never throw and writing them can never store an id
 * or a span the catalogue does not offer.
 *
 * PRESETS are whole layouts rather than one person's current one: the designs a
 * surface ships ({@see WidgetCatalog::presets()}) and the ones a person saved
 * ({@see WidgetCustomPreset}). Both are converted to an ordinary payload and
 * written through {@see save()} — there is exactly one path into a
 * stored row, and adopting a design is not an exception to it.
 *
 * THE ACTIVE-PRESET MODEL (2026-08)
 * ---------------------------------
 * There is NO ANONYMOUS LAYOUT. A dashboard renders exactly one preset, and the
 * stored row names it ({@see WidgetPreference::getActiveKind()}). A person who
 * has never chosen is active on the surface's default built-in
 * ({@see WidgetCatalog::defaultPresetId()}), which is why a fresh dashboard is
 * still "a preset" and the library's strip always has exactly one Active card.
 *
 * BUILT-INS ARE IMMUTABLE. They are the designs the product ships, so
 * {@see save()} REFUSES a layout while one is active: the way a shipped design
 * changes is {@see copyBuiltinPreset()}, and the copy is what gets edited.
 * Editing while a CUSTOM preset is active WRITES THROUGH to that preset's own
 * row — the preset is the layout, so there is nothing else to save.
 *
 * SOURCE COMPATIBILITY. Every method below kept the signature module bundles
 * were already calling; the model's needs arrived as arguments appended with
 * defaults ({@see saveCustomPreset()}) or as new methods. Behaviour changed in
 * exactly one place, and deliberately: applying or creating a preset now also
 * makes it active, because in this model that is what applying MEANS.
 */
final class WidgetService
{
    /** As long as a name can be and still read on a preset card — and the stored column's length. */
    public const int NAME_MAX = 60;

    public function __construct(
        private readonly WidgetPreferenceRepository $preferences,
        private readonly WidgetCustomPresetRepository $savedPresets,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * This person's layout of this surface, complete and ordered. A null user (an
     * anonymous request) always gets the catalogue's defaults. The area is null
     * on an org-wide surface.
     *
     * @return list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}>
     */
    public function resolve(WidgetCatalog $catalog, ?int $userId, ?Uuid $areaUuid = null): array
    {
        if (null === $userId) {
            return self::merge($catalog, null);
        }

        $row = $this->preferences->findOneForUser($catalog->surface, $userId, $areaUuid);
        // THE ACTIVE PRESET IS THE LAYOUT. Reading it back from the preset itself
        // rather than from the row's cached copy is what makes "editing a custom
        // preset edits your dashboard" true with nothing to keep in step.
        $active = $this->activePreset($catalog, $row);
        if (null !== $active) {
            return self::merge($catalog, self::presetPayload($catalog, $active));
        }

        // A row written before the active-preset model: it has a layout and names
        // no preset. Honour it rather than silently rearranging their dashboard.
        return self::merge($catalog, $row?->getPrefs());
    }

    /**
     * WHICH PRESET this person's dashboard is showing, as {kind, id, label}.
     * Tolerant: a reference to a preset that no longer exists (a deleted custom
     * one, a design a release retired) falls back to the surface's default
     * built-in, so a dashboard is never left pointing at nothing.
     *
     * @return array{kind: string, id: string, label: string}
     */
    public function activeRef(WidgetCatalog $catalog, ?int $userId, ?Uuid $areaUuid = null): array
    {
        $row = null !== $userId
            ? $this->preferences->findOneForUser($catalog->surface, $userId, $areaUuid)
            : null;

        if (WidgetPreference::KIND_MINE === $row?->getActiveKind()) {
            $mine = $this->activeCustom($catalog, $row, $areaUuid);
            if (null !== $mine) {
                return [
                    'kind' => WidgetPreference::KIND_MINE,
                    'id' => (string) $mine->getUuidString(),
                    'label' => $mine->getName(),
                ];
            }
        }
        if (WidgetPreference::KIND_DESIGN === $row?->getActiveKind()) {
            $design = $catalog->preset((string) $row->getActivePreset());
            if (null !== $design) {
                return ['kind' => WidgetPreference::KIND_DESIGN, 'id' => $design->id, 'label' => $design->label];
            }
        }

        // A catalogue that ships no preset at all still has to answer, so the id
        // stands on its own and the label falls back to the framework's word.
        $fallback = $catalog->preset($catalog->defaultPresetId());

        return [
            'kind' => WidgetPreference::KIND_DESIGN,
            'id' => $catalog->defaultPresetId(),
            'label' => null !== $fallback ? $fallback->label : 'Default layout',
        ];
    }

    /**
     * Store this person's layout, canonicalised. Throws rather than storing a
     * payload the catalogue does not recognise.
     *
     * WHERE IT GOES depends on what is active. On one of the person's OWN presets
     * it writes THROUGH to that preset — the preset is the layout, it stays
     * active, and there is nothing else to save. On a BUILT-IN it refuses: the
     * designs the product ships are immutable, and the way to change one is
     * {@see copyBuiltinPreset()}. The library never offers the edit at all, so a
     * refusal here means something bypassed the screen.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidWidgetPreferenceException
     */
    public function save(WidgetCatalog $catalog, int $userId, array $payload, ?Uuid $areaUuid = null): void
    {
        $row = $this->row($catalog, $userId, $areaUuid);

        if (WidgetPreference::KIND_DESIGN === $row->getActiveKind()
            || (null === $row->getActiveKind() && null === $row->getId())) {
            throw new InvalidWidgetPreferenceException('That design is one the product ships, so it cannot be edited. Make a copy to customize it.');
        }

        $mine = WidgetPreference::KIND_MINE === $row->getActiveKind()
            ? $this->activeCustom($catalog, $row, $areaUuid)
            : null;

        if (null !== $mine) {
            // The canvas holds exactly the composition, so the payload IS the
            // preset's layout; the row's own copy is then that preset read back
            // through the catalogue, so the two can never drift.
            $composed = self::composedLayout($catalog, $payload);
            $mine->setLayout($composed);
            $prefs = self::presetPayload($catalog, self::customPreset((string) $mine->getUuidString(), $mine->getName(), $composed));
        } else {
            // A row written before the active-preset model: it has a layout and
            // names no preset, so the old complete-picture shape is what it means.
            $prefs = self::validate($catalog, $payload);
        }

        $row->setPrefs($prefs);
        $this->entityManager->persist($row);
        $this->entityManager->flush();
    }

    /**
     * Adopt one of the surface's designs wholesale: the preset's widgets, in its
     * order, at its widths; everything else off.
     *
     * It goes through {@see save()} rather than writing a row itself, so the
     * tolerant ranking, the span clamping and the completeness rule stay in ONE
     * place and a preset can never store something the library could not.
     *
     * @throws InvalidWidgetPreferenceException when the surface names no such design
     */
    public function applyPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, string $presetId): void
    {
        $preset = self::presetOf($catalog, $presetId);

        $this->store($catalog, $userId, $areaUuid, $preset, WidgetPreference::KIND_DESIGN, $preset->id);
    }

    /**
     * MAKE A COPY TO CUSTOMIZE — the only way a shipped design ever changes.
     *
     * The copy is one of the person's own presets, so it is editable, and it
     * becomes ACTIVE immediately: you asked to customize this design, so this
     * design (as yours) is what your dashboard shows while you do. Nothing forks
     * behind anyone's back — this happens because a person pressed the button
     * that says so.
     *
     * @param string|null $rawName what to call it; null takes the design's own name and marks it a copy
     *
     * @throws InvalidWidgetPreferenceException when the surface names no such design, or the name is unusable
     */
    public function copyBuiltinPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, string $presetId, ?string $rawName = null): WidgetCustomPreset
    {
        $source = self::presetOf($catalog, $presetId);
        $name = null !== $rawName && '' !== trim($rawName)
            ? self::presetName($rawName)
            : $this->freeName($catalog, $userId, $areaUuid, $source->label.' — copy');

        // The layout is TAKEN THROUGH the catalogue, so a copy of a design is
        // canonical from its first moment rather than inheriting a span the
        // catalogue has since narrowed.
        $layout = self::captureLayout(self::merge($catalog, self::presetPayload($catalog, $source)));

        $row = new WidgetCustomPreset($catalog->surface, $userId, $areaUuid, $name, $layout);
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        $this->applyCustomPreset($catalog, $userId, $areaUuid, self::uuidOf($row));

        return $row;
    }

    /**
     * This person's own saved layouts for this surface, as presets — the strip
     * draws them beside the shipped ones with no idea which is which.
     *
     * Their DESCRIPTION is generated rather than empty: a card in this strip
     * wears the same three registers as a shipped one, and the sentence a saved
     * layout has to offer is what it holds. Stated here so the server-rendered
     * card and the script-rendered one can never word it differently.
     *
     * @return list<WidgetPreset>
     */
    public function customPresets(WidgetCatalog $catalog, ?int $userId, ?Uuid $areaUuid = null): array
    {
        if (null === $userId) {
            return [];
        }

        return array_map(
            static fn (WidgetCustomPreset $row): WidgetPreset => new WidgetPreset(
                (string) $row->getUuidString(),
                $row->getName(),
                self::countLine($row->getLayout()),
                $row->getLayout(),
            ),
            $this->savedPresets->findForUser($catalog->surface, $userId, $areaUuid),
        );
    }

    /**
     * Keep a layout under a name of their own, and put it on.
     *
     * The layout is the one COMPOSED on the library's canvas when the caller
     * hands one in, and the dashboard as it stands otherwise — `$layout` was
     * appended with a default so every existing two-argument call still means
     * "save what I am looking at". A composed one is validated through the
     * catalogue like any other, so a payload naming a retired widget is refused
     * before a row is touched rather than stored and dropped later.
     *
     * It becomes ACTIVE, because in the active-preset model that is what saving a
     * composition means: you composed the dashboard you wanted, so that is the
     * dashboard you get.
     *
     * Saving under a name they already used OVERWRITES that preset rather than
     * refusing: it is their own layout under their own word, two cards reading
     * "Morning check" would be worse than a replacement, and the library asks
     * first through the confirm modal. The table's unique index says the same
     * thing, so a race ends as a replacement too.
     *
     * @param array<string, mixed>|null $layout the composed layout as a save payload; null means "the dashboard as it is"
     *
     * @throws InvalidWidgetPreferenceException on an unusable name or an empty dashboard
     */
    public function saveCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, string $rawName, ?array $layout = null): WidgetCustomPreset
    {
        $name = self::presetName($rawName);
        $captured = null !== $layout
            ? self::composedLayout($catalog, $layout)
            : self::captureLayout($this->resolve($catalog, $userId, $areaUuid));
        // Refuses an empty dashboard, and refuses it BEFORE any row is touched.
        self::customPreset('draft', $name, $captured);

        $row = $this->savedPresets->findOneNamed($catalog->surface, $userId, $areaUuid, $name)
            ?? new WidgetCustomPreset($catalog->surface, $userId, $areaUuid, $name);
        $row->setLayout($captured);

        $this->entityManager->persist($row);
        $this->entityManager->flush();

        $this->applyCustomPreset($catalog, $userId, $areaUuid, self::uuidOf($row));

        return $row;
    }

    /**
     * Put one of their own saved layouts back on. Through {@see save()} like every
     * other write, so drift is tolerated and the stored row stays canonical.
     *
     * @throws UnknownWidgetPresetException when the preset is not theirs, or gone
     */
    public function applyCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid): void
    {
        $row = $this->ownedPreset($catalog, $userId, $areaUuid, $uuid);
        $preset = self::customPreset((string) $row->getUuidString(), $row->getName(), $row->getLayout());

        $this->store($catalog, $userId, $areaUuid, $preset, WidgetPreference::KIND_MINE, $preset->id);
    }

    /**
     * @throws InvalidWidgetPreferenceException on an unusable name
     * @throws UnknownWidgetPresetException     when the preset is not theirs, or gone
     */
    public function renameCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid, string $rawName): void
    {
        $row = $this->ownedPreset($catalog, $userId, $areaUuid, $uuid);
        $name = self::presetName($rawName);

        $clash = $this->savedPresets->findOneNamed($catalog->surface, $userId, $areaUuid, $name);
        if (null !== $clash && $clash !== $row) {
            // Renaming onto an existing name is the one place overwriting would
            // destroy a layout the person did not have on screen.
            throw new InvalidWidgetPreferenceException(\sprintf('You already have a preset called “%s”.', $name));
        }

        $row->setName($name);
        $this->entityManager->flush();
    }

    /**
     * Throw one of their own layouts away. Deleting the ACTIVE one cannot leave
     * the dashboard pointing at nothing, so the row goes back to the surface's
     * default design — the same answer {@see activeRef()} gives a dangling
     * reference, reached deliberately here rather than left to be tolerated.
     *
     * @throws UnknownWidgetPresetException when the preset is not theirs, or gone
     */
    public function deleteCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid): void
    {
        $preset = $this->ownedPreset($catalog, $userId, $areaUuid, $uuid);
        $wasActive = $uuid->toRfc4122() === $this->activeRef($catalog, $userId, $areaUuid)['id'];

        $this->entityManager->remove($preset);
        $this->entityManager->flush();

        if ($wasActive) {
            $this->reset($catalog, $userId, $areaUuid);
        }
    }

    /** Back to the catalogue's layout — no row means the defaults, so reset deletes. */
    public function reset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid = null): void
    {
        $row = $this->preferences->findOneForUser($catalog->surface, $userId, $areaUuid);
        if (null === $row) {
            return;
        }

        $this->entityManager->remove($row);
        $this->entityManager->flush();
    }

    /**
     * Stored preferences over the catalogue defaults. Never throws: a row written
     * by an older release, or edited by hand, degrades to the defaults rather
     * than taking the dashboard down.
     *
     * @param array<string, mixed>|null $stored
     *
     * @return list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}>
     */
    public static function merge(WidgetCatalog $catalog, ?array $stored): array
    {
        $order = self::readOrder($catalog, $stored['order'] ?? null);
        $widgets = \is_array($stored['widgets'] ?? null) ? $stored['widgets'] : [];

        $resolved = [];
        foreach ($order as $id) {
            $definition = $catalog->get($id);
            $entry = $widgets[$id] ?? null;
            $entry = \is_array($entry) ? $entry : [];

            $resolved[] = [
                'id' => $id,
                'label' => $definition->label,
                'group' => $definition->group,
                'on' => \array_key_exists('on', $entry) ? (bool) $entry['on'] : $definition->on,
                'cols' => $catalog->clamp($id, isset($entry['cols']) && is_numeric($entry['cols'])
                    ? (int) $entry['cols']
                    : $definition->cols),
                'spans' => $definition->spans,
            ];
        }

        return $resolved;
    }

    /**
     * The canonical stored shape for a payload from the library screen. Every
     * catalogue widget ends up in the result, so a stored row is always a
     * complete picture and a later read needs no defaulting.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{order: list<string>, widgets: array<string, array{on: bool, cols: int}>}
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function validate(WidgetCatalog $catalog, array $payload): array
    {
        $rawOrder = $payload['order'] ?? [];
        if (!\is_array($rawOrder)) {
            throw new InvalidWidgetPreferenceException('The widget order must be a list of widget ids.');
        }
        $rawWidgets = $payload['widgets'] ?? [];
        if (!\is_array($rawWidgets)) {
            throw new InvalidWidgetPreferenceException('The widget preferences must be a map of widget id to settings.');
        }

        $order = [];
        foreach ($rawOrder as $id) {
            if (!\is_string($id) || !$catalog->has($id)) {
                throw new InvalidWidgetPreferenceException(self::unknown($catalog, $id));
            }
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        foreach ($catalog->ids() as $id) {
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $widgets = [];
        foreach ($order as $id) {
            $entry = $rawWidgets[$id] ?? [];
            if (!\is_array($entry)) {
                throw new InvalidWidgetPreferenceException(\sprintf('The settings for widget "%s" must be an object.', $id));
            }
            $definition = $catalog->get($id);
            $widgets[$id] = [
                'on' => \array_key_exists('on', $entry) ? (bool) $entry['on'] : $definition->on,
                'cols' => $catalog->clamp($id, isset($entry['cols']) && is_numeric($entry['cols'])
                    ? (int) $entry['cols']
                    : $definition->cols),
            ];
        }

        foreach (array_keys($rawWidgets) as $id) {
            if (!\is_string($id) || !$catalog->has($id)) {
                throw new InvalidWidgetPreferenceException(self::unknown($catalog, $id));
            }
        }

        return ['order' => $order, 'widgets' => $widgets];
    }

    /**
     * The design this id names. A preset id arrives in a URL, so an unknown one
     * is a REFUSED PREFERENCE (the endpoint answers 422), never a 500.
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function presetOf(WidgetCatalog $catalog, string $presetId): WidgetPreset
    {
        return $catalog->preset($presetId)
            ?? throw new InvalidWidgetPreferenceException(\sprintf('"%s" is not a design of the "%s" dashboard.', $presetId, $catalog->surface));
    }

    /**
     * A preset read as a library payload: its widgets on, in its order, at its
     * widths — and every other widget the surface ships explicitly off, so
     * adopting a design leaves nothing of the previous arrangement behind.
     *
     * TOLERANT, like {@see merge()} and for the same reason: a SHIPPED preset is
     * catalogue-checked at boot, but a preset a person saved is a stored row, and
     * a release may have retired a widget or narrowed its spans since. A ghost is
     * dropped, a width is clamped, and the widgets the surface has gained arrive
     * off — applying an old preset is never an error page.
     *
     * @return array{order: list<string>, widgets: array<string, array{on: bool, cols: int}>}
     */
    public static function presetPayload(WidgetCatalog $catalog, WidgetPreset $preset): array
    {
        $widgets = [];
        foreach ($preset->layout as $id => $cols) {
            if (!$catalog->has($id)) {
                continue;
            }
            $widgets[$id] = ['on' => true, 'cols' => $catalog->clamp($id, $cols)];
        }
        foreach ($catalog->ids() as $id) {
            if (!isset($widgets[$id])) {
                $widgets[$id] = ['on' => false, 'cols' => $catalog->get($id)->cols];
            }
        }

        /** @var list<string> $order */
        $order = array_keys(array_filter($widgets, static fn (array $widget): bool => $widget['on']));

        return ['order' => $order, 'widgets' => $widgets];
    }

    /**
     * A LIBRARY PAYLOAD READ AS A PRESET'S LAYOUT — the composition it states,
     * and only that.
     *
     * It is not {@see captureLayout()} over {@see merge()}, and the difference
     * matters: merge() fills a widget the payload does not mention with the
     * CATALOGUE's answer for it, which is right for a stored preference (a row
     * must be a complete picture) and wrong for a composition. The canvas holds
     * exactly the composition, so a widget it does not name is OFF — filling one
     * in would put widgets on a preset nobody added.
     *
     * The order is the payload's own, with anything explicitly switched on but
     * left out of the order appended: a caller that states `on` and forgets to
     * list it meant to include it.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, int>
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function composedLayout(WidgetCatalog $catalog, array $payload): array
    {
        $validated = self::validate($catalog, $payload);
        /** @var array<string, mixed> $rawWidgets */
        $rawWidgets = \is_array($payload['widgets'] ?? null) ? $payload['widgets'] : [];
        /** @var array<int, mixed> $rawOrder */
        $rawOrder = \is_array($payload['order'] ?? null) ? $payload['order'] : [];

        $named = [];
        foreach ($rawOrder as $id) {
            if (\is_string($id)) {
                $named[$id] = true;
            }
        }

        $layout = [];
        foreach ($validated['order'] as $id) {
            $entry = \is_array($rawWidgets[$id] ?? null) ? $rawWidgets[$id] : [];
            $explicitlyOff = \array_key_exists('on', $entry) && !$entry['on'];
            $explicitlyOn = \array_key_exists('on', $entry) && (bool) $entry['on'];
            if ($explicitlyOff || (!isset($named[$id]) && !$explicitlyOn)) {
                continue;
            }
            $layout[$id] = $validated['widgets'][$id]['cols'];
        }

        return $layout;
    }

    /**
     * A resolved layout read as a preset's layout: the widgets that are ON, in
     * their order, at their width. Absence IS off, exactly as in a shipped
     * preset, so "save this dashboard as a preset" and "ship this design" write
     * the same thing.
     *
     * @param list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}> $resolved
     *
     * @return array<string, int>
     */
    public static function captureLayout(array $resolved): array
    {
        $layout = [];
        foreach ($resolved as $widget) {
            if ($widget['on']) {
                $layout[$widget['id']] = $widget['cols'];
            }
        }

        return $layout;
    }

    /**
     * A person's own saved layout, as the very same value object a surface ships.
     * Nothing downstream distinguishes the two.
     *
     * @param array<string, int> $layout
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function customPreset(string $id, string $name, array $layout): WidgetPreset
    {
        if ([] === $layout) {
            // Saving an empty dashboard would store a preset that, applied, shows
            // nothing — a trap dressed as a feature.
            throw new InvalidWidgetPreferenceException('Switch at least one widget on before saving this layout as a preset.');
        }

        return new WidgetPreset($id, $name, '', $layout);
    }

    /**
     * What a saved layout has to say about itself, in one line. Kept beside
     * {@see customPresets()} because assets/widgets.js writes the same sentence
     * for a card it draws after a save, and the two must match word for word.
     *
     * @param array<string, int> $layout
     */
    public static function countLine(array $layout): string
    {
        $count = \count($layout);

        return \sprintf('%d widget%s, in your order and at your widths.', $count, 1 === $count ? '' : 's');
    }

    /**
     * A name a person typed: trimmed, saying something, and short enough to read
     * on a card. Untrusted input, so a refusal is a 422 and never an exception
     * page.
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function presetName(string $raw): string
    {
        $name = trim($raw);
        if ('' === $name) {
            throw new InvalidWidgetPreferenceException('A preset needs a name.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidWidgetPreferenceException(\sprintf('A preset name may be at most %d characters.', self::NAME_MAX));
        }

        return $name;
    }

    /**
     * A stored order, skipping ids this surface no longer ships and appending the
     * ones it gained. Unreadable input simply means "the catalogue order".
     *
     * @return list<string>
     */
    private static function readOrder(WidgetCatalog $catalog, mixed $stored): array
    {
        $order = [];
        if (\is_array($stored)) {
            foreach ($stored as $id) {
                if (\is_string($id) && $catalog->has($id) && !\in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }
        foreach ($catalog->ids() as $id) {
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    /**
     * THE ONE WRITE that makes a preset the dashboard: its payload into the row's
     * layout, and its identity into the row's active reference. The two are set
     * together because they are one fact — "this is the preset you are on" — and
     * a row that held one without the other would be exactly the anonymous layout
     * this model does not have.
     *
     * Private, and not routed through {@see save()}, because save() enforces the
     * immutability rule and APPLYING a built-in is not editing one.
     */
    private function store(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, WidgetPreset $preset, string $kind, string $presetId): void
    {
        $row = $this->row($catalog, $userId, $areaUuid);
        $row->setPrefs(self::validate($catalog, self::presetPayload($catalog, $preset)));
        $row->setActive($kind, $presetId);

        $this->entityManager->persist($row);
        $this->entityManager->flush();
    }

    /** This person's row for this surface, existing or fresh; unflushed either way. */
    private function row(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid): WidgetPreference
    {
        return $this->preferences->findOneForUser($catalog->surface, $userId, $areaUuid)
            ?? new WidgetPreference($catalog->surface, $userId, $areaUuid);
    }

    /**
     * The active preset as a value object, or null where the row names none that
     * still exists — the caller then falls back, which is where the tolerance in
     * this framework always lives.
     */
    private function activePreset(WidgetCatalog $catalog, ?WidgetPreference $row): ?WidgetPreset
    {
        if (null === $row) {
            // Never chosen: the surface's own answer, which is still a preset.
            return $catalog->preset($catalog->defaultPresetId());
        }
        if (WidgetPreference::KIND_DESIGN === $row->getActiveKind()) {
            return $catalog->preset((string) $row->getActivePreset())
                ?? $catalog->preset($catalog->defaultPresetId());
        }
        if (WidgetPreference::KIND_MINE === $row->getActiveKind()) {
            $mine = $this->activeCustom($catalog, $row, $row->getAreaUuid());

            return null !== $mine
                ? self::customPreset((string) $mine->getUuidString(), $mine->getName(), $mine->getLayout())
                : $catalog->preset($catalog->defaultPresetId());
        }

        return null;
    }

    /** The custom-preset row a 'mine' reference names, if it is still theirs. */
    private function activeCustom(WidgetCatalog $catalog, WidgetPreference $row, ?Uuid $areaUuid): ?WidgetCustomPreset
    {
        $reference = $row->getActivePreset();
        if (null === $reference || !Uuid::isValid($reference)) {
            return null;
        }

        return $this->savedPresets->findOwned(
            $catalog->surface,
            $row->getUserId(),
            $areaUuid,
            Uuid::fromString($reference),
        );
    }

    /**
     * A name nothing of theirs already wears, by counting up. Copying twice must
     * make a second card rather than replacing the first — that is the whole
     * point of a copy, and it is the one place the overwrite-by-name rule would
     * destroy work nobody asked to lose.
     */
    private function freeName(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, string $wanted): string
    {
        $base = mb_substr(trim($wanted), 0, self::NAME_MAX);
        $name = $base;
        for ($n = 2; null !== $this->savedPresets->findOneNamed($catalog->surface, $userId, $areaUuid, $name); ++$n) {
            $suffix = ' '.$n;
            $name = mb_substr($base, 0, self::NAME_MAX - mb_strlen($suffix)).$suffix;
        }

        return $name;
    }

    /** The UUID a just-persisted preset was given; absent is a programming error, never input. */
    private static function uuidOf(WidgetCustomPreset $preset): Uuid
    {
        return $preset->getUuid()
            ?? throw new \LogicException('A persisted widget preset always carries a UUID.');
    }

    /**
     * The ownership check, in the lookup itself: someone else's preset and a
     * deleted one are the same answer, so a URL tells nobody what exists.
     *
     * @throws UnknownWidgetPresetException
     */
    private function ownedPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid): WidgetCustomPreset
    {
        return $this->savedPresets->findOwned($catalog->surface, $userId, $areaUuid, $uuid)
            ?? throw new UnknownWidgetPresetException(\sprintf('You have no saved preset "%s" on the "%s" dashboard.', $uuid->toRfc4122(), $catalog->surface));
    }

    private static function unknown(WidgetCatalog $catalog, mixed $id): string
    {
        return \sprintf(
            '"%s" is not a widget of the "%s" dashboard.',
            \is_string($id) ? $id : \gettype($id),
            $catalog->surface,
        );
    }
}
