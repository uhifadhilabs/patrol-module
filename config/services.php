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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use UhifadhiLabs\Patrol\Controller\PatrolCalendarController;
use UhifadhiLabs\Patrol\Controller\PatrolController;
use UhifadhiLabs\Patrol\Controller\PatrolDetailController;
use UhifadhiLabs\Patrol\Repository\FlightRepository;
use UhifadhiLabs\Patrol\Repository\LaunchPointRepository;
use UhifadhiLabs\Patrol\Repository\ObservationPhotoRepository;
use UhifadhiLabs\Patrol\Repository\ObservationRepository;
use UhifadhiLabs\Patrol\Repository\PatrolEventRepository;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Repository\TrackBatchRepository;
use UhifadhiLabs\Patrol\Repository\TrackPointRepository;
use UhifadhiLabs\Patrol\Repository\WidgetPreferenceRepository;
use UhifadhiLabs\Patrol\Service\GeoService;
use UhifadhiLabs\Patrol\Service\GpxParser;
use UhifadhiLabs\Patrol\Service\GpxWriter;
use UhifadhiLabs\Patrol\Service\PatrolDashboardService;
use UhifadhiLabs\Patrol\Service\PatrolWidgetService;
use UhifadhiLabs\Patrol\Service\TrackIngestService;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiLabsPatrolBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions (module category, vocabulary parameters).
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure(), and
 * ids prefixed with the bundle alias — because this bundle is installed by other
 * projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * Controllers extend nothing and take their collaborators explicitly, patterned
 * on FrameworkBundle's own TemplateController (see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('patrol.geo', GeoService::class);

    $services->set('patrol.gpx_parser', GpxParser::class)
        ->args([service('patrol.geo')]);

    // The inverse of the parser: a recorded track back out as a GPX file.
    $services->set('patrol.gpx_writer', GpxWriter::class)
        ->args([service('patrol.geo')]);

    $services->set('patrol.dashboard', PatrolDashboardService::class);

    $services->set('patrol.widget_service', PatrolWidgetService::class)
        ->args([
            service(WidgetPreferenceRepository::class),
            service('doctrine.orm.entity_manager'),
        ]);

    $services->set('patrol.track_ingest', TrackIngestService::class)
        ->args([
            service('patrol.gpx_parser'),
            service('doctrine.orm.entity_manager'),
            param('patrol.gap_threshold_minutes'),
        ]);

    /*
     * Repositories keep FQCN ids — the one place the bundle-alias prefix cannot
     * be used: ServiceRepositoryCompilerPass keys its locator by SERVICE ID over
     * findTaggedServiceIds(), while ContainerRepositoryFactory looks a repository
     * up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    $services->set(PatrolRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(ObservationRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(WidgetPreferenceRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // The field-sync entities' repositories. Registered unconditionally with the
    // rest: a repository is just a query surface over a mapped entity, and those
    // entities are mapped whether or not this host installs api-platform.
    $services->set(TrackBatchRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TrackPointRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(LaunchPointRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(FlightRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(ObservationPhotoRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(PatrolEventRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * Controllers: plain classes (they extend nothing), explicit collaborators,
     * prefixed ids. Routes reference "PatrolController::dashboard" and Symfony's
     * controller resolver asks the container for that class name, so each gets the
     * alias the best practices prescribe: "For public services, aliases should be
     * created from the interface/class to the service id."
     *
     * @see https://symfony.com/doc/current/bundles/best_practices.html
     * @see vendor/symfony/framework-bundle/Resources/config/routing.php
     */
    $services->set('patrol.controller.dashboard', PatrolController::class)
        ->args([
            service('twig'),
            service(PatrolRepository::class),
            service('patrol.dashboard'),
            service('patrol.widget_service'),
            param('patrol.types'),
            param('patrol.record_screens'),
            param('patrol.widget_screens'),
            // Null where the host runs no security: nobody is signed in, so the
            // dashboard renders the design's default layout for everyone.
            service('security.token_storage')->nullOnInvalid(),
            param('patrol.discard_retention_days'),
        ])
        ->public();

    $services->alias(PatrolController::class, 'patrol.controller.dashboard')->public();

    /*
     * The calendar's month fragment (PL·11 ‹ ›). Registered beside the dashboard
     * rather than inside the bundle's SecurityBundle guard: it is a slice of the
     * dashboard the same caller already reads, so it must exist wherever the
     * dashboard does — including a host with no security, where the widget still
     * renders and its ‹ › must still work.
     */
    $services->set('patrol.controller.calendar', PatrolCalendarController::class)
        ->args([
            service('twig'),
            service(PatrolRepository::class),
            service('patrol.dashboard'),
            param('patrol.types'),
        ])
        ->public();

    $services->alias(PatrolCalendarController::class, 'patrol.controller.calendar')->public();

    $services->set('patrol.controller.detail', PatrolDetailController::class)
        ->args([
            service('twig'),
            service('router'),
            service('patrol.geo'),
            service('patrol.gpx_writer'),
            param('patrol.types'),
            param('patrol.observation_categories'),
            param('patrol.discard_retention_days'),
            // Null where the host runs no security: the hold action then exists
            // for nobody, and the route it would post to was never registered.
            service('security.authorization_checker')->nullOnInvalid(),
            service('security.csrf.token_manager')->nullOnInvalid(),
        ])
        ->public();

    $services->alias(PatrolDetailController::class, 'patrol.controller.detail')->public();
};
