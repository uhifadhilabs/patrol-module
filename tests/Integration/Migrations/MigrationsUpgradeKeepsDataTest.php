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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Service\TrackIngestService;

/**
 * A PATROL RECORDED BEFORE AN UPGRADE IS STILL THERE AFTER IT.
 *
 * HOW THIS SEEDS, AND WHAT THAT COVERS. This module has no content provider, so
 * the rows are written by the module's own PUBLIC services against the migrated
 * schema — nothing is hand-inserted: {@see TrackIngestService::ingest()} for a
 * patrol carrying a real LINESTRING, and {@see TaxonomyAdminService} for the
 * area's observation taxonomy, a kind and a sub-category under it. That is
 * three of the eleven tables, which is what this bundle's public surface writes
 * without an HTTP request; the other eight are locked by
 * {@see MigrationsCoverSchemaTest}, which asserts the SCHEMA rather than rows.
 * The area and the person come from the core (AreaBundle, TeamBundle) and are
 * persisted directly — this module defines neither and creates neither.
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
        $patrolId = $this->seed();

        // Today this re-runs a history already at its head; the moment a second
        // version ships it is the upgrade itself, and this assertion is the one
        // that catches a version that rebuilds a table instead of altering it.
        $this->migrateToLatest();

        $em = $this->entityManager();
        $em->clear();

        $stored = $em->find(Patrol::class, $patrolId);
        self::assertInstanceOf(Patrol::class, $stored);
        self::assertSame('walk', $stored->getType());
        self::assertSame(4, $stored->getPointCount());

        // The geometry column survived too, which no scalar assertion shows.
        self::assertNotNull($stored->getTrack());

        self::assertSame(1, $this->rowCount('patrol_patrol'));
        self::assertSame(1, $this->rowCount('patrol_taxonomy_kind'));
        self::assertSame(1, $this->rowCount('patrol_taxonomy_subcategory'));
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

    /** @return non-empty-string the id of the seeded patrol */
    private function seed(): string
    {
        $em = $this->entityManager();

        $lead = new User()->setPassword('x')->setEmail('lead@example.test')->setFirstName('Alex')->setLastName('Example');
        $em->persist($lead);

        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Example reserve')->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.0],[35.1,-3.0],[35.1,-2.9],[35.0,-2.9],[35.0,-3.0]]]]}');
        $em->persist($area);
        $em->flush();

        /** @var TrackIngestService $ingest */
        $ingest = static::getContainer()->get('test_public.'.TrackIngestService::class);
        $gpx = file_get_contents(\dirname(__DIR__, 2).'/Fixtures/gpx/short_track.gpx');
        \assert(\is_string($gpx));

        $patrol = $ingest->ingest($gpx, $area, type: 'walk', station: 'North post', lead: $lead, team: 'B. Example');

        /** @var TaxonomyAdminService $taxonomy */
        $taxonomy = static::getContainer()->get('test_public.'.TaxonomyAdminService::class);
        $kind = $taxonomy->createKind($area, 'Wildlife');
        $taxonomy->createSubcategory($kind, 'Elephant');

        $id = (string) $patrol->getId();
        \assert('' !== $id);

        return $id;
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
