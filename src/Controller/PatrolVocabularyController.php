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

namespace Uhifadhi\Patrol\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;
use Uhifadhi\Patrol\Exception\VocabularyConflictException;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Service\GeoService;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Shell\PatrolConfigurationSections;

/**
 * THE WRITES BEHIND THE TWO WORD-LIST SECTIONS — Patrol types and Stations, each
 * a section of the configure page with an address of its own.
 *
 * ONE CONTROLLER FOR BOTH, for the reason {@see PatrolVocabularyService} is one
 * service for both: they are one thing twice. Each takes the same two shapes of
 * write, at the same two shapes of address, and a rule tightened in one of them
 * must hold in the other.
 *
 * TWO SHAPES OF WRITE, BECAUSE THE DESIGN DRAWS TWO.
 *
 *   THE SECTION SAVES IN ONE POST. Every base, glyph and tunable on the page is a
 *   field of the section, and the design draws ONE save row under them; the add
 *   panel's own button submits the same form, so somebody who types a name and
 *   presses Save gets their type either way. Nothing on the section writes on
 *   change.
 *
 *   A ROW'S RENAME, RETIRE AND REACTIVATE ARE EACH THEIR OWN POST. Renaming a
 *   station is not part of choosing a coverage buffer, and a retirement that
 *   rode along with a save nobody meant to make would be a word off the handsets
 *   by accident.
 *
 * LEAN: every action authorizes, checks the token, reads scalars off the request
 * and hands them to a service. What a word MEANS, what a base prefills, what is
 * clamped, what collides and why a retirement never deletes are all
 * {@see PatrolVocabularyService}'s.
 *
 * THERE IS NO GET HERE. Reading a section is the SHELL's configure page, and the
 * shell's route answers GET at these very addresses; a module that also served
 * them would be a second answer to where configuration lives.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final readonly class PatrolVocabularyController
{
    /** Changing the words everybody else must use rides on the kinds' authority. */
    public const string MANAGE_PERMISSION = PatrolTaxonomyController::MANAGE_PERMISSION;

    /**
     * The token id every form on this module's configure page carries — the
     * Settings section's included, because one page with two ids is a page where
     * a token read off one form is refused by the next.
     */
    public const string CSRF_TOKEN_ID = PatrolSettingsController::CSRF_TOKEN_ID;

    /** What a row's buttons may ask for. Anything else is not a button we drew. */
    private const array ACTIONS = ['rename', 'retire', 'reactivate'];

    /**
     * What a point off the world is told. It names the plate rather than the
     * fields, because the fields are hidden and the plate is what a person used.
     */
    private const string POINT_SENTENCE = 'That is not a place on the map. Nothing was saved — drag the marker on the plate and save again.';

    public function __construct(
        private UrlGeneratorInterface $router,
        private PatrolVocabularyService $vocabulary,
        private GeoService $geo,
        private PatrolTypeRepository $types,
        private StationRepository $stations,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    // ── the Patrol types section ──────────────────────────────────────────────

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/types',
        name: 'patrol_types_save',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function saveTypes(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): RedirectResponse {
        $this->guard($request);

        /*
         * EVERY ROW THE PAGE CARRIED, keyed by the uuid its fields were named
         * with. A row the section never drew is not looked up: the fields are
         * walked, not the table, so a stale uuid posted by hand writes nothing
         * instead of 404-ing a save that had eleven good rows in it.
         */
        foreach ($this->types->findByArea($area) as $type) {
            $uuid = $type->getUuid()->toRfc4122();

            $base = PatrolBaseEnum::tryFrom(self::string($request, 'base', $uuid));
            if (null !== $base && $base !== $type->getBase()) {
                $this->vocabulary->setTypeBase($type, $base);
            }

            $this->vocabulary->tuneType(
                $type,
                self::number($request, 'pace_min', $uuid),
                self::number($request, 'pace_max', $uuid),
                self::number($request, 'buffer', $uuid),
                ObservationPlacementEnum::tryFrom(self::string($request, 'placement', $uuid)),
                '' !== ($glyph = self::string($request, 'glyph', $uuid)) ? $glyph : null,
            );
        }

        // THE ADD PANEL IS PART OF THE SAME FORM, so a name typed into it is
        // created whichever of the two buttons was pressed. An empty field is
        // simply nobody adding anything, not a refusal.
        $label = trim($request->request->getString('label'));
        if ('' !== $label) {
            try {
                $created = $this->vocabulary->addType(
                    $area,
                    $label,
                    base: PatrolBaseEnum::tryFrom($request->request->getString('add_base')),
                    glyph: $request->request->getString('add_glyph'),
                );
            } catch (VocabularyConflictException $conflict) {
                return $this->back($request, $area, PatrolConfigurationSections::TYPES, 'error', $conflict->getMessage());
            }

            return $this->back($request, $area, PatrolConfigurationSections::TYPES, 'success', \sprintf(
                '"%s" is a patrol type in this area now.',
                $created->getLabel(),
            ));
        }

        return $this->back($request, $area, PatrolConfigurationSections::TYPES, 'success', 'Saved. These are this area’s patrol types.');
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/types/{type}/{action}',
        name: 'patrol_type_act',
        requirements: ['uuid' => Requirement::UUID, 'type' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function actOnType(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $type,
        string $action,
    ): RedirectResponse {
        $this->guard($request);

        $record = $this->types->findOneByAreaAndUuid($area, $type);
        if (!$record instanceof PatrolType || !\in_array($action, self::ACTIONS, true)) {
            throw new NotFoundHttpException('No such patrol type in this area.');
        }

        try {
            $message = match ($action) {
                'rename' => \sprintf('Renamed to "%s".', $this->vocabulary->renameType($record, $request->request->getString('label'))->getLabel()),
                'retire' => \sprintf('"%s" is retired. Every patrol filed under it keeps it.', $this->vocabulary->retireType($record)->getLabel()),
                default => \sprintf('"%s" is back in use.', $this->vocabulary->reactivateType($record)->getLabel()),
            };
        } catch (VocabularyConflictException $conflict) {
            return $this->back($request, $area, PatrolConfigurationSections::TYPES, 'error', $conflict->getMessage());
        }

        return $this->back($request, $area, PatrolConfigurationSections::TYPES, 'success', $message);
    }

    // ── the Stations section ──────────────────────────────────────────────────

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/stations',
        name: 'patrol_stations_save',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function saveStations(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): RedirectResponse {
        $this->guard($request);

        /*
         * WHERE THE PLATE LEFT ITS MARKER. Null where the two fields hold nothing
         * a map could have produced — a coordinate off the world, or a value typed
         * into a hidden field by hand — and that is told rather than clamped: a
         * station filed at the pole would read as placed.
         */
        $point = $this->geo->pointGeoJson(
            (float) $request->request->getString('point_lat'),
            (float) $request->request->getString('point_lng'),
        );
        if (null === $point) {
            return $this->back($request, $area, PatrolConfigurationSections::STATIONS, 'error', self::POINT_SENTENCE);
        }

        $label = trim($request->request->getString('label'));
        if ('' !== $label) {
            try {
                $created = $this->vocabulary->addStation($area, $label, point: $point);
            } catch (VocabularyConflictException $conflict) {
                return $this->back($request, $area, PatrolConfigurationSections::STATIONS, 'error', $conflict->getMessage());
            }

            return $this->back($request, $area, PatrolConfigurationSections::STATIONS, 'success', \sprintf(
                '"%s" is a station in this area now.',
                $created->getLabel(),
            ));
        }

        // A POINT WITHOUT A NAME BELONGS TO THE ROW THAT ASKED, which is the uuid
        // the plate was bound to. A save that names neither is somebody pressing
        // Save with nothing changed, and that is not an error.
        $asked = $request->request->getString('point');
        $station = '' !== $asked ? $this->stations->findOneByAreaAndUuid($area, $asked) : null;
        if ($station instanceof Station) {
            $this->vocabulary->setStationPoint($station, $point);

            return $this->back($request, $area, PatrolConfigurationSections::STATIONS, 'success', \sprintf(
                '"%s" sets off from there now.',
                $station->getLabel(),
            ));
        }

        return $this->back($request, $area, PatrolConfigurationSections::STATIONS, 'success', 'Saved. These are this area’s stations.');
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/stations/{station}/{action}',
        name: 'patrol_station_act',
        requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function actOnStation(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $station,
        string $action,
    ): RedirectResponse {
        $this->guard($request);

        $record = $this->stations->findOneByAreaAndUuid($area, $station);
        if (!$record instanceof Station || !\in_array($action, self::ACTIONS, true)) {
            throw new NotFoundHttpException('No such station in this area.');
        }

        try {
            $message = match ($action) {
                'rename' => \sprintf('Renamed to "%s".', $this->vocabulary->renameStation($record, $request->request->getString('label'))->getLabel()),
                'retire' => \sprintf('"%s" is retired. Every patrol filed against it keeps it.', $this->vocabulary->retireStation($record)->getLabel()),
                default => \sprintf('"%s" is back in use.', $this->vocabulary->reactivateStation($record)->getLabel()),
            };
        } catch (VocabularyConflictException $conflict) {
            return $this->back($request, $area, PatrolConfigurationSections::STATIONS, 'error', $conflict->getMessage());
        }

        return $this->back($request, $area, PatrolConfigurationSections::STATIONS, 'success', $message);
    }

    // ── reading the section's fields ──────────────────────────────────────────

    /**
     * One row's value out of a field named `field[uuid]`, as a plain string.
     *
     * The whole bag is read rather than `InputBag::getString('field[uuid]')`,
     * which does not address into an array, and the shape is checked rather than
     * asserted: a hand-posted `field=1` is a string where an array was drawn, and
     * that is a row nobody said anything about rather than a 400.
     */
    private static function string(Request $request, string $field, string $uuid): string
    {
        $values = $request->request->all()[$field] ?? null;
        $value = \is_array($values) ? ($values[$uuid] ?? null) : null;

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * The same, as a whole number — and NULL rather than a 400 for anything that
     * is not one, an empty string included.
     *
     * A null means "this row said nothing about it", which is what a row whose
     * disclosure was never opened posts and what the service leaves alone. Out of
     * range is the service's to clamp, not this method's to refuse.
     */
    private static function number(Request $request, string $field, string $uuid): ?int
    {
        $value = filter_var(self::string($request, $field, $uuid), \FILTER_VALIDATE_INT);

        return false === $value ? null : $value;
    }

    // ── the two things every action does ──────────────────────────────────────

    private function guard(Request $request): void
    {
        if (!$this->authorization->isGranted(self::MANAGE_PERMISSION)) {
            throw new AccessDeniedException('Changing what this area runs patrols on needs "'.self::MANAGE_PERMISSION.'".');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the patrols configure page.');
        }
    }

    private function back(Request $request, AreaOfInterest $area, string $section, string $type, string $message): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        // BACK TO THE SECTION THAT WAS SAVED, NAMED. The bare address belongs to
        // the page's FIRST section — the widget library, a screen of its own — so
        // a save that returned there would land somebody on a different page than
        // the one they saved.
        return new RedirectResponse($this->router->generate(ConfigureController::MODULE_ROUTE, [
            'uuid' => $area->getUuidString(),
            'slug' => PatrolModuleProvider::SLUG,
            'section' => $section,
        ]));
    }
}
