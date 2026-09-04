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

namespace Uhifadhi\Patrol\Tests\Integration;

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
use Uhifadhi\Area\UhifadhiAreaBundle;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\FixedRecordVoter;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\HeaderUserAuthenticator;
use Uhifadhi\Patrol\UhifadhiPatrolBundle;
use Uhifadhi\Seam\UhifadhiSeamBundle;
use Uhifadhi\Shell\UhifadhiShellBundle;
use Uhifadhi\Storage\Controller\EvidenceController;
use Uhifadhi\Storage\UhifadhiStorageBundle;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\UhifadhiTeamBundle;
use Uhifadhi\Widget\UhifadhiWidgetBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The smallest INSTALLATION this bundle can live in, and every part of it is
 * real: framework + twig + doctrine + PostGIS + security + api-platform, the
 * shell every patrol screen renders through, the widget framework the dashboard
 * IS, the area a patrol happens in, the seam that switches this module on there,
 * the team the account class comes from and the storage the photographs go to —
 * against a REAL PostGIS database (PATROL_TEST_DATABASE_URL, see
 * phpunit.dist.xml). Vocabulary config uses the synthetic example domain.
 *
 * NOTHING HERE IS A COPY ANY MORE, and that is the change. This kernel used to
 * assemble a stand-in: a `layout.html.twig` typed into a fixture directory, a
 * hand-wired WidgetService pointing at classes copied under tests/Fixtures, and
 * three route definitions standing in for an application's own pages. A copy
 * cannot hold a contract — it pins whatever the copyist believed — so each of
 * them is now the published bundle it was imitating.
 *
 * THE AREA, THE PLACE AND THE PERSON ALL COME FROM MODULES. Patrol's records
 * point at an area (uhifadhi/area-module) and at a person (the class an
 * installation resolves the contract to, played by team's). Neither is this
 * bundle's to define, and neither is stubbed here.
 *
 * TEAM AND AREA ARE BOOTED FOR THEIR MODELS, NOT FOR THEIR DASHBOARDS. Both are
 * modules with widget surfaces of their own, which would land in the registry
 * beside this module's; {@see OnlyThisModulesSurfacesPass} keeps them out, so
 * what this suite asserts about the registry stays about PATROLS.
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
        // An installation installs api-platform; this stands in for one so the
        // bundle's own sync endpoints can be exercised.
        yield new ApiPlatformBundle();
        // The frame every patrol screen renders in.
        yield new UhifadhiShellBundle();
        // Hard-required: the dashboard is a widget surface, not a page with
        // widgets on it.
        yield new UhifadhiWidgetBundle();
        // The place a patrol happens in, the zones the gap card reads, and the
        // six seams this module contributes to an area's overview.
        yield new UhifadhiAreaBundle();
        // The per-area catalogue this module registers itself in.
        yield new UhifadhiSeamBundle();
        // For the account class every patrol, observation and stored layout is
        // keyed by — and for the org chart the department figures walk.
        yield new UhifadhiTeamBundle();
        // Where observation photos go. A hard dependency of this bundle, and
        // registered here in the order a host registers it: flysystem first,
        // because the storage bundle PREPENDS a flysystem storage.
        yield new FlysystemBundle();
        yield new UhifadhiStorageBundle();
        yield new UhifadhiPatrolBundle();
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
            // asset() has to exist: the shell's document and this module's base
            // template both link stylesheets with it. AssetMapper takes over
            // path resolution here, exactly as in a real installation.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        // A minimal but REAL security setup: loginUser() needs a stateful
        // firewall, and permission checks must go through the real
        // AuthorizationChecker rather than a stub that always says yes. The
        // people are TEAM's own entity rather than InMemoryUser, because a
        // patrol and a stored layout both carry a foreign key to a person and an
        // in-memory one has no row to point at.
        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => [
                    // Test-only cost floor, the documented Symfony practice.
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
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
            'orm' => [
                // The skeleton's own choice (config/packages/doctrine.yaml),
                // mirrored here so the bundle's metadata-driven SQL is exercised
                // against the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // NO 'mappings' AND NO 'resolve_target_entities' HERE, both
                // deliberately. Every entity this module points at now arrives
                // with the module that owns it — the area and its zones from
                // uhifadhi/area-module, the person and the org chart from
                // uhifadhi/team-module — and each maps its own; team prepends the
                // contract's resolution from its own bundle, which is the one
                // line an installation used to have to write. If either ever
                // stopped happening the schema would not build and this whole
                // suite would say so at once.
            ],
        ]);

        // A real installation vendors its icon set (bin/console ux:icons:import). These
        // tests are about the module's markup, not about which glyph an icon
        // resolves to, so a missing one renders as nothing rather than failing
        // the page — and the assertions never depend on an icon being there.
        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'ignore_not_found' => true,
        ]);

        $services = $container->services();

        // Public aliases so tests can fetch the bundle's private services, keyed
        // by class name for readability (see IntegrationTestCase). Needed only
        // until controllers reference them.
        foreach ([
            \Uhifadhi\Patrol\Service\TrackIngestService::class => 'patrol.track_ingest',
            \Uhifadhi\Patrol\Service\GpxParser::class => 'patrol.gpx_parser',
            // The two halves of the storage seam, and the registry the hub reads
            // through — so a test can prove the tag was applied AND that the two
            // halves still claim the same keys.
            \Uhifadhi\Patrol\Storage\PatrolFileSource::class => 'patrol.file_source',
            \Uhifadhi\Patrol\Security\PatrolEvidenceVoter::class => 'patrol.evidence_voter',
            \Uhifadhi\Storage\Registry\FileRegistry::class => 'storage.file_registry',
            // The module's six contributions to the host's area overview, and
            // the one reading behind all of them. A host reaches them through
            // their TAGS; these aliases only let a test hold one directly.
            \Uhifadhi\Patrol\Service\PatrolOverviewService::class => 'patrol.overview',
            \Uhifadhi\Patrol\Overview\PatrolOverviewContributor::class => 'patrol.overview.contributor',
            \Uhifadhi\Patrol\Overview\PatrolNowTiles::class => 'patrol.overview.now_tiles',
            \Uhifadhi\Patrol\Overview\PatrolAttention::class => 'patrol.overview.attention',
            \Uhifadhi\Patrol\Overview\PatrolMapLayers::class => 'patrol.overview.map_layers',
            \Uhifadhi\Patrol\Overview\PatrolPulse::class => 'patrol.overview.pulse',
            \Uhifadhi\Patrol\Overview\PatrolOverviewCopy::class => 'patrol.overview.copy',
            // The widget framework, by the ids uhifadhi/widget-module publishes,
            // plus the registry a surface has to be findable in.
            \Uhifadhi\Widget\Service\WidgetService::class => 'widget.service',
            \Uhifadhi\Widget\Service\WidgetEndpoint::class => 'widget.endpoint',
            \Uhifadhi\Widget\Registry\WidgetSurfaceRegistry::class => 'widget.surfaces',
            // The catalogue this module registers itself in, so a test can ask
            // whether Patrols is in it rather than trusting the tag.
            \Uhifadhi\Seam\Service\ModuleCatalogue::class => 'seam.catalogue',
            \Uhifadhi\Seam\Service\ModuleEntryRouteResolver::class => 'seam.entry_routes',
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }

        // Mirrors an installation's api_platform.yaml: JSON only, stateless. The sync
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

        // THE SCREENS THIS MODULE'S CRUMB POINTS AT, mounted from the bundles
        // that own them rather than declared as bare paths here. The area
        // register and the area page are uhifadhi/area-module's; the front door
        // is the shell's. `seam_area_modules` is deliberately absent — the
        // per-area module grid is the seam's page and the seam does not ship one
        // yet, which is exactly the case patrol_url() answers null for and the
        // crumb prints as plain text.
        $routes->import('@UhifadhiShellBundle/src/Controller/', 'attribute');
        $routes->import('@UhifadhiAreaBundle/src/Controller/', 'attribute');
    }

    public function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);

        // Team and area are booted for their models, not for their dashboards.
        $container->addCompilerPass(new OnlyThisModulesSurfacesPass());
    }

    /**
     * THE STAND-IN INSTALLATION'S PROJECT DIRECTORY — an application's asset side
     * and nothing else. The shell's document renders the importmap of whatever
     * application it is installed in, so a suite that renders any page through
     * the page frame needs an application that has one. Pointing the kernel at a
     * fixture is how it gets one without this bundle growing an importmap of its
     * own, which a shipped bundle has no business carrying.
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures/app';
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
