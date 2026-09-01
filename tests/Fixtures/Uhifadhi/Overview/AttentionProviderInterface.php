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

use Uhifadhi\Entity\AreaOfInterest;

/**
 * DEV/TEST-ONLY stub of uhifadhi's own class of this name, carried in the
 * bundle's autoload-dev so the module's overview contribution compiles, is
 * phpstan'd and is tested standalone. COPIED VERBATIM, signatures and
 * validation alike: the moment it drifts from the host's the tests stop
 * proving anything about the seam. NOT shipped — autoload-dev is dropped on
 * install, and inside uhifadhi the real class is loaded instead.
 *
 * THE SEAM A MODULE PUTS A ROW IN THE HOST'S "NEEDS ATTENTION" LIST THROUGH.
 *
 * The host merges every installed module's items, sorts them by urgency — never
 * by module — and draws them all identically. It does not know what a snare line
 * is and must not: the day it does, uninstalling a module leaves a row behind.
 *
 * Nothing is stored. The aggregator asks every provider on every render, so an
 * item leaves the list when the thing that raised it is dealt with and nobody
 * ever dismisses one by hand.
 *
 * Returning `[]` is the right answer on a good day, and a good day is allowed to
 * look like one.
 *
 * Tagged explicitly at both ends, for the reason
 * {@see OverviewContributorInterface} spells out.
 */
interface AttentionProviderInterface
{
    public const string TAG = 'uhifadhi.overview.attention';

    /** The slug of the module these items belong to; asked only where it is installed. */
    public function moduleSlug(): string;

    /**
     * @return list<AttentionItem>
     */
    public function attentionFor(AreaOfInterest $area, \DateTimeImmutable $now): array;
}
