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

/*
 * THE STAND-IN INSTALLATION'S IMPORTMAP. The shell's document renders the importmap of
 * whatever application it is installed in, so a suite that renders a page
 * through the page frame needs an application that has one. It carries the
 * entrypoint every Flex-installed application has, `app`, and nothing else —
 * this bundle has no business shipping an importmap, and the fixture exists so
 * that it does not have to.
 */

return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
];
