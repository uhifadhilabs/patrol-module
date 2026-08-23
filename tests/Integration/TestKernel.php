<?php

declare(strict_types=1);

namespace UhifadhiLabs\PatrolBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use FundiStadi\PostGISBundle\FundiStadiPostGISBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Access\Entity\User;
use UhifadhiLabs\PatrolBundle\UhifadhiLabsPatrolBundle;

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
        yield new DoctrineBundle();
        yield new FundiStadiPostGISBundle();
        yield new SecurityBundle();
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
        ]);

        // A minimal but REAL security setup (see uhakiki-bundle's TestKernel for
        // the reasoning: loginUser() needs a stateful firewall, and permission
        // checks must go through the real AuthorizationChecker).
        $container->extension('security', [
            'providers' => [
                'app_users' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'main' => ['lazy' => true, 'provider' => 'app_users'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(PATROL_TEST_DATABASE_URL)%',
            ],
            // Map the dev-only Uhifadhi\Access\Entity\User + Spatial\Entity\AreaOfInterest
            // stubs so the Patrol→User and Patrol→AreaOfInterest relations resolve
            // standalone (the real ones are used inside uhifadhi).
            'orm' => [
                'mappings' => [
                    'UhifadhiUserStub' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/Fixtures/Uhifadhi/Access/Entity',
                        'prefix' => 'Uhifadhi\\Access\\Entity',
                        'is_bundle' => false,
                    ],
                    'UhifadhiAreaStub' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/Fixtures/Uhifadhi/Spatial/Entity',
                        'prefix' => 'Uhifadhi\\Spatial\\Entity',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        // Any uhifadhi instance provides layout.html.twig; tests stub it.
        $container->extension('twig', [
            'paths' => [\dirname(__DIR__).'/Integration/Fixtures/templates'],
        ]);

        // Public aliases so tests can fetch the bundle's private services, keyed
        // by class name for readability (see IntegrationTestCase). Needed only
        // until controllers reference them.
        foreach ([
            \UhifadhiLabs\PatrolBundle\Service\TrackIngestService::class => 'patrol.track_ingest',
            \UhifadhiLabs\PatrolBundle\Service\GpxParser::class => 'patrol.gpx_parser',
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }

        $container->extension('patrol', [
            'dev_tools' => true, // this IS the test env — the recipe enables it via when@test
            // Synthetic example vocabulary (never a client's).
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

        // The host routes the bundle's crumbs/back-links generate URLs for —
        // stubbed here (URL generation needs only the definition).
        $routes->add('dashboard_index', '/_host/dashboard');
        $routes->add('dashboard_area_show', '/_host/areas/{uuid}');
        $routes->add('dashboard_area_modules_grid', '/_host/areas/{uuid}/modules');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/patrol-bundle-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/patrol-bundle-tests/log';
    }
}
