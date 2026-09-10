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
 * HOW THIS SEEDS. This module has no content provider, so the rows are written
 * by the module's OWN public services against the migrated schema:
 * {@see TrackIngestService::ingest()} for the patrol, its track batch and its
 * points, and {@see TaxonomyAdminService::createKind()} for the area's
 * observation taxonomy. The area and the person are entities the core owns
 * (AreaBundle, TeamBundle) and are persisted directly — this module does not
 * define them and has no service that creates one.
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

        self::assertSame(1, $this->rowCount('patrol_patrol'));
        self::assertSame(4, $this->rowCount('patrol_track_point'));
        self::assertSame(1, $this->rowCount('patrol_track_batch'));
        self::assertSame(1, $this->rowCount('patrol_taxonomy_kind'));
    }

    public function testThisModulesHistoryUnwindsAndComesBack(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();

        $versions = $this->ownVersions();
        self::assertNotSame([], $versions, 'The module ships no migration.');

        $this->console('doctrine:migrations:execute', [
            'versions' => array_reverse($versions),
            '--down' => true,
            '--no-interaction' => true,
        ]);

        $tables = $this->tableNames();
        foreach ($tables as $table) {
            self::assertStringStartsNotWith('patrol_', $table, $table.' survived down().');
        }

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
        $taxonomy->createKind($area, 'Wildlife');

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
