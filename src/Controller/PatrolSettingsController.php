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
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Service\PatrolSettingsService;

/**
 * THE ONE POST BEHIND THE SETTINGS SECTION. Every control in that section is a
 * field of one record, and the section saves once — nothing there writes on
 * change, so a half-finished form leaves the area running on what it ran on.
 *
 * LEAN: it authorizes, checks the token, hands two numbers to the settings
 * service and redirects back. What a number MEANS, and what happens to one
 * outside the design's bounds, is the service's.
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

    /** The token id the Settings section's one form carries. */
    public const string CSRF_TOKEN_ID = 'patrol_settings';

    public function __construct(
        private UrlGeneratorInterface $router,
        private PatrolSettingsService $settings,
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
        if (!$this->authorization->isGranted(self::MANAGE_PERMISSION)) {
            throw new AccessDeniedException('Changing what this area runs patrols on needs "'.self::MANAGE_PERMISSION.'".');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the patrols settings.');
        }

        $this->settings->save(
            $area,
            $request->request->getInt('gap_threshold_minutes'),
            $request->request->getInt('discard_retention_days'),
        );

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', 'Saved. This area runs on its own numbers now.');
        }

        return new RedirectResponse($this->router->generate(ConfigureController::MODULE_ROUTE, [
            'uuid' => $area->getUuidString(),
            'slug' => PatrolModuleProvider::SLUG,
        ]));
    }
}
