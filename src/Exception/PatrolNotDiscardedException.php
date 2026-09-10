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

namespace Uhifadhi\Patrol\Exception;

use Uhifadhi\Patrol\Entity\Patrol;

/**
 * A hold asked for on a patrol that has no retention clock to stop.
 *
 * Only a discarded patrol is on its way to deletion, so only a discarded patrol
 * can be held back from it. Carried out of
 * {@see \Uhifadhi\Patrol\Service\PatrolHoldService} so the rule lives with the
 * write and each caller answers it in its own vocabulary — the detail screen
 * with a 404, which says plainly that there is no such control here rather than
 * storing a flag nothing reads.
 */
final class PatrolNotDiscardedException extends \RuntimeException
{
    public function __construct(public readonly Patrol $patrol)
    {
        parent::__construct(\sprintf('Patrol %s is not discarded, so it has no retention clock to hold.', $patrol->getRef()));
    }
}
