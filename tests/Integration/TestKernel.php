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
use Uhifadhi\Entity\User;
use UhifadhiLabs\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use UhifadhiLabs\Patrol\UhifadhiLabsPatrolBundle;

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
            // The widget library's two writes carry a CSRF token, so the token
            // manager must exist here as it does in a real host (FrameworkBundle
            // only defines it when csrf_protection is on).
            'csrf_protection' => ['enabled' => true],
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

        // Public aliases so tests can fetch the bundle's private services, keyed
        // by class name for readability (see IntegrationTestCase). Needed only
        // until controllers reference them.
        foreach ([
            \UhifadhiLabs\Patrol\Service\TrackIngestService::class => 'patrol.track_ingest',
            \UhifadhiLabs\Patrol\Service\GpxParser::class => 'patrol.gpx_parser',
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
        return sys_get_temp_dir().'/patrol-module-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/patrol-module-tests/log';
    }
}
