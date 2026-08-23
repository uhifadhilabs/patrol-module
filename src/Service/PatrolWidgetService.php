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

namespace UhifadhiLabs\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Patrol\Entity\WidgetPreference;
use UhifadhiLabs\Patrol\Exception\InvalidWidgetPreferenceException;
use UhifadhiLabs\Patrol\Repository\WidgetPreferenceRepository;

/**
 * THE widget catalogue: which widgets the patrols module ships, what each is
 * called, how wide it sits by default and how wide a person may make it. The
 * dashboard, the widget library and the save endpoint all read this one list, so
 * a widget can never exist on one screen and not the other.
 *
 * Defaults are the settled design's own layout (designs/ngoro-patrols-widgets):
 * everything full width except the two charts, which sit half and half.
 *
 * The merge and validation rules are static and pure — stored preferences are
 * untrusted input (a browser wrote them, and a release may have retired a widget
 * since), so reading them can never throw and writing them can never store an id
 * or a span the catalogue does not offer.
 */
final class PatrolWidgetService
{
    /**
     * Spans are twelfths of the grid. Full width is offered only where a widget
     * is designed to fill the row; the two charts are half-width plates and are
     * never offered a span of 12.
     *
     * @var array<string, array{label: string, cols: int, fullWidth: bool}>
     */
    public const array CATALOGUE = [
        'kpis' => ['label' => 'KPI strip', 'cols' => 12, 'fullWidth' => true],
        'map' => ['label' => 'Coverage map', 'cols' => 12, 'fullWidth' => true],
        'log' => ['label' => 'Patrol log', 'cols' => 12, 'fullWidth' => true],
        'feed' => ['label' => 'Feed + mini-map', 'cols' => 12, 'fullWidth' => true],
        'chweek' => ['label' => 'Patrols per week', 'cols' => 6, 'fullWidth' => false],
        'chstation' => ['label' => 'By station', 'cols' => 6, 'fullWidth' => false],
        'cal' => ['label' => 'Patrol calendar', 'cols' => 12, 'fullWidth' => true],
    ];

    /** Widest first, the order the library's width chips are drawn in. */
    private const array SPANS = [12, 9, 6, 3];

    public function __construct(
        private readonly WidgetPreferenceRepository $preferences,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * This person's layout for this area, complete and ordered. A null user (a
     * host without security, or an anonymous request) always gets the defaults.
     *
     * @return list<array{id: string, label: string, on: bool, cols: int, spans: list<int>, fullWidth: bool}>
     */
    public function resolve(Uuid $areaUuid, ?int $userId): array
    {
        $stored = null !== $userId
            ? $this->preferences->findOneByAreaAndUser($areaUuid, $userId)?->getPrefs()
            : null;

        return self::merge($stored);
    }

    /**
     * Store this person's layout, canonicalised. Throws rather than storing a
     * payload the catalogue does not recognise.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidWidgetPreferenceException
     */
    public function save(Uuid $areaUuid, int $userId, array $payload): void
    {
        $prefs = self::validate($payload);

        $row = $this->preferences->findOneByAreaAndUser($areaUuid, $userId)
            ?? new WidgetPreference($areaUuid, $userId);
        $row->setPrefs($prefs);

        $this->entityManager->persist($row);
        $this->entityManager->flush();
    }

    /** Back to the design's layout — no row means the defaults, so reset deletes. */
    public function reset(Uuid $areaUuid, int $userId): void
    {
        $row = $this->preferences->findOneByAreaAndUser($areaUuid, $userId);
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
     * @return list<array{id: string, label: string, on: bool, cols: int, spans: list<int>, fullWidth: bool}>
     */
    public static function merge(?array $stored): array
    {
        $order = self::readOrder($stored['order'] ?? null);
        $widgets = \is_array($stored['widgets'] ?? null) ? $stored['widgets'] : [];

        $resolved = [];
        foreach ($order as $id) {
            $entry = $widgets[$id] ?? null;
            $entry = \is_array($entry) ? $entry : [];
            $spans = self::spans($id);

            $resolved[] = [
                'id' => $id,
                'label' => self::CATALOGUE[$id]['label'],
                'on' => !\array_key_exists('on', $entry) || (bool) $entry['on'],
                'cols' => self::clamp($id, isset($entry['cols']) && is_numeric($entry['cols'])
                    ? (int) $entry['cols']
                    : self::CATALOGUE[$id]['cols']),
                'spans' => $spans,
                'fullWidth' => self::CATALOGUE[$id]['fullWidth'],
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
    public static function validate(array $payload): array
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
            if (!\is_string($id) || !isset(self::CATALOGUE[$id])) {
                throw new InvalidWidgetPreferenceException(\sprintf('"%s" is not a patrol widget.', \is_string($id) ? $id : \gettype($id)));
            }
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        foreach (array_keys(self::CATALOGUE) as $id) {
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
            $widgets[$id] = [
                'on' => !\array_key_exists('on', $entry) || (bool) $entry['on'],
                'cols' => self::clamp($id, isset($entry['cols']) && is_numeric($entry['cols'])
                    ? (int) $entry['cols']
                    : self::CATALOGUE[$id]['cols']),
            ];
        }

        foreach (array_keys($rawWidgets) as $id) {
            if (!\is_string($id) || !isset(self::CATALOGUE[$id])) {
                throw new InvalidWidgetPreferenceException(\sprintf('"%s" is not a patrol widget.', \is_string($id) ? $id : \gettype($id)));
            }
        }

        return ['order' => $order, 'widgets' => $widgets];
    }

    /**
     * The spans a widget may take, widest first.
     *
     * @return list<int>
     */
    public static function spans(string $id): array
    {
        return array_values(array_filter(
            self::SPANS,
            static fn (int $span) => 12 !== $span || self::CATALOGUE[$id]['fullWidth'],
        ));
    }

    /**
     * A stored order, skipping ids this release no longer ships and appending the
     * ones it gained. Unreadable input simply means "the catalogue order".
     *
     * @return list<string>
     */
    private static function readOrder(mixed $stored): array
    {
        $order = [];
        if (\is_array($stored)) {
            foreach ($stored as $id) {
                if (\is_string($id) && isset(self::CATALOGUE[$id]) && !\in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }
        foreach (array_keys(self::CATALOGUE) as $id) {
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    /** The allowed span nearest the asked-for one; ties go to the wider. */
    private static function clamp(string $id, int $cols): int
    {
        $best = null;
        foreach (self::spans($id) as $span) {
            if (null === $best || abs($span - $cols) < abs($best - $cols)) {
                $best = $span;
            }
        }

        return $best ?? self::CATALOGUE[$id]['cols'];
    }
}
