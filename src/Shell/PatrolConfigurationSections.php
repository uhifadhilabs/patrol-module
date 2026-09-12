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

namespace Uhifadhi\Patrol\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Patrol\Controller\PatrolVocabularyController;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Model\PatrolBaseDefaults;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Service\PatrolSettingsService;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;

/**
 * WHAT IS ON THE PATROLS CONFIGURE PAGE — five sections, in the order the
 * platform rules and not the order written here: the library, the types, the
 * places, the words, the numbers.
 *
 * TWO OF THEM KEEP AN ADDRESS OF THEIR OWN, and the design is why. The widget
 * library and the observation kinds are each a full screen in the settled
 * design — `widgets.html` and `kinds.html`, each with its own URL, wearing the
 * configure page's heading and the configure page's strip — so they are
 * declared as {@see ConfigurationSection::screen()} rather than bodies the shell
 * renders. There is a second reason and it is the harder one: `sections()` is
 * consulted on EVERY page of this module, and the library's body is assembled
 * from the month's patrols, the coverage buffer, the map plate, the day's live
 * reading, the viewer's presets and a token. Handing that over as a rendered
 * section's variables would build a whole dashboard preview on every request
 * this module serves.
 *
 * A RENDERED SECTION IS BUILT ONLY WHERE IT IS DRAWN. For the same reason, the
 * three rendered sections' variables are gathered only when the request IS the
 * shell's configure page AND names that section; everywhere else each is declared
 * with its label alone, which is all the strip needs. A declaration that queried
 * on every page would make a page frame expensive, and one that queried for all
 * three on every section would make each of them pay for the other two.
 *
 * IT RESOLVES THE REQUEST ITSELF, like every other source in the frame: the
 * shell passes nothing, because it has a slug and not an area.
 */
final readonly class PatrolConfigurationSections implements ConfigurationSectionsInterface
{
    /** The shell's own configure route, which is where a section body is drawn. */
    private const string CONFIGURE_ROUTE = 'shell_module_configure';

    /**
     * THE TWO SECTIONS THIS MODULE NAMES ITSELF, published because the writes
     * behind them redirect back to one by id and an id typed twice is an id that
     * eventually differs.
     *
     * `types` AND NOT `patrol_types`, and the frame decides that rather than
     * taste: the shell's configure route requires a section of
     * `[a-z][a-z0-9-]*`, so an underscore is a section with no address. It is
     * also the word the design's own crumb ends in — "configure / types" — and
     * the module's namespace is what makes it unambiguous.
     */
    public const string TYPES = 'types';

    public const string STATIONS = 'stations';

    public function __construct(
        private RequestStack $requests,
        private AreaOfInterestRepository $areas,
        private PatrolSettingsService $settings,
        private PatrolTypeRepository $types,
        private StationRepository $stations,
        private PatrolVocabularyService $vocabulary,
        /*
         * NULL WHERE THE INSTALLATION RUNS NO SECURITY — and there the Settings
         * form has no route to post to either, so the section renders as a
         * reading of what the area runs on rather than a form that cannot save.
         */
        private ?CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function slug(): string
    {
        return PatrolModuleProvider::SLUG;
    }

    /**
     * The whole heading, to which the shell adds " · configure". Empty when the
     * request names no area — a page the configure route never serves, but a
     * source that answers only on the pages it expects is a source that throws
     * on the one it did not.
     */
    public function heading(): string
    {
        $area = $this->currentArea();

        return null === $area ? '' : $area->getName().' — Patrols';
    }

    public function summary(): string
    {
        return 'Everything this module is set up with in this area, in one place: how its dashboard is composed, '
            .'the words a ranger picks from, and the numbers the module runs on.';
    }

    public function sections(): array
    {
        return [
            ConfigurationSection::screen(
                ConfigurationSection::WIDGETS,
                'Widget library',
                'patrol_widgets',
            ),
            ConfigurationSection::page(
                self::TYPES,
                'Patrol types',
                '@UhifadhiPatrol/configure/_types.html.twig',
                $this->sectionVariables(self::TYPES),
            ),
            ConfigurationSection::page(
                self::STATIONS,
                'Stations',
                '@UhifadhiPatrol/configure/_stations.html.twig',
                $this->sectionVariables(self::STATIONS),
            ),
            // THE WORD IS THIS MODULE'S. The shell prints "Observation kinds"
            // because this line says so; the frame has no vocabulary to impose.
            ConfigurationSection::screen(
                'kinds',
                'Observation kinds',
                'patrol_kinds',
            ),
            ConfigurationSection::page(
                ConfigurationSection::SETTINGS,
                'Settings',
                '@UhifadhiPatrol/configure/_settings.html.twig',
                $this->sectionVariables(ConfigurationSection::SETTINGS),
            ),
        ];
    }

    /**
     * What ONE section's body is given — and nothing at all unless the viewer is
     * on the page that draws it, and on THAT section of it.
     *
     * ONE SECTION'S QUERIES, NEVER THE PAGE'S. `sections()` is consulted on every
     * page this module serves and has to name all five whichever one is being
     * read, so each declaration is built with its label alone everywhere except
     * where it is drawn. Narrowing to the CURRENT section on top of that is what
     * keeps the split honest: opening Stations counts patrols per station and
     * asks the area for its boundary; it has no business also counting patrols
     * per type, and the Patrol types section has no business drawing a map.
     *
     * @return array<string, mixed>
     */
    private function sectionVariables(string $section): array
    {
        $request = $this->requests->getCurrentRequest();
        $area = $this->currentArea();

        if (null === $request || null === $area || self::CONFIGURE_ROUTE !== $request->attributes->get('_route')) {
            return [];
        }

        // The bare address is the widget library's, which is a screen of its own;
        // a request that names no section is therefore never drawing one here.
        $named = $request->attributes->get('section');
        if ($named !== $section) {
            return [];
        }

        $common = [
            'area' => $area,
            'csrfToken' => $this->csrfTokenManager?->getToken(PatrolVocabularyController::CSRF_TOKEN_ID)->getValue() ?? '',
        ];

        return match ($section) {
            self::TYPES => [
                ...$common,
                'types' => $types = $this->seededTypes($area),
                'typeCounts' => $this->types->countPatrolsByArea($area),
                'bases' => PatrolBaseEnum::cases(),
                // WHAT EACH BASE SEEDS AND WHAT MARK A ROW DRAWS, RESOLVED HERE.
                // Both are one `match` over an enum, and a template that reached
                // for them would be a template holding the rule.
                'baseDefaults' => self::baseDefaults(),
                'typeGlyphs' => self::glyphsOf($types),
                'glyphs' => PatrolBaseDefaults::GLYPHS,
                'placements' => ObservationPlacementEnum::cases(),
                'minPaceKmh' => PatrolBaseDefaults::MIN_PACE_KMH,
                'maxPaceKmh' => PatrolBaseDefaults::MAX_PACE_KMH,
                'minBufferM' => PatrolBaseDefaults::MIN_BUFFER_M,
                'maxBufferM' => PatrolBaseDefaults::MAX_BUFFER_M,
            ],
            self::STATIONS => [
                ...$common,
                'stations' => $this->stations->findByArea($area),
                'stationCounts' => $this->stations->countPatrolsByArea($area),
            ],
            default => [
                ...$common,
                'settings' => $this->settings->forArea($area),
                'minGapMinutes' => PatrolSettingsService::MIN_GAP_MINUTES,
                'maxGapMinutes' => PatrolSettingsService::MAX_GAP_MINUTES,
                'minRetentionDays' => PatrolSettingsService::MIN_RETENTION_DAYS,
                'maxRetentionDays' => PatrolSettingsService::MAX_RETENTION_DAYS,
            ],
        };
    }

    /**
     * The area's types, having first been given the installation's words if it
     * has none of its own.
     *
     * AN AREA NOBODY HAS CONFIGURED YET OPENS ON THE INSTALLATION'S WORDS, written
     * in as its own — the one thing `patrol.types` is still for. After this the
     * two have nothing to do with each other: renaming a type here changes this
     * area and no other, and a later config change never reaches back into a list
     * somebody has curated.
     *
     * @return list<PatrolType>
     */
    private function seededTypes(AreaOfInterest $area): array
    {
        $this->vocabulary->seedTypes($area);

        return $this->types->findByArea($area);
    }

    /**
     * What each base prefills, keyed by the wire value the section's radios carry.
     *
     * @return array<string, PatrolBaseDefaults>
     */
    private static function baseDefaults(): array
    {
        $defaults = [];
        foreach (PatrolBaseEnum::cases() as $base) {
            $defaults[$base->value] = PatrolBaseDefaults::of($base);
        }

        return $defaults;
    }

    /**
     * The mark each row draws, keyed by the type's uuid — its own, or its base's,
     * or the question mark a row with no base is asking with.
     *
     * @param list<PatrolType> $types
     *
     * @return array<string, string>
     */
    private static function glyphsOf(array $types): array
    {
        $glyphs = [];
        foreach ($types as $type) {
            $glyphs[$type->getUuid()->toRfc4122()] = PatrolBaseDefaults::glyphOf($type->getBase(), $type->getGlyph());
        }

        return $glyphs;
    }

    private function currentArea(): ?AreaOfInterest
    {
        $uuid = $this->requests->getCurrentRequest()?->attributes->get('uuid');

        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}
