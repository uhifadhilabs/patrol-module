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
 * THE SEAM A MODULE WRITES INTO THE AREA PULSE THROUGH.
 *
 * The platform-wide move log the roadmap plans (Symfony Workflow plus an audit
 * trail) does not exist yet — but every module already keeps its own: the
 * patrols module has patrol events, the incidents module has incident events.
 * They have the same shape, so the pulse asks each module for its moves in a
 * window and merges them, rather than waiting for a log that has not been built.
 * When the platform log lands, this seam is what it fills.
 *
 * Tagged explicitly at both ends, for the reason
 * {@see OverviewContributorInterface} spells out.
 */
interface PulseProviderInterface
{
    public const string TAG = 'uhifadhi.overview.pulse';

    /** The slug of the module these moves belong to; asked only where it is installed. */
    public function moduleSlug(): string;

    /**
     * Every move this module made in the area between `$since` and `$now`.
     *
     * @return list<PulseEvent>
     */
    public function pulseFor(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $now): array;
}
