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

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The base for the four locks this module's shipped history stands on.
 *
 * It is NOT {@see \Uhifadhi\Patrol\Tests\Integration\IntegrationTestCase}: that
 * one builds the schema from entity metadata with a SchemaTool, which is exactly
 * the thing a migration test must not trust. Here the only way a table comes
 * into existence is `doctrine:migrations:migrate`, run through the real console
 * command against the real PostGIS database, so what is asserted is what an
 * installation gets.
 *
 * A test that runs migrations calls {@see self::emptyDatabase()} first, and
 * emptied means EMPTY: every table in the public schema goes, including
 * `doctrine_migration_versions`, so `migrate` starts from nothing the way a
 * fresh installation does. Tables a PostgreSQL EXTENSION owns are left alone —
 * PostGIS is installed by whoever provisioned the database (or by the core's
 * first version) and `spatial_ref_sys` is not this suite's to drop.
 *
 * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
 * @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php
 */
abstract class MigrationsTestCase extends KernelTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
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

    protected function dependencyFactory(): DependencyFactory
    {
        /** @var DependencyFactory $factory */
        $factory = static::getContainer()->get('doctrine.migrations.dependency_factory');

        return $factory;
    }

    /**
     * Every table in the public schema that no extension owns, dropped.
     *
     * `pg_depend` with `deptype = 'e'` is what marks a table as belonging to an
     * extension; PostGIS's `spatial_ref_sys` is one, and DROPping it needs the
     * extension owner's rights an application user does not have.
     */
    protected function emptyDatabase(): void
    {
        $tables = $this->connection->fetchFirstColumn(<<<'SQL'
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

        foreach ($tables as $table) {
            \assert(\is_string($table));
            $this->connection->executeStatement(\sprintf('DROP TABLE IF EXISTS %s CASCADE', $this->connection->quoteSingleIdentifier($table)));
        }
    }

    /**
     * Run a console command the way an installation runs it, and return what it
     * printed. A non-zero exit fails the test with the output, because a
     * migration that half-ran is the one thing worth reading in full.
     *
     * @param array<string, bool|string|list<string>> $parameters
     */
    protected function console(string $command, array $parameters = [], int $expectedExit = 0): string
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $tester = new ApplicationTester($application);
        $exit = $tester->run(['command' => $command] + $parameters, ['interactive' => false, 'capture_stderr_separately' => false]);

        $output = $tester->getDisplay();
        self::assertSame($expectedExit, $exit, $command.' exited '.$exit.":\n".$output);

        return $output;
    }

    /** The whole history, every namespace, in the order the comparator puts it. */
    protected function migrateToLatest(): string
    {
        return $this->console('doctrine:migrations:migrate', ['version' => 'latest', '--no-interaction' => true]);
    }

    /** @return list<string> */
    protected function tableNames(): array
    {
        return $this->connection->createSchemaManager()->listTableNames();
    }
}
