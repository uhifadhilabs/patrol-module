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

namespace UhifadhiLabs\Patrol;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use UhifadhiLabs\Patrol\Api\PatrolApiContext;
use UhifadhiLabs\Patrol\Api\State\AppendFlightsProcessor;
use UhifadhiLabs\Patrol\Api\State\AppendObservationsProcessor;
use UhifadhiLabs\Patrol\Api\State\AppendTrackProcessor;
use UhifadhiLabs\Patrol\Api\State\CompletePatrolProcessor;
use UhifadhiLabs\Patrol\Api\State\CreatePatrolProcessor;
use UhifadhiLabs\Patrol\Api\State\UploadPhotoProcessor;
use UhifadhiLabs\Patrol\Command\SeedDemoCommand;
use UhifadhiLabs\Patrol\Controller\PatrolRecordController;
use UhifadhiLabs\Patrol\Controller\PatrolWidgetsController;
use UhifadhiLabs\Patrol\DependencyInjection\PatrolConfiguration;
use UhifadhiLabs\Patrol\Module\PatrolDepartmentKpiProvider;
use UhifadhiLabs\Patrol\Module\PatrolModuleProvider;
use UhifadhiLabs\Patrol\Repository\FlightRepository;
use UhifadhiLabs\Patrol\Repository\LaunchPointRepository;
use UhifadhiLabs\Patrol\Repository\ObservationPhotoRepository;
use UhifadhiLabs\Patrol\Repository\ObservationRepository;
use UhifadhiLabs\Patrol\Repository\PatrolRepository;
use UhifadhiLabs\Patrol\Repository\TrackBatchRepository;
use UhifadhiLabs\Patrol\Service\Api\FlightSyncService;
use UhifadhiLabs\Patrol\Service\Api\ObservationSyncService;
use UhifadhiLabs\Patrol\Service\Api\PatrolCompletionService;
use UhifadhiLabs\Patrol\Service\Api\PatrolUpsertService;
use UhifadhiLabs\Patrol\Service\Api\PhotoSyncService;
use UhifadhiLabs\Patrol\Service\Api\RangerResolver;
use UhifadhiLabs\Patrol\Service\Api\TrackBatchService;

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
final class UhifadhiLabsPatrolBundle extends AbstractBundle
{
    /** Config lives under "patrol:", not the class-derived "uhifadhi_labs_patrol:". */
    protected string $extensionAlias = 'patrol';

    public function configure(DefinitionConfigurator $definition): void
    {
        PatrolConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/uhifadhilabspatrol` and content-versioned — no
        // config here, no assets:install.

        // Ship the bundle's Stimulus controllers (assets/) under an AssetMapper
        // namespace, exactly as symfony/ux-turbo does (TurboExtension::prepend).
        // The recipe enables them in the host's assets/controllers.json.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            $container->extension('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/../assets' => '@uhifadhilabs/patrol-module',
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
                        'UhifadhiLabsPatrol' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'UhifadhiLabs\\Patrol\\Entity',
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
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'pressure';
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
        // The dashboard offers "Import GPX" / "Log patrol" only where those
        // routes exist, so a host without security shows no link into nowhere.
        $builder->setParameter('patrol.record_screens', $hasSecurity);
        // The widget library edits ONE PERSON's layout, so it needs a signed-in
        // user for the same reason and lives under the same guard; a host without
        // security simply renders the design's default layout for everyone.
        $builder->setParameter('patrol.widget_screens', $hasSecurity);
        if ($hasSecurity) {
            $services->set('patrol.controller.widgets', PatrolWidgetsController::class)
                ->args([
                    service('twig'),
                    service(PatrolRepository::class),
                    service('patrol.dashboard'),
                    service('patrol.widget_service'),
                    service('security.token_storage'),
                    // Both writes are state-changing and reachable by a signed-in
                    // browser, so both carry a CSRF token. FrameworkBundle defines
                    // this id whenever symfony/security-csrf is installed, which a
                    // host running SecurityBundle already has.
                    service('security.csrf.token_manager'),
                    param('patrol.types'),
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
        $hasApiPlatform = \is_array($bundles) && isset($bundles['ApiPlatformBundle']);
        $builder->setParameter('patrol.field_api', $hasSecurity && $hasApiPlatform);

        if ($hasSecurity && $hasApiPlatform) {
            $photoDir = \is_string($config['photo_dir'] ?? null) ? $config['photo_dir'] : '%kernel.project_dir%/var/patrol/photos';
            $builder->setParameter('patrol.photo_dir', $photoDir);
            $photoMaxBytes = $config['photo_max_bytes'] ?? 12 * 1024 * 1024;
            $builder->setParameter('patrol.photo_max_bytes', \is_int($photoMaxBytes) ? $photoMaxBytes : 12 * 1024 * 1024);

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

            $services->set('patrol.api.photo_sync', PhotoSyncService::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(ObservationPhotoRepository::class),
                    param('patrol.photo_dir'),
                    param('patrol.photo_max_bytes'),
                ]);

            $services->set('patrol.api.completion', PatrolCompletionService::class)
                ->args([service('doctrine.orm.entity_manager')]);

            foreach ([
                CreatePatrolProcessor::class => 'patrol.api.patrol_upsert',
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
            ->tag('uhifadhi.department_kpi');
    }
}
