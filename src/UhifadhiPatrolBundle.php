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

namespace Uhifadhi\Patrol;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Area\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Area\Overview\AttentionProviderInterface;
use Uhifadhi\Area\Overview\MapLayerProviderInterface;
use Uhifadhi\Area\Overview\NowTileProviderInterface;
use Uhifadhi\Area\Overview\OverviewContributorInterface;
use Uhifadhi\Area\Overview\OverviewCopyProviderInterface;
use Uhifadhi\Area\Overview\PulseProviderInterface;
use Uhifadhi\Patrol\Api\PatrolApiContext;
use Uhifadhi\Patrol\Api\State\AppendEventsProcessor;
use Uhifadhi\Patrol\Api\State\AppendFlightsProcessor;
use Uhifadhi\Patrol\Api\State\AppendObservationsProcessor;
use Uhifadhi\Patrol\Api\State\AppendTrackProcessor;
use Uhifadhi\Patrol\Api\State\CompletePatrolProcessor;
use Uhifadhi\Patrol\Api\State\CreatePatrolProcessor;
use Uhifadhi\Patrol\Api\State\UploadPhotoProcessor;
use Uhifadhi\Patrol\Command\BackfillPhotoThumbsCommand;
use Uhifadhi\Patrol\Command\PurgeDiscardedCommand;
use Uhifadhi\Patrol\Command\SeedDemoCommand;
use Uhifadhi\Patrol\Controller\ObservationAmendmentController;
use Uhifadhi\Patrol\Controller\PatrolHoldController;
use Uhifadhi\Patrol\Controller\PatrolRecordController;
use Uhifadhi\Patrol\Controller\PatrolWidgetsController;
use Uhifadhi\Patrol\DependencyInjection\PatrolConfiguration;
use Uhifadhi\Patrol\Module\PatrolDepartmentKpiProvider;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Overview\PatrolAttention;
use Uhifadhi\Patrol\Overview\PatrolMapLayers;
use Uhifadhi\Patrol\Overview\PatrolNowTiles;
use Uhifadhi\Patrol\Overview\PatrolOverviewContributor;
use Uhifadhi\Patrol\Overview\PatrolOverviewCopy;
use Uhifadhi\Patrol\Overview\PatrolPulse;
use Uhifadhi\Patrol\Repository\FlightRepository;
use Uhifadhi\Patrol\Repository\LaunchPointRepository;
use Uhifadhi\Patrol\Repository\ObservationPhotoRepository;
use Uhifadhi\Patrol\Repository\ObservationRepository;
use Uhifadhi\Patrol\Repository\PatrolEventRepository;
use Uhifadhi\Patrol\Repository\PatrolRepository;
use Uhifadhi\Patrol\Repository\TrackBatchRepository;
use Uhifadhi\Patrol\Repository\TrackPointRepository;
use Uhifadhi\Patrol\Security\PatrolEvidenceVoter;
use Uhifadhi\Patrol\Service\Api\FlightSyncService;
use Uhifadhi\Patrol\Service\Api\ObservationSyncService;
use Uhifadhi\Patrol\Service\Api\PatrolCompletionService;
use Uhifadhi\Patrol\Service\Api\PatrolEventService;
use Uhifadhi\Patrol\Service\Api\PatrolUpsertService;
use Uhifadhi\Patrol\Service\Api\PhotoSyncService;
use Uhifadhi\Patrol\Service\Api\RangerResolver;
use Uhifadhi\Patrol\Service\Api\TrackBatchService;
use Uhifadhi\Patrol\Service\PatrolOverviewService;
use Uhifadhi\Patrol\Storage\PatrolFileSource;
use Uhifadhi\Patrol\Widget\PatrolWidgets;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Widget\Registry\WidgetSurfaceInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Patrols — field patrol effort as first-class records: GPX track ingest,
 * en-route observations with photos, coverage mapping and a per-user widget
 * dashboard.
 *
 * Zero-config: registering the bundle maps its own entities (no host doctrine
 * block needed) and registers the domain services. Spatial columns ride on
 * fundistadi/postgis-bundle.
 */
final class UhifadhiPatrolBundle extends AbstractBundle
{
    /**
     * WHERE THIS BUNDLE'S VOCABULARY IS SERVED FROM — what AssetMapper serves
     * public/patrol.css under, stated once because it has two readers that must
     * never disagree: `templates/base.html.twig`, which links it on every patrol
     * page of this module's own, and
     * {@see PatrolOverviewContributor::stylesheet()},
     * which hands it to a HOST that is rendering this module's plates on the
     * area overview. The bundle's name is the bundle's own knowledge.
     */
    public const string STYLESHEET = 'bundles/uhifadhipatrol/patrol.css';

    /** Config lives under "patrol:", not the class-derived "uhifadhi_labs_patrol:". */
    protected string $extensionAlias = 'patrol';

    public function configure(DefinitionConfigurator $definition): void
    {
        PatrolConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/uhifadhipatrol` and content-versioned — no
        // config here, no assets:install.

        // Ship the bundle's Stimulus controllers (assets/) under an AssetMapper
        // namespace, exactly as symfony/ux-turbo does (TurboExtension::prepend).
        // The recipe enables them in the host's assets/controllers.json.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            $container->extension('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/../assets' => '@uhifadhi/patrol-module',
                    ],
                ],
            ]);
        }

        // Zero-config persistence: the bundle maps its own entities, so hosts
        // never write a doctrine mappings block for patrol_* tables.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiPatrol' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Patrol\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Explicit wiring, no autowire/autoconfigure — see config/services.php for
        // the Symfony reusable-bundle rule and its citation.
        $services = $container->services();

        // The one module this bundle contributes, collected by the host's
        // catalogue seed + module grid. The host tags every ModuleProviderInterface
        // via registerForAutoconfiguration, but that only fires for autoconfigured
        // services — and a reusable bundle doesn't autoconfigure — so the tag is
        // applied explicitly here.
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'operations';
        $services->set('patrol.module_provider', PatrolModuleProvider::class)
            ->args([$category])
            ->tag('uhifadhi.module');

        // The deployment's vocabulary (patrol types, observation categories) and
        // ingest thresholds, exposed as parameters for the services that need them.
        $types = $config['types'] ?? [];
        $builder->setParameter('patrol.types', \is_array($types) ? $types : []);
        $categories = $config['observation_categories'] ?? [];
        $builder->setParameter('patrol.observation_categories', \is_array($categories) ? $categories : []);
        $gap = $config['gap_threshold_minutes'] ?? 5.0;
        $builder->setParameter('patrol.gap_threshold_minutes', \is_float($gap) || \is_int($gap) ? (float) $gap : 5.0);
        // How long a discarded patrol survives. Read by the purge command and by
        // the detail screen, which states the same window to the person looking
        // at the patrol — one number, so the page cannot promise a date the
        // command will not honour.
        $retention = $config['discard_retention_days'] ?? PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS;
        $builder->setParameter(
            'patrol.discard_retention_days',
            \is_int($retention) ? $retention : PatrolConfiguration::DEFAULT_DISCARD_RETENTION_DAYS,
        );

        /*
         * The two RECORDING screens (import GPX, log patrol) are registered ONLY
         * inside this guard. They are the only screens that create patrols, so
         * they must never exist unprotected: without symfony/security there is no
         * authorization checker to enforce "patrols.record", and a host in that
         * state gets no recording controller at all (the routes fail loudly)
         * rather than an open write endpoint. See PatrolRecordController for why
         * the check is in code and not an #[IsGranted] attribute.
         *
         * The guard asks whether SecurityBundle is actually in the kernel, read
         * from the kernel.bundles parameter. Two other checks look right and are
         * not: hasExtension('security') cannot be used here, because while an
         * extension is loading the builder is a restricted
         * MergeExtensionConfigurationContainerBuilder that does not expose other
         * extensions; and interface_exists() only proves a class is autoloadable —
         * security-core is one of this bundle's DEV dependencies, so it autoloads
         * in our own test runs even when SecurityBundle is absent, and services
         * would then reference security.* ids that do not exist. FrameworkExtension
         * reads kernel.bundles for exactly this reason.
         */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        $hasSecurity = \is_array($bundles) && isset($bundles['SecurityBundle']);

        /*
         * THE EVIDENCE STORAGE — a hard requirement, said here where a developer
         * will read it rather than as a missing "storage.evidence_storage" three
         * screens into a stack trace.
         *
         * Not made optional on purpose. Photographs are not a decorative extra
         * on an observation: the upload endpoint takes them, the detail screen
         * shows them, and both would have to grow a "…unless the host skipped
         * storage" branch that no deployment would ever run. One composer
         * dependency and one line in bundles.php is the cheaper contract.
         */
        if (!\is_array($bundles) || !isset($bundles['UhifadhiStorageBundle'])) {
            throw new \LogicException('UhifadhiPatrolBundle stores observation photos in uhifadhi/storage-module. Register FlysystemBundle and Uhifadhi\Storage\UhifadhiStorageBundle in config/bundles.php.');
        }

        /*
         * PATROL'S HALF OF THE PERMISSION SEAM.
         *
         * Storage denies any key no module claims, so without this service every
         * patrol photograph is a 403 — the right failure direction, and the
         * reason the voter ships in the same change as the rewire. Registered
         * unconditionally, beside the entities rather than inside the field-API
         * guard: a host that never installed api-platform can still HOLD photos
         * (imported, seeded, migrated) and must still be able to show them.
         *
         * Tagged explicitly. A reusable bundle is not autoconfigured, and a
         * module that forgot this tag would silently lose access to its own
         * evidence — a confusing way to find out.
         */
        $services->set('patrol.evidence_voter', PatrolEvidenceVoter::class)
            ->args([service(ObservationPhotoRepository::class)])
            ->tag('uhifadhi.evidence_access_voter');

        /*
         * PATROL'S FILES, ON THE PLATFORM'S FILES HUB.
         *
         * The other half of the same seam: the voter says who may READ a
         * photograph, this says which photographs exist and what may be done to
         * them. Registered beside it and for the same reason — a host that holds
         * photographs must be able to list them whether or not it ever installed
         * api-platform.
         *
         * Tagged by hand with the interface's own constant. A reusable bundle is
         * not autoconfigured, and a module that forgot this tag would simply not
         * appear on /files — the hub grows by MODULES, so a missing source looks
         * exactly like a module that was never installed.
         */
        $services->set('patrol.file_source', PatrolFileSource::class)
            ->args([service(ObservationPhotoRepository::class), service('router')])
            ->tag(FileSourceInterface::TAG);

        // The dashboard offers "Import GPX" / "Log patrol" only where those
        // routes exist, so a host without security shows no link into nowhere.
        $builder->setParameter('patrol.record_screens', $hasSecurity);
        // The widget library edits ONE PERSON's layout, so it needs a signed-in
        // user for the same reason and lives under the same guard; a host without
        // security simply renders the design's default layout for everyone.
        $builder->setParameter('patrol.widget_screens', $hasSecurity);

        /*
         * THE PATROLS DASHBOARD IS A DECLARED WIDGET SURFACE, tagged by hand
         * because a reusable bundle is not autoconfigured. The tag is what makes
         * the surface FINDABLE: `widget:prune` walks the registry, and layouts
         * keyed to a surface no service claims are exactly what it deletes.
         *
         * OUTSIDE the security guard, unlike the library screen. The catalogue
         * is a statement of what this module ships and is true of every
         * installation that registered the bundle; an installation with no
         * firewall still RENDERS the surface — as the shipped composition, for
         * everyone — and the rows already stored against it from before a
         * firewall was removed must not read as orphans while it is gone.
         */
        $services->set('patrol.widget_surface', PatrolWidgets::class)
            ->tag(WidgetSurfaceInterface::TAG);

        if ($hasSecurity) {
            $services->set('patrol.controller.widgets', PatrolWidgetsController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service(PatrolRepository::class),
                    service('patrol.dashboard'),
                    // uhifadhi/widget-module, BY ITS PUBLISHED SERVICE IDS: the
                    // module ships a catalogue (PatrolWidgets), never a copy of
                    // the algebra that resolves it — and that bundle's endpoint
                    // service answers every widget write, so this module
                    // validates no token and chooses no status code.
                    service('widget.service'),
                    service('patrol.widget_urls'),
                    service('widget.endpoint'),
                    param('patrol.types'),
                    param('patrol.discard_retention_days'),
                ])
                ->public();
            $services->alias(PatrolWidgetsController::class, 'patrol.controller.widgets')->public();

            $services->set('patrol.controller.record', PatrolRecordController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('doctrine.orm.entity_manager'),
                    service(PatrolRepository::class),
                    service('patrol.dashboard'),
                    service('patrol.track_ingest'),
                    service('security.authorization_checker'),
                    param('patrol.types'),
                    param('patrol.gap_threshold_minutes'),
                ])
                ->public();
            $services->alias(PatrolRecordController::class, 'patrol.controller.record')->public();

            // Holding a discarded patrol for review — the detail screen's one
            // write. Under this guard for the same reason the recording screens
            // are: it changes a field record, so it must never exist where there
            // is no authorization checker to enforce "patrols.record".
            $services->set('patrol.controller.hold', PatrolHoldController::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service('router'),
                    service('security.authorization_checker'),
                    service('security.token_storage'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(PatrolHoldController::class, 'patrol.controller.hold')->public();

            // Appending a correction to an observation (PL·06–PL·09). Under the
            // same guard, and for a sharper version of the same reason: an
            // amendment is SIGNED, and a host with no security has nobody to
            // sign one. The route not existing there is the honest outcome —
            // better than a trail of unattributed corrections.
            $services->set('patrol.controller.observation_amend', ObservationAmendmentController::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service('router'),
                    service('security.authorization_checker'),
                    service('security.token_storage'),
                    service('security.csrf.token_manager'),
                    // The same evidence path the field uploads use, so a
                    // photograph attached on the web is stored, typed and
                    // previewed exactly as one off a handset.
                    service('storage.evidence_storage'),
                ])
                ->public();
            $services->alias(ObservationAmendmentController::class, 'patrol.controller.observation_amend')->public();
        }

        /*
         * The FIELD-SYNC API (API-CONTRACT.md) — the mobile app's endpoints.
         *
         * Registered only where the host actually runs api-platform AND
         * security, and for the same reason the recording screens are: these
         * routes CREATE patrols, so they must never exist unprotected. Without
         * security there is no authorization checker to enforce
         * "patrols.record"; without api-platform there is no /api to attach to.
         * A host missing either gets no sync endpoints at all rather than an
         * open write surface.
         *
         * The RESOURCES need no registration: api-platform discovers
         * src/ApiResource/ from kernel.bundles_metadata by itself (see
         * ApiResource/PatrolSync.php). Only the services behind them are wired
         * here — explicitly, with prefixed ids, no autowiring, as everything
         * else in this bundle is.
         *
         * Processors are the one exception to the id-prefix rule, and by
         * necessity: api-platform resolves `processor: SomeProcessor::class` as
         * a SERVICE ID, so the id has to be the class name. Each still gets a
         * prefixed alias for consistency with the controllers above.
         */
        // $bundles is known to be a non-empty array by here: the storage guard
        // above cannot have been passed otherwise.
        $hasApiPlatform = isset($bundles['ApiPlatformBundle']);
        $builder->setParameter('patrol.field_api', $hasSecurity && $hasApiPlatform);

        if ($hasSecurity && $hasApiPlatform) {
            $services->set('patrol.api.ranger_resolver', RangerResolver::class)
                ->args([service('doctrine.orm.entity_manager')]);

            $services->set('patrol.api.context', PatrolApiContext::class)
                ->args([
                    service('request_stack'),
                    service('security.token_storage'),
                    service('security.authorization_checker'),
                    service(PatrolRepository::class),
                    service(ObservationRepository::class),
                ]);

            $services->set('patrol.api.patrol_upsert', PatrolUpsertService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(PatrolRepository::class),
                    service('patrol.api.ranger_resolver'),
                ]);

            $services->set('patrol.api.track_batch', TrackBatchService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(TrackBatchRepository::class),
                    service('patrol.geo'),
                    param('patrol.gap_threshold_minutes'),
                ]);

            $services->set('patrol.api.observation_sync', ObservationSyncService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(ObservationRepository::class),
                    service(LaunchPointRepository::class),
                    service(FlightRepository::class),
                    param('patrol.observation_categories'),
                ]);

            $services->set('patrol.api.flight_sync', FlightSyncService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(LaunchPointRepository::class),
                    service(FlightRepository::class),
                ]);

            // The bytes go to the platform's evidence storage, by service id —
            // the storage bundle is a reusable bundle and its ids are its public
            // surface (its docs/service-reference.md).
            $services->set('patrol.api.photo_sync', PhotoSyncService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(ObservationPhotoRepository::class),
                    service('storage.evidence_storage'),
                ]);

            $services->set('patrol.api.completion', PatrolCompletionService::class)
                ->args([service('doctrine.orm.entity_manager')]);

            $services->set('patrol.api.events', PatrolEventService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(PatrolEventRepository::class),
                ]);

            foreach ([
                CreatePatrolProcessor::class => 'patrol.api.patrol_upsert',
                AppendEventsProcessor::class => 'patrol.api.events',
                AppendTrackProcessor::class => 'patrol.api.track_batch',
                AppendObservationsProcessor::class => 'patrol.api.observation_sync',
                AppendFlightsProcessor::class => 'patrol.api.flight_sync',
                UploadPhotoProcessor::class => 'patrol.api.photo_sync',
                CompletePatrolProcessor::class => 'patrol.api.completion',
            ] as $processor => $collaborator) {
                $services->set($processor)
                    ->args([service('patrol.api.context'), service($collaborator)])
                    ->tag('api_platform.state_processor');
            }
        }

        /*
         * RETENTION. Registered unconditionally, unlike the two dev commands
         * below: deleting discarded patrols past their window is an operation a
         * PRODUCTION console must have, because production is the only place
         * there is anything to purge. It writes nothing unless run, and
         * `--dry-run` shows the sweep before it is trusted.
         */
        $services->set('patrol.command.purge_discarded', PurgeDiscardedCommand::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(PatrolRepository::class),
                service('storage.evidence_storage'),
                param('patrol.discard_retention_days'),
            ])
            ->tag('console.command');

        // Dev tooling: the demo seeder exists only where patrol.dev_tools is on
        // (the recipe enables it via when@dev/when@test), so production never
        // gets a command that writes invented patrols.
        if (true === ($config['dev_tools'] ?? false)) {
            $services->set('patrol.command.seed_demo', SeedDemoCommand::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(PatrolRepository::class),
                    service('patrol.geo'),
                    param('patrol.types'),
                    param('patrol.observation_categories'),
                ])
                ->tag('console.command');

            /*
             * The one-off preview backfill for photographs stored before this
             * module adopted storage-module. Dev tooling by the same reasoning
             * as the seeder: it is a migration aid a deployment runs once, not
             * an operation a production console needs standing by.
             *
             * It takes the FLYSYSTEM STORAGE and the thumbnail engine directly
             * rather than EvidenceStorage, because what it does — write one
             * derived object beside a key that already exists — is the one thing
             * the evidence API deliberately does not expose: store() validates
             * and names a NEW upload, and this is neither.
             */
            $services->set('patrol.command.backfill_photo_thumbs', BackfillPhotoThumbsCommand::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(ObservationPhotoRepository::class),
                    service('storage.evidence'),
                    service('storage.thumbnail_generator'),
                ])
                ->tag('console.command');
        }

        // The department KPI seam. APPENDED last on purpose: it is the newest thing this bundle
        // plugs into, and it depends on nothing declared above it.
        //
        // Tagged EXPLICITLY, exactly like 'uhifadhi.module' above and for the same reason: a
        // reusable bundle is not autoconfigured (symfony.com/doc/current/bundles/best_practices
        // .html), so the host's autoconfiguration never fires for it.
        //
        // The slug and name are the scalars PatrolModuleProvider::slug()/name() return: they must
        // MATCH, because the host only asks this provider for figures when a department attaches
        // the module of that slug, and captions the plates with that name. They are literals here
        // rather than constants because the provider exposes them as methods and a scalar is what
        // a service argument can carry; PatrolDepartmentKpiProviderTest pins the two together.
        $services->set('patrol.department_kpi_provider', PatrolDepartmentKpiProvider::class)
            ->args([
                service(PatrolRepository::class),
                service('doctrine.orm.entity_manager'),
                'patrols',
                'Patrols',
            ])
            ->tag(DepartmentKpiProviderInterface::TAG);

        /*
         * THE AREA OVERVIEW SEAM — the module's contribution to /areas/{uuid}.
         *
         * FIVE TAGS, FIVE SEPARATE THINGS. One puts this module's widgets and its
         * own templates on the page; the other four put PARTS into widgets the HOST
         * draws — a tile in the right-now strip, an item in needs attention, a layer
         * with its legend entry on the operational plate, a move in the pulse. The
         * host owns those widgets and knows what none of these parts count, which is
         * what stops "Right now" and "Needs attention" from becoming a hard-coded
         * list of every module the product ever shipped.
         *
         * Tagged EXPLICITLY, exactly like 'uhifadhi.module' and the KPI provider
         * above and for the same reason: a reusable bundle is not autoconfigured
         * (symfony.com/doc/current/bundles/best_practices.html), so an
         * installation's registerForAutoconfiguration never fires for these classes.
         *
         * THE TAG NAMES ARE THE INTERFACES' OWN CONSTANTS, which they could not be
         * before: these seams used to be an application's classes, off this bundle's
         * classpath at build time, so the names were literals pinned to a stub by a
         * test. They belong to uhifadhi/area-module now and it is a requirement of
         * this package, so the constant is readable here — and a rename is a compile
         * error rather than a module that silently stops contributing.
         *
         * ONE READING, FIVE CONSUMERS. All five share 'patrol.overview', so the
         * strip's "3 out", the live card's three rows and the plate's three live
         * tracks are the same three patrols rather than three answers measured a
         * query apart.
         */
        $services->set('patrol.overview', PatrolOverviewService::class)
            ->args([
                service(PatrolRepository::class),
                service(TrackPointRepository::class),
                service(ObservationRepository::class),
                service('router'),
                param('patrol.types'),
                param('patrol.observation_categories'),
            ]);

        $services->set('patrol.overview.contributor', PatrolOverviewContributor::class)
            ->args([service('patrol.overview'), param('patrol.types')])
            ->tag(OverviewContributorInterface::TAG);

        $services->set('patrol.overview.now_tiles', PatrolNowTiles::class)
            ->args([service('patrol.overview'), param('patrol.types')])
            ->tag(NowTileProviderInterface::TAG);

        $services->set('patrol.overview.attention', PatrolAttention::class)
            ->args([service('patrol.overview'), param('patrol.types')])
            ->tag(AttentionProviderInterface::TAG);

        $services->set('patrol.overview.map_layers', PatrolMapLayers::class)
            ->args([service('patrol.overview'), service(PatrolRepository::class), param('patrol.types')])
            ->tag(MapLayerProviderInterface::TAG);

        // THE MODULE'S WORDS INSIDE THE HOST'S SENTENCES. Not a widget and not a
        // part of one: the phrases the host drops into its own copy about the
        // operational plate, so "today's tracks" is said by the module that draws
        // them rather than written into the host.
        $services->set('patrol.overview.copy', PatrolOverviewCopy::class)
            ->tag(OverviewCopyProviderInterface::TAG);

        $services->set('patrol.overview.pulse', PatrolPulse::class)
            ->args([
                service(PatrolRepository::class),
                service(ObservationRepository::class),
                service('patrol.overview'),
                param('patrol.types'),
                param('patrol.observation_categories'),
            ])
            ->tag(PulseProviderInterface::TAG);
    }
}
