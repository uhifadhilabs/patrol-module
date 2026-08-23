<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use UhifadhiLabs\PatrolBundle\Controller\PatrolRecordController;
use UhifadhiLabs\PatrolBundle\DependencyInjection\PatrolConfiguration;
use UhifadhiLabs\PatrolBundle\Module\PatrolModuleProvider;
use UhifadhiLabs\PatrolBundle\Repository\PatrolRepository;

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
                        __DIR__.'/../assets' => '@uhifadhilabs/patrol-bundle',
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
                            'prefix' => 'UhifadhiLabs\\PatrolBundle\\Entity',
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
        if ($hasSecurity) {
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
    }
}
