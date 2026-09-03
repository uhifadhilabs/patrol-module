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

namespace UhifadhiLabs\Patrol\Tests\Integration;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use FundiStadi\PostGISBundle\FundiStadiPostGISBundle;
use League\FlysystemBundle\FlysystemBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Entity\User;
use Uhifadhi\Repository\WidgetCustomPresetRepository;
use Uhifadhi\Repository\WidgetPreferenceRepository;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;
use UhifadhiLabs\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use UhifadhiLabs\Patrol\Tests\Integration\Fixtures\HeaderUserAuthenticator;
use UhifadhiLabs\Patrol\UhifadhiLabsPatrolBundle;
use UhifadhiLabs\Storage\Controller\EvidenceController;
use UhifadhiLabs\Storage\UhifadhiLabsStorageBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Smallest possible host app for integration tests: framework + doctrine +
 * the PostGIS bundle + patrol, talking to a REAL PostGIS database
 * (PATROL_TEST_DATABASE_URL, see phpunit.dist.xml). Vocabulary config uses the
 * synthetic example domain.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new UXIconsBundle();
        yield new DoctrineBundle();
        yield new FundiStadiPostGISBundle();
        yield new SecurityBundle();
        // The host installs api-platform; this stands in for that host so the
        // bundle's own sync endpoints can be exercised without one.
        yield new ApiPlatformBundle();
        // Where observation photos go. A hard dependency of this bundle, and
        // registered here in the order a host registers it: flysystem first,
        // because the storage bundle PREPENDS a flysystem storage.
        yield new FlysystemBundle();
        yield new UhifadhiLabsStorageBundle();
        yield new UhifadhiLabsPatrolBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // loginUser() needs a stateful firewall and flashes need a session;
            // the mock file storage is the documented test-env choice.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // The widget library's two writes carry a CSRF token, so the token
            // manager must exist here as it does in a real host (FrameworkBundle
            // only defines it when csrf_protection is on).
            'csrf_protection' => ['enabled' => true],
            // api-platform's own services need these three; a real host running
            // api-platform has them via the framework recipe.
            'property_access' => true,
            'property_info' => ['enabled' => true],
            'serializer' => ['enabled' => true],
            'validation' => ['enabled' => true],
        ]);

        // A minimal but REAL security setup: loginUser() needs a stateful
        // firewall, and permission checks must go through the real
        // AuthorizationChecker rather than a stub that always says yes.
        $container->extension('security', [
            'providers' => [
                'app_users' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                // Mirrors the host: /api is STATELESS and token-authenticated.
                // The credential is a test header (see HeaderUserAuthenticator)
                // because minting real tokens is the host's job — everything
                // else on this path is the real thing.
                'api' => [
                    'pattern' => '^/api',
                    'stateless' => true,
                    'provider' => 'app_users',
                    'custom_authenticators' => [HeaderUserAuthenticator::class],
                    'entry_point' => HeaderUserAuthenticator::class,
                ],
                'main' => ['lazy' => true, 'provider' => 'app_users'],
            ],
            'access_control' => [
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $container->services()->set(HeaderUserAuthenticator::class)
            ->args([service('doctrine.orm.entity_manager')]);

        // The HOST's permission voter, played by a fixture: the bundle declares
        // "patrols.record" and grants it to nobody, so something has to decide
        // who holds it. Tagged by hand — a reusable-bundle test kernel does not
        // autoconfigure.
        $container->services()->set(FixedRecordVoter::class)->tag('security.voter');

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(PATROL_TEST_DATABASE_URL)%',
            ],
            // Map the dev-only Uhifadhi\Entity stubs (User, Position, AreaOfInterest)
            // so the Patrol relations resolve standalone (the real ones inside uhifadhi).
            'orm' => [
                // The host's own choice (config/packages/doctrine.yaml), mirrored
                // here so the bundle's metadata-driven SQL is exercised against
                // the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                'mappings' => [
                    'UhifadhiHostStubs' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/Fixtures/Uhifadhi/Entity',
                        'prefix' => 'Uhifadhi\\Entity',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        // Any uhifadhi instance provides layout.html.twig; tests stub it.
        $container->extension('twig', [
            'paths' => [\dirname(__DIR__).'/Integration/Fixtures/templates'],
        ]);

        // A real host vendors its icon set (bin/console ux:icons:import). These
        // tests are about the module's markup, not about which glyph an icon
        // resolves to, so a missing one renders as nothing rather than failing
        // the page — and the assertions never depend on an icon being there.
        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'ignore_not_found' => true,
        ]);

        /*
         * THE HOST'S WIDGET FRAMEWORK, registered the way the host registers it.
         * The patrols surface rides this rather than shipping a copy, so the
         * tests have to exercise the real thing — a library that only worked
         * against a stand-in would be a library nobody has proved works. (The
         * storage bundle's Files hub controller reads the same framework, so
         * the container needs it compiled here either way.)
         */
        $services = $container->services();
        $services->set(WidgetPreferenceRepository::class)
            ->args([service('doctrine')])->tag('doctrine.repository_service');
        $services->set(WidgetCustomPresetRepository::class)
            ->args([service('doctrine')])->tag('doctrine.repository_service');
        $services->set(WidgetService::class)->args([
            service(WidgetPreferenceRepository::class),
            service(WidgetCustomPresetRepository::class),
            service('doctrine.orm.entity_manager'),
        ]);
        $services->set(WidgetEndpoint::class)->args([
            service(WidgetService::class),
            service('security.token_storage'),
            service('security.csrf.token_manager'),
        ]);

        // Public aliases so tests can fetch the bundle's private services, keyed
        // by class name for readability (see IntegrationTestCase). Needed only
        // until controllers reference them.
        foreach ([
            \UhifadhiLabs\Patrol\Service\TrackIngestService::class => 'patrol.track_ingest',
            \UhifadhiLabs\Patrol\Service\GpxParser::class => 'patrol.gpx_parser',
            // The two halves of the storage seam, and the registry the hub reads
            // through — so a test can prove the tag was applied AND that the two
            // halves still claim the same keys.
            \UhifadhiLabs\Patrol\Storage\PatrolFileSource::class => 'patrol.file_source',
            \UhifadhiLabs\Patrol\Security\PatrolEvidenceVoter::class => 'patrol.evidence_voter',
            \UhifadhiLabs\Storage\Registry\FileRegistry::class => 'storage.file_registry',
            // The module's six contributions to the host's area overview, and
            // the one reading behind all of them. A host reaches them through
            // their TAGS; these aliases only let a test hold one directly.
            \UhifadhiLabs\Patrol\Service\PatrolOverviewService::class => 'patrol.overview',
            \UhifadhiLabs\Patrol\Overview\PatrolOverviewContributor::class => 'patrol.overview.contributor',
            \UhifadhiLabs\Patrol\Overview\PatrolNowTiles::class => 'patrol.overview.now_tiles',
            \UhifadhiLabs\Patrol\Overview\PatrolAttention::class => 'patrol.overview.attention',
            \UhifadhiLabs\Patrol\Overview\PatrolMapLayers::class => 'patrol.overview.map_layers',
            \UhifadhiLabs\Patrol\Overview\PatrolPulse::class => 'patrol.overview.pulse',
            \UhifadhiLabs\Patrol\Overview\PatrolOverviewCopy::class => 'patrol.overview.copy',
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }

        // Mirrors the host's api_platform.yaml: JSON only, stateless. The sync
        // endpoints are asserted against the CONTRACT's literal field names, so
        // a JSON-LD default here would be testing something the app never sees.
        $container->extension('api_platform', [
            'title' => 'Patrol module test API',
            'version' => '1.0.0',
            'formats' => ['json' => ['application/json']],
            'defaults' => ['stateless' => true],
        ]);

        // The evidence storage the photo tests write real bytes into — a
        // throwaway directory, because a mocked filesystem would only prove the
        // mock. The rest is the bundle's own defaults, which is what a host gets.
        $container->extension('storage', [
            'evidence' => [
                'adapter' => 'local',
                'directory' => sys_get_temp_dir().'/patrol-module-tests/evidence',
            ],
        ]);

        $container->extension('patrol', [
            'dev_tools' => true, // this IS the test env — the recipe enables it via when@test
            // Synthetic example vocabulary (never a client's). Deliberately NOT
            // the field app's words: patrol types and observation categories are
            // DEPLOYMENT config, and the sync tests prove the endpoints work
            // against whatever a deployment configured rather than against a
            // list hard-coded to match one client.
            //
            // "drone" is the exception, and only because it carries behaviour —
            // no track, declared sectors (Â§5, Â§7) — so the rule has something
            // real to fire on.
            'types' => [
                'walk' => ['label' => 'Walking round'],
                'boat' => ['label' => 'Boat'],
            ],
            'observation_categories' => [
                'maintenance' => ['label' => 'Maintenance need'],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $controllers = \dirname(__DIR__, 2).'/src/Controller/';
        if (is_dir($controllers)) {
            $routes->import($controllers, 'attribute');
        }

        // The evidence serving route, mounted exactly as a host mounts it: the
        // storage bundle's controller carries its own #[Route], so the host
        // imports the directory. Without this the photos card would link at
        // nothing and the voter would never be asked anything.
        // Only the serving route: the storage bundle's Files hub is a host
        // screen standing on the host's widget framework, which a bundle test
        // kernel does not have and does not need.
        $evidence = (new \ReflectionClass(EvidenceController::class))->getFileName();
        if (\is_string($evidence)) {
            $routes->import($evidence, 'attribute');
        }

        // The /api entry point, mounted exactly as the host mounts it
        // (config/routes/api_platform.yaml). The bundle's own ApiResource
        // classes are discovered by api-platform without any registration —
        // see src/ApiResource/PatrolSync.php.
        $routes->import('.', 'api_platform')->prefix('/api');

        // The host routes the bundle's crumbs/back-links generate URLs for —
        // stubbed here (URL generation needs only the definition).
        $routes->add('dashboard_index', '/_host/dashboard');
        $routes->add('dashboard_area_show', '/_host/areas/{uuid}');
        $routes->add('dashboard_area_modules_grid', '/_host/areas/{uuid}/modules');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/patrol-module-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/patrol-module-tests/log';
    }
}
