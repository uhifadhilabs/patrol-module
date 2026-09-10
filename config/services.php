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

use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Patrol\Controller\PatrolCalendarController;
use Uhifadhi\Patrol\Controller\PatrolController;
use Uhifadhi\Patrol\Controller\PatrolDetailController;
use Uhifadhi\Patrol\Repository\FlightRepository;
use Uhifadhi\Patrol\Repository\LaunchPointRepository;
use Uhifadhi\Patrol\Repository\ObservationAmendmentRepository;
use Uhifadhi\Patrol\Repository\ObservationPhotoRepository;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolEventRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\TaxonomyKindRepository;
use Uhifadhi\Patrol\Repository\TaxonomySubcategoryRepository;
use Uhifadhi\Patrol\Repository\TrackBatchRepository;
use Uhifadhi\Patrol\Repository\TrackPointRepository;
use Uhifadhi\Patrol\Service\GeoService;
use Uhifadhi\Patrol\Service\GpxParser;
use Uhifadhi\Patrol\Service\GpxWriter;
use Uhifadhi\Patrol\Service\ObservationAmendmentService;
use Uhifadhi\Patrol\Service\PatrolDashboardService;
use Uhifadhi\Patrol\Service\PatrolHoldService;
use Uhifadhi\Patrol\Service\PatrolMapService;
use Uhifadhi\Patrol\Service\PatrolRecordingService;
use Uhifadhi\Patrol\Service\PatrolWidgetUrls;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Service\TrackIngestService;
use Uhifadhi\Patrol\Twig\PatrolTrailExtension;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiPatrolBundle::loadExtension(), which keeps only the config-DRIVEN
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

    /*
     * THE MODULE'S PLATES. What patrol states about its two maps, handed to the
     * atlas to draw. The module writes no map JavaScript: this builds the map,
     * and render_map() puts it on the page.
     */
    $services->set('patrol.map', PatrolMapService::class)
        ->args([service(MapBuilderInterface::class)]);
    $services->alias(PatrolMapService::class, 'patrol.map');

    // The widget library's URL map, shared by the dashboard and the library
    // itself, with THIS AREA named in every URL.
    $services->set('patrol.widget_urls', PatrolWidgetUrls::class)
        ->args([service('router')]);

    /*
     * APPENDING ONE CORRECTION to an observation. Unconditional for the reason
     * 'patrol.taxonomy_admin' is: it is domain logic with no security of its
     * own, and only the door that fronts it lives inside the SecurityBundle
     * guard — an amendment is signed, and a host with no security has nobody to
     * sign one.
     */
    $services->set('patrol.observation_amendments', ObservationAmendmentService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            // The same evidence path the field uploads use, so a photograph
            // attached on the web is stored, typed and previewed exactly as one
            // off a handset.
            service('storage.evidence_storage'),
        ]);

    /*
     * HOLDING A DISCARDED PATROL back from the purge, and letting it go again.
     * Unconditional beside the other two writes, and for the same reason: the
     * rule about which patrols have a clock to stop is domain logic, and only
     * the door that fronts it is guarded.
     */
    $services->set('patrol.hold', PatrolHoldService::class)
        ->args([service('doctrine.orm.entity_manager')]);

    /*
     * The hand-written patrol's write path — the log screen's half of what
     * 'patrol.track_ingest' is for the import screen. Registered beside it and
     * unconditionally, for the reason 'patrol.taxonomy_admin' is: it is domain
     * logic with no security of its own, and only the DOOR that fronts it lives
     * inside the SecurityBundle guard.
     */
    $services->set('patrol.recording', PatrolRecordingService::class)
        ->args([service('doctrine.orm.entity_manager')]);

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
    $services->set(ObservationAmendmentRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // The area-scoped observation-taxonomy's two levels. Registered with the rest
    // for the same reason: a repository is a query surface over a mapped entity,
    // and these entities are mapped whether or not this host runs security.
    $services->set(TaxonomyKindRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TaxonomySubcategoryRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * THE AREA-SCOPED OBSERVATION-TAXONOMY ADMIN's logic. Registered
     * unconditionally — it is pure domain logic (create/rename/retire kinds and
     * sub-categories, keep wire-codes unique per area) with no security of its
     * own; the CONTROLLER that fronts it is registered only under the security
     * guard (see UhifadhiPatrolBundle), because the write rides on
     * "patrols.manage" and there is nobody to grant it without a firewall.
     */
    $services->set('patrol.taxonomy_admin', TaxonomyAdminService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TaxonomyKindRepository::class),
            service(TaxonomySubcategoryRepository::class),
        ]);

    /*
     * THE CRUMB'S ONE HELPER — `patrol_url()`, which answers null for a screen
     * the installation did not mount instead of throwing the page away. See
     * PatrolTrailExtension for why a module's breadcrumb cannot use path().
     */
    $services->set('patrol.twig.trail', PatrolTrailExtension::class)
        ->args([service('router')])
        ->tag('twig.extension');

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
            service('patrol.map'),
            // The day's live reading (out now, zone gaps, the observation queue)
            // for the direction widgets — measured in the ONE place the overview
            // measures it, so the dashboard and /areas/{uuid} never disagree.
            service('patrol.overview'),
            // ShellBundle, BY ITS PUBLISHED SERVICE ID: the module
            // ships a catalogue, never a copy of the algebra that resolves it.
            // The id is that bundle's public surface (its service reference),
            // which is what a reusable bundle names another one by.
            service('shell.widget.service'),
            param('patrol.types'),
            param('patrol.record_screens'),
            param('patrol.widget_screens'),
            param('patrol.manage_screens'),
            // Null where the installation runs no security: nobody is signed
            // in, so the dashboard renders the shipped composition for everyone.
            service('security.token_storage')->nullOnInvalid(),
            param('patrol.discard_retention_days'),
            // Whether THIS VIEWER may record — a different question from whether
            // the recording screens exist, and the dashboard has to ask both
            // before it offers a door. Null under the same condition as the
            // token storage, and the answer is then "no door", which is right:
            // an installation with no authorization checker cannot enforce
            // patrols.record either, so the recording routes do not exist.
            service('security.authorization_checker')->nullOnInvalid(),
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
            service('patrol.map'),
            // The amendment trail the observation screen reads (PL·06). Not
            // behind the security guard the WRITE is behind: a correction is
            // part of the record and must be readable wherever the record is,
            // including on a host that runs no security and can therefore never
            // append one.
            service(ObservationAmendmentRepository::class),
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
