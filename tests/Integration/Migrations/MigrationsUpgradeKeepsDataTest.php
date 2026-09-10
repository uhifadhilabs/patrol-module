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

namespace Uhifadhi\Patrol\Tests\Integration\Migrations;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Patrol\Devkit\PatrolContentProvider;
use Uhifadhi\Patrol\Devkit\PatrolDemoMonth;
use Uhifadhi\Patrol\Tests\Integration\Fixtures\CollectedContentProviders;

/**
 * A PATROL RECORDED BEFORE AN UPGRADE IS STILL THERE AFTER IT.
 *
 * THE ROWS ARE NOT WRITTEN HERE. They are seeded by this module's OWN content
 * provider ({@see PatrolContentProvider}), through the services a person's
 * screens and a ranger's handset use — so what is asserted to survive is shaped
 * the way real content is: tracks with real LINESTRINGs, observations positioned
 * on them, stored photographs, and an area taxonomy. A hand-written fixture
 * would never have produced that spread.
 *
 * HOW THE SEEDING IS DRIVEN, said plainly, because the provider needs two things
 * it does not create:
 *
 *   PEOPLE — {@see PatrolContentProvider::dependsOn()} returns `['team']`, and
 *   the observations are recorded against accounts the installation already has.
 *   So TeamBundle's own content provider is run first, reached through
 *   {@see CollectedContentProviders} — devkit's collector, played by a fixture —
 *   which is the same door devkit uses and keeps the dependency honest.
 *
 *   AN AREA — nothing installed ships area demo content, which is why the
 *   provider takes the first area the installation has and seeds nothing when
 *   there is none. There is no provider to drive, so the area is the smallest
 *   honest fixture: one persisted AreaOfInterest with the boundary its NOT NULL
 *   columns require.
 *
 * Five of the eleven tables carry rows this way; the other six are locked by
 * {@see MigrationsCoverSchemaTest}, which asserts the SCHEMA rather than rows.
 *
 * WHAT down() IS FOR, HONESTLY. A version that creates a table has a `down()`
 * that drops it, and dropping a table drops its rows. The round-trip below is
 * therefore a SCHEMA rehearsal, not a data-safe undo: it proves this module's
 * history unwinds and comes back to a schema `diff` still has nothing to say
 * about. Data survives `up()`, which is the case this test asserts first.
 *
 * The unwind stops at the last core version rather than at `first`: the core's
 * earliest `down()` drops the PostGIS extension, which belongs to whoever
 * provisioned the database and is not this module's to take with it.
 */
final class MigrationsUpgradeKeepsDataTest extends MigrationsTestCase
{
    private const NAMESPACE = 'Uhifadhi\\Patrol\\Migrations';

    public function testRowsWrittenBeforeAnUpgradeSurviveIt(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();
        $this->seedADemoMonth();

        $before = $this->counts();
        self::assertSame(PatrolDemoMonth::PATROLS, $before['patrol_patrol'], 'The seeding has to have left something to protect.');
        self::assertGreaterThan(0, $before['patrol_observation']);
        self::assertGreaterThan(0, $before['patrol_observation_photo']);

        // Today this re-runs a history already at its head; the moment a second
        // version ships it is the upgrade itself, and this assertion is the one
        // that catches a version that rebuilds a table instead of altering it.
        $this->migrateToLatest();

        self::assertSame($before, $this->counts());

        // The geometry column survived too, which no count shows.
        $trackless = $this->connection->fetchOne("SELECT COUNT(*) FROM patrol_patrol WHERE source = 'gpx' AND track IS NULL");
        \assert(is_numeric($trackless));
        self::assertSame(0, (int) $trackless, 'A recorded patrol lost its track across the migrate.');
    }

    public function testThisModulesHistoryUnwindsAndComesBack(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();

        $versions = $this->ownVersions();
        self::assertNotSame([], $versions, 'The module ships no migration.');

        // A migration instance is FROZEN once it has run, and the dependency
        // factory hands out one instance per version, so up() and down() cannot
        // be asked of the same booted kernel.
        // @see vendor/doctrine/migrations/src/AbstractMigration.php
        $this->rebootKernel();

        $this->console('doctrine:migrations:execute', [
            'versions' => array_reverse($versions),
            '--down' => true,
            '--no-interaction' => true,
        ]);

        $tables = $this->tableNames();
        foreach ($tables as $table) {
            self::assertStringStartsNotWith('patrol_', $table, $table.' survived down().');
        }

        $this->rebootKernel();
        $this->migrateToLatest();

        $output = $this->console('doctrine:migrations:diff', [
            '--namespace' => self::NAMESPACE,
            '--allow-empty-diff' => true,
            '--no-interaction' => true,
        ]);
        self::assertStringContainsString('No changes detected', $output);
    }

    /** @return list<string> */
    private function ownVersions(): array
    {
        $versions = [];
        foreach ($this->dependencyFactory()->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $version = (string) $migration->getVersion();
            if (str_starts_with($version, self::NAMESPACE.'\\')) {
                $versions[] = $version;
            }
        }
        sort($versions);

        return $versions;
    }

    /**
     * A month of patrolling, seeded the way devkit seeds it: the providers are
     * collected off the tag, team's runs first because patrol depends on it, and
     * the area is the one fixture nothing installed ships a provider for.
     */
    private function seedADemoMonth(): void
    {
        /** @var CollectedContentProviders $providers */
        $providers = static::getContainer()->get('test_public.devkit.content_providers');
        $byKey = $providers->byKey();

        self::assertArrayHasKey('team', $byKey, 'The people this module records observations against come from team.');
        $byKey['team']->load();

        $this->anArea();

        self::assertArrayHasKey('patrol', $byKey);
        $patrol = $byKey['patrol'];
        self::assertInstanceOf(ContentProviderInterface::class, $patrol);
        $patrol->load();

        $this->entityManager()->clear();
    }

    private function anArea(): void
    {
        $em = $this->entityManager();

        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Sample Area')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $em->persist($area);
        $em->flush();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ([
            'patrol_patrol',
            'patrol_observation',
            'patrol_observation_photo',
            'patrol_taxonomy_kind',
            'patrol_taxonomy_subcategory',
        ] as $table) {
            $counts[$table] = $this->rowCount($table);
        }

        return $counts;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    private function rowCount(string $table): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->connection->quoteSingleIdentifier($table));
        \assert(is_numeric($count));

        return (int) $count;
    }
}
