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

namespace Uhifadhi\Overview;

/**
 * DEV/TEST-ONLY stub of uhifadhi's own class of this name, carried in the
 * bundle's autoload-dev so the module's overview contribution compiles, is
 * phpstan'd and is tested standalone. COPIED VERBATIM, signatures and
 * validation alike: the moment it drifts from the host's the tests stop
 * proving anything about the seam. NOT shipped — autoload-dev is dropped on
 * install, and inside uhifadhi the real class is loaded instead.
 *
 * A CONTRIBUTOR THAT WEARS ITS OWN VOCABULARY, and therefore needs its
 * stylesheet loaded by whoever renders it.
 *
 * Everywhere else in the product this does not arise: a module's pages extend
 * the module's own layout, which links the module's own CSS. The area overview
 * is the one surface where a module's markup is rendered by SOMEBODY ELSE'S
 * template — so unless the host loads each installed module's stylesheet, every
 * chip, badge and status colour on a contributed widget renders naked.
 *
 * A SECOND, OPTIONAL INTERFACE rather than a method on
 * {@see OverviewContributorInterface}: the host's own contributor and the
 * not-installed-here seam have no stylesheet of their own, and a contract that
 * makes them answer a question they have no answer to is a contract that has
 * started guessing. A contributor that ships no CSS simply does not implement
 * this, and the host asks it nothing.
 *
 * The path is what the asset mapper serves the bundle's public/ under, e.g.
 * `bundles/uhifadhipatrol/patrol.css` — the module knows its own
 * bundle's name and the host must not have to derive it.
 */
interface ContributesStylesheetInterface
{
    public function stylesheet(): string;
}
