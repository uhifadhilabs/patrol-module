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

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;

/**
 * THE TWO RULES A SHIPPED VERSION CANNOT BREAK, READ OFF THE SQL IT PLANS.
 *
 * 1. A column made NOT NULL on a table this version did not create needs a
 *    DEFAULT or a same-file UPDATE. Expand, backfill, contract — in one version,
 *    so a failure leaves the table untouched and nobody edits a vendor file.
 * 2. A DROP in up() needs an `@destructive` marker naming the release the code
 *    stopped using it in. No down() brings dropped data back.
 *
 * The lint is run over what this module SHIPS (which must be clean) and over
 * four fixture versions under Fixtures/Migrations — two that break one rule
 * each, two that keep them — so a lint that passes everything shipped has been
 * shown failing something.
 *
 * The SQL is read by instantiating each version and calling up(): what a
 * version PLANS is the only honest reading of it, and a grep over the file
 * would be reading the source rather than the statements.
 */
final class MigrationLintTest extends MigrationsTestCase
{
    private const SHIPPED_NAMESPACE = 'Uhifadhi\\Patrol\\Migrations';
    private const FIXTURE_NAMESPACE = __NAMESPACE__.'\\Fixtures\\Migrations';

    public function testEveryShippedVersionPassesTheLint(): void
    {
        $directories = $this->dependencyFactory()->getConfiguration()->getMigrationDirectories();
        self::assertArrayHasKey(self::SHIPPED_NAMESPACE, $directories);

        $violations = [];
        foreach ($this->versionsIn($directories[self::SHIPPED_NAMESPACE], self::SHIPPED_NAMESPACE) as $class) {
            $violations = [...$violations, ...$this->lint($class)];
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testARequiredColumnWithNothingToFillItIsRejected(): void
    {
        self::assertSame(
            ['Version20260201000000: "ALTER TABLE patrol_patrol ADD sector VARCHAR(64) NOT NULL" requires a column on patrol_patrol, a table this version does not create, with no DEFAULT and no UPDATE beside it.'],
            $this->lint(self::FIXTURE_NAMESPACE.'\\Version20260201000000'),
        );
    }

    public function testExpandBackfillContractIsAccepted(): void
    {
        self::assertSame([], $this->lint(self::FIXTURE_NAMESPACE.'\\Version20260201000100'));
    }

    public function testADropWithoutTheMarkerIsRejected(): void
    {
        self::assertSame(
            ['Version20260201000200: "ALTER TABLE patrol_patrol DROP legacy_grid" drops in up() without an @destructive marker in the class docblock.'],
            $this->lint(self::FIXTURE_NAMESPACE.'\\Version20260201000200'),
        );
    }

    public function testADropCarryingTheMarkerIsAccepted(): void
    {
        self::assertSame([], $this->lint(self::FIXTURE_NAMESPACE.'\\Version20260201000300'));
    }

    /**
     * @param class-string<AbstractMigration> $class
     *
     * @return list<string>
     */
    private function lint(string $class): array
    {
        $migration = new $class($this->connection, new NullLogger());
        $migration->up(new Schema());

        $statements = array_values(array_map(static fn ($query) => $query->getStatement(), $migration->getSql()));

        $created = [];
        foreach ($statements as $statement) {
            if (1 === preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?"?(\w+)"?/i', $statement, $match)) {
                $created[] = strtolower($match[1]);
            }
        }

        $reflection = new \ReflectionClass($class);
        $docblock = $reflection->getDocComment();
        $marked = \is_string($docblock) && str_contains($docblock, '@destructive');
        $short = $reflection->getShortName();

        $violations = [];
        foreach ($statements as $statement) {
            $violation = $this->violation($statement, $created, $statements, $marked);
            if (null !== $violation) {
                $violations[] = $short.': "'.$statement.'" '.$violation;
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $created    tables this version creates itself
     * @param list<string> $statements every statement the version plans
     */
    private function violation(string $statement, array $created, array $statements, bool $marked): ?string
    {
        if (1 === preg_match('/\bDROP\b/i', $statement) && !$marked) {
            return 'drops in up() without an @destructive marker in the class docblock.';
        }

        if (1 !== preg_match('/^ALTER TABLE "?(\w+)"?\s+(.*)$/is', $statement, $match)) {
            return null;
        }

        $table = strtolower($match[1]);
        $change = $match[2];

        if (\in_array($table, $created, true)) {
            return null;
        }

        if (1 !== preg_match('/\bNOT NULL\b/i', $change)) {
            return null;
        }

        // A DEFAULT fills the rows already there; DEFAULT NULL is not one.
        if (1 === preg_match('/\bDEFAULT\s+(?!NULL\b)/i', $change)) {
            return null;
        }

        foreach ($statements as $other) {
            if (1 === preg_match('/^UPDATE "?'.preg_quote($table, '/').'"?\b/i', $other)) {
                return null;
            }
        }

        return 'requires a column on '.$table.', a table this version does not create, with no DEFAULT and no UPDATE beside it.';
    }

    /**
     * @return list<class-string<AbstractMigration>>
     */
    private function versionsIn(string $directory, string $namespace): array
    {
        $files = glob($directory.'/Version*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'No versions found in '.$directory);

        $classes = [];
        foreach ($files as $file) {
            $class = $namespace.'\\'.basename($file, '.php');
            self::assertTrue(is_subclass_of($class, AbstractMigration::class), $class.' is not a migration.');
            /** @var class-string<AbstractMigration> $class */
            $classes[] = $class;
        }

        return $classes;
    }
}
