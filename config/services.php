<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use UhifadhiLabs\PatrolBundle\Controller\PatrolController;
use UhifadhiLabs\PatrolBundle\Repository\ObservationRepository;
use UhifadhiLabs\PatrolBundle\Repository\PatrolRepository;
use UhifadhiLabs\PatrolBundle\Service\GeoService;
use UhifadhiLabs\PatrolBundle\Service\GpxParser;
use UhifadhiLabs\PatrolBundle\Service\PatrolDashboardService;
use UhifadhiLabs\PatrolBundle\Service\TrackIngestService;

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

    $services->set('patrol.dashboard', PatrolDashboardService::class);

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
            param('patrol.types'),
        ])
        ->public();

    $services->alias(PatrolController::class, 'patrol.controller.dashboard')->public();
};
