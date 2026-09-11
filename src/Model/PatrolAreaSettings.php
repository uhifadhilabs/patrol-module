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

namespace Uhifadhi\Patrol\Model;

/**
 * THE NUMBERS ONE AREA RUNS PATROLS ON, as the module reads them — the area's
 * own where it has chosen, and the installation's where it has not.
 *
 * IT IS NOT THE ENTITY. A caller that took the entity would have to handle a
 * null row, and every caller would answer the fallback question its own way;
 * this is the answered version, and there is only one place it is answered
 * ({@see \Uhifadhi\Patrol\Service\PatrolSettingsService}).
 */
final readonly class PatrolAreaSettings
{
    /**
     * @param int  $gapThresholdMinutes  minutes without a fix before a track is drawn as broken
     * @param int  $discardRetentionDays days a discarded patrol stays recoverable before it is purged
     * @param bool $areaChose            whether these are the area's own numbers or the installation's
     */
    public function __construct(
        public int $gapThresholdMinutes,
        public int $discardRetentionDays,
        public bool $areaChose,
    ) {
    }
}
