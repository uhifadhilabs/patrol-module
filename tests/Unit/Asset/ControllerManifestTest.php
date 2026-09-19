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

namespace Uhifadhi\Patrol\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;

/**
 * EVERY CONTROLLER THIS PACKAGE NAMES IS A FILE THIS PACKAGE SHIPS.
 *
 * `assets/package.json` is not documentation: Flex copies its
 * `symfony.controllers` block into an installation's `assets/controllers.json`
 * on every `composer require`/`update`, and StimulusBundle then resolves each
 * entry against the package directory when it builds the controllers map —
 * throwing `Controller "…" does not exist in the "…" package.`
 * ({@see \Symfony\UX\StimulusBundle\AssetMapper\ControllersMapGenerator}) when
 * the file is gone. That is not a build warning: it is a 500 on every page of
 * the installation, raised at render.
 *
 * WHICH MAKES DELETING A SHIPPED CONTROLLER A TWO-RELEASE MOVE, never one. An
 * installation's `controllers.json` is its own file and keeps naming what it
 * was given, so the file stays — emptied, deprecated, and named in the upgrade
 * notes — for one release, and is removed only after installations have had a
 * release in which to drop the entry. This test is what stops the one-release
 * version of that from shipping again.
 */
final class ControllerManifestTest extends TestCase
{
    public function testEveryControllerTheManifestNamesIsShippedInThePackage(): void
    {
        $missing = [];
        foreach (self::declaredControllers() as $name => $main) {
            if (!is_file(self::bundlePath().'/assets/'.$main)) {
                $missing[] = \sprintf('%s → assets/%s', $name, $main);
            }
        }

        self::assertSame([], $missing, \sprintf(
            'assets/package.json names [%s], which this package does not ship — every installation that already '
            .'carries the entry in its own assets/controllers.json raises "Controller does not exist in the package" '
            .'on every page. A shipped controller is emptied and deprecated for one release, and removed in the next.',
            implode(', ', $missing),
        ));
    }

    /**
     * Every controller the manifest declares, as name => the path it points at
     * inside `assets/`.
     *
     * @return array<string, string>
     */
    private static function declaredControllers(): array
    {
        /** @var array{symfony?: array{controllers?: array<string, array{main?: string}>}} $manifest */
        $manifest = json_decode(
            (string) file_get_contents(self::bundlePath().'/assets/package.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        $declared = [];
        foreach ($manifest['symfony']['controllers'] ?? [] as $name => $config) {
            $declared[$name] = $config['main'] ?? '';
        }

        self::assertNotSame([], $declared, 'the manifest declares at least one controller');

        return $declared;
    }

    private static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }
}
