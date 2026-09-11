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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Symfony-standard kernel testing: KernelTestCase + KERNEL_CLASS (phpunit.dist.xml)
 * booting TestKernel with debug=true, so the container self-invalidates when test
 * config changes. Talks to the real PostGIS database and rebuilds the schema per
 * test, so every assertion is about what was actually stored.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        self::emptyDatabase($this->em);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * EVERY TABLE IN THE SCHEMA, GONE — not just the ones the mapping happens to
     * describe.
     *
     * `SchemaTool::dropSchema()` emits a DROP per MAPPED table, in dependency
     * order, and swallows a statement that fails. Anything the mapping does not
     * know about therefore survives, and if it holds a foreign key the drop it
     * blocks is swallowed too — so the very next `createSchema()` collides with a
     * table that was supposed to be gone. That makes the suite ORDER-DEPENDENT:
     * the migration tests migrate a schema of their own into this same database,
     * and a test that ran after them failed while the same test passed alone.
     *
     * Read from the catalogue and dropped with CASCADE instead, which is the
     * reading `MigrationsTestCase::emptyDatabase()` already takes. A table an
     * EXTENSION owns is left alone — `pg_depend.deptype = 'e'` is what marks one,
     * PostGIS's `spatial_ref_sys` is one, and dropping it needs rights an
     * application user does not have.
     */
    private static function emptyDatabase(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();

        $tables = $connection->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND NOT EXISTS (
                  SELECT 1 FROM pg_depend d
                  WHERE d.objid = c.oid AND d.deptype = 'e'
              )
            SQL);

        if ([] === $tables) {
            return;
        }

        // ONE statement, not one per table. This runs before every integration
        // test in the suite, and a round trip per table was measurably the
        // slowest thing in it; Postgres takes a comma list and resolves the
        // dependencies itself, which is also why no ordering is computed here.
        $connection->executeStatement(\sprintf(
            'DROP TABLE IF EXISTS %s CASCADE',
            implode(', ', array_map(
                static function (mixed $table) use ($connection): string {
                    \assert(\is_string($table));

                    return $connection->quoteSingleIdentifier($table);
                },
                $tables,
            )),
        ));
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /**
     * Fetch a bundle service through the test container. The test_public.*
     * aliases (TestKernel) exist only because a bundle test kernel has no
     * controllers yet: unreferenced private services are removed at compile
     * time. Delete the aliases once real references exist.
     */
    protected function service(string $id): object
    {
        return static::getContainer()->get('test_public.'.$id);
    }
}
