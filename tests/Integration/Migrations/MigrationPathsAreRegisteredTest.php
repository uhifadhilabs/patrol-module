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

/**
 * BOOTING AN INSTALLATION YIELDS THIS MODULE'S NAMESPACE, POINTING AT A REAL
 * DIRECTORY — and an installation configured nothing to get it.
 *
 * The registration is one guarded block in the bundle's `prependExtension()`,
 * the seam the migrations bundle documents for it:
 *
 * > migrations_paths: A list of namespace/path pairs where to look for migrations.
 *
 * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
 * @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php
 *      — the `migrations_paths` node, keyed by namespace.
 */
final class MigrationPathsAreRegisteredTest extends MigrationsTestCase
{
    private const NAMESPACE = 'Uhifadhi\\Patrol\\Migrations';

    public function testTheModuleRegistersItsOwnMigrationsNamespace(): void
    {
        $directories = $this->dependencyFactory()->getConfiguration()->getMigrationDirectories();

        self::assertArrayHasKey(self::NAMESPACE, $directories, 'The bundle did not register its migrations path. Registered: '.implode(', ', array_keys($directories)));
    }

    public function testEveryRegisteredNamespacePointsAtADirectoryThatExists(): void
    {
        $directories = $this->dependencyFactory()->getConfiguration()->getMigrationDirectories();

        foreach ($directories as $namespace => $path) {
            self::assertDirectoryExists($path, $namespace.' is registered at a path that does not exist.');
        }
    }

    /**
     * The namespace has to RESOLVE, not merely be configured: a `migrations/`
     * directory under a psr-4 root of `src/` autoloads nothing at all, and the
     * failure only shows up on a case-sensitive filesystem in an installation.
     */
    public function testTheNamespaceResolvesToTheDirectoryThroughComposer(): void
    {
        $directories = $this->dependencyFactory()->getConfiguration()->getMigrationDirectories();
        self::assertArrayHasKey(self::NAMESPACE, $directories);

        $files = glob($directories[self::NAMESPACE].'/Version*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The module ships no migration.');

        foreach ($files as $file) {
            $class = self::NAMESPACE.'\\'.basename($file, '.php');
            self::assertTrue(class_exists($class), $class.' does not autoload — check the psr-4 prefix in composer.json.');
        }
    }
}
