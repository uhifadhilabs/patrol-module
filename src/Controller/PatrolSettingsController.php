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
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Entity\Station;
use Uhifadhi\Patrol\Exception\VocabularyConflictException;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Repository\StationRepository;
use Uhifadhi\Patrol\Service\PatrolSettingsService;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;

/**
 * THE WRITES BEHIND THE SETTINGS SECTION — the thresholds it saves once, and the
 * two word-lists SET·01 and SET·03 edit a row at a time.
 *
 * TWO SHAPES, BECAUSE THE DESIGN DRAWS TWO. The thresholds are fields of one
 * record and save together, so nothing there writes on change. A word-list row
 * is a decision on its own — renaming a station is not part of choosing a gap
 * threshold — so each of its buttons is its own POST, at the addresses the
 * design writes on the section: `…/settings/types` to add, and
 * `…/settings/types/{uuid}/{rename|retire|reactivate}` to act on one.
 *
 * LEAN: every action authorizes, checks the token, hands words to a service and
 * redirects back. What a word MEANS, whether it collides, what its key is and
 * why a retirement never deletes are all {@see PatrolVocabularyService}'s.
 *
 * THERE IS NO GET HERE. Reading the section is the SHELL's configure page; a
 * module that also served the page would be a second answer to where
 * configuration lives.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => PatrolModuleProvider::SLUG])]
final readonly class PatrolSettingsController
{
    /** Changing what the area runs on rides on the same authority the kinds do. */
    public const string MANAGE_PERMISSION = PatrolTaxonomyController::MANAGE_PERMISSION;

    /** The token id every form on the Settings section carries. */
    public const string CSRF_TOKEN_ID = 'patrol_settings';

    /** What a row's buttons may ask for. Anything else is not a button we drew. */
    private const array ACTIONS = ['rename', 'retire', 'reactivate'];

    /**
     * What a cleared threshold is told. Each names the row's own label as SET·04
     * draws it — "gps gap", "discard keeps" — because a sentence that does not
     * say which box it is about leaves a reader checking both.
     */
    private const string GAP_SENTENCE = 'The gps gap needs a whole number of minutes. Nothing was saved.';
    private const string RETENTION_SENTENCE = 'Discard keeps needs a whole number of days. Nothing was saved.';

    public function __construct(
        private UrlGeneratorInterface $router,
        private PatrolSettingsService $settings,
        private PatrolVocabularyService $vocabulary,
        private PatrolTypeRepository $types,
        private StationRepository $stations,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/settings',
        name: 'patrol_settings_save',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): RedirectResponse {
        $this->guard($request);

        $gap = self::wholeNumber($request, 'gap_threshold_minutes');
        $retention = self::wholeNumber($request, 'discard_retention_days');

        // A CLEARED FIELD IS A SENTENCE, NOT A PROTOCOL ERROR. Both rows are
        // <input type="number">, and somebody who selects one and presses delete
        // posts an empty string — the commonest way there is to change a number
        // in one. Each is named on its own so the reader is told WHICH box to go
        // back to, and nothing is written: saving one threshold because the other
        // was blank would leave an area running on a number nobody chose.
        if (null === $gap) {
            return $this->back($request, $area, 'error', self::GAP_SENTENCE);
        }
        if (null === $retention) {
            return $this->back($request, $area, 'error', self::RETENTION_SENTENCE);
        }

        $this->settings->save($area, $gap, $retention);

        return $this->back($request, $area, 'success', 'Saved. This area runs on its own numbers now.');
    }

    /**
     * A WHOLE NUMBER, OR NULL — AND NEVER A 400.
     *
     * `InputBag::getInt()` filters with FILTER_VALIDATE_INT and THROWS a
     * BadRequestException on anything that is not one, an empty string included.
     * So clearing either threshold answered `400 Input value
     * "gap_threshold_minutes" cannot be converted to "int"` — a stack trace in
     * place of the one sentence that would have said what to type.
     *
     * OUT OF RANGE IS STILL CLAMPED rather than refused, and that is a decision
     * rather than an omission: a number outside the design's bounds is a
     * hand-posted value the browser's own `min`/`max` would never send, and
     * {@see PatrolSettingsService} has always answered one by clamping. Only a
     * value that is not a number AT ALL is something a person can have typed into
     * the field, and only that gets a sentence.
     *
     * @see vendor/symfony/http-foundation/InputBag.php — getInt()
     */
    private static function wholeNumber(Request $request, string $field): ?int
    {
        $value = filter_var($request->request->getString($field), \FILTER_VALIDATE_INT);

        return false === $value ? null : $value;
    }

    // ── SET·01 · patrol types ─────────────────────────────────────────────────

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/settings/types',
        name: 'patrol_settings_type_add',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function addType(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): RedirectResponse {
        $this->guard($request);

        try {
            $type = $this->vocabulary->addType($area, $request->request->getString('label'));
        } catch (VocabularyConflictException $conflict) {
            return $this->back($request, $area, 'error', $conflict->getMessage());
        }

        return $this->back($request, $area, 'success', \sprintf('"%s" is a patrol type in this area now.', $type->getLabel()));
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/settings/types/{type}/{action}',
        name: 'patrol_settings_type_act',
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
            return $this->back($request, $area, 'error', $conflict->getMessage());
        }

        return $this->back($request, $area, 'success', $message);
    }

    // ── SET·03 · stations ─────────────────────────────────────────────────────

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/settings/stations',
        name: 'patrol_settings_station_add',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function addStation(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): RedirectResponse {
        $this->guard($request);

        try {
            $station = $this->vocabulary->addStation($area, $request->request->getString('label'));
        } catch (VocabularyConflictException $conflict) {
            return $this->back($request, $area, 'error', $conflict->getMessage());
        }

        return $this->back($request, $area, 'success', \sprintf('"%s" is a station in this area now.', $station->getLabel()));
    }

    #[Route(
        '/areas/{uuid}/modules/patrols/configure/settings/stations/{station}/{action}',
        name: 'patrol_settings_station_act',
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
            return $this->back($request, $area, 'error', $conflict->getMessage());
        }

        return $this->back($request, $area, 'success', $message);
    }

    // ── the two things every action does ──────────────────────────────────────

    private function guard(Request $request): void
    {
        if (!$this->authorization->isGranted(self::MANAGE_PERMISSION)) {
            throw new AccessDeniedException('Changing what this area runs patrols on needs "'.self::MANAGE_PERMISSION.'".');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the patrols settings.');
        }
    }

    private function back(Request $request, AreaOfInterest $area, string $type, string $message): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        /*
         * BACK TO THE SETTINGS SECTION, NAMED. The bare configure address
         * belongs to the surface's FIRST section, and this module's first is the
         * widget library — a screen of its own, so the shell redirects the bare
         * address to it. A save that returned there would land somebody on a
         * different page than the one they saved.
         *
         * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/Frame/Service/ModuleFrameService.php
         */
        return new RedirectResponse($this->router->generate(ConfigureController::MODULE_ROUTE, [
            'uuid' => $area->getUuidString(),
            'slug' => PatrolModuleProvider::SLUG,
            'section' => ConfigurationSection::SETTINGS,
        ]));
    }
}
