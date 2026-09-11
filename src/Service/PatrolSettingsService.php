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

namespace Uhifadhi\Patrol\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Patrol\Entity\PatrolSettings;
use Uhifadhi\Patrol\Model\PatrolAreaSettings;
use Uhifadhi\Patrol\Repository\PatrolSettingsRepository;

/**
 * WHAT ONE AREA RUNS PATROLS ON — read here, written here, and nowhere else.
 *
 * THE INSTALLATION IS THE FALLBACK, NOT THE ANSWER. These numbers were one
 * `patrol:` block for a whole deployment, which is wrong the moment a second
 * area exists: a crater floor and a lake shore do not lose a handset for the
 * same number of minutes. So an area keeps its own row, and an area that has
 * never chosen reads the installation's configuration — which means an
 * untouched default and a chosen one stay distinguishable, and upgrading an
 * installation changes nothing anybody had set.
 *
 * THE BOUNDS ARE THE DESIGN'S. The Settings section draws each control with a
 * min and a max, and a form is not a security boundary, so the same bounds are
 * enforced here on the way in: a hand-posted value is clamped rather than
 * stored.
 */
final readonly class PatrolSettingsService
{
    /** The design's own bounds on the gap threshold, in minutes. */
    public const int MIN_GAP_MINUTES = 1;
    public const int MAX_GAP_MINUTES = 180;

    /** The design's own bounds on how long a discarded patrol is kept, in days. */
    public const int MIN_RETENTION_DAYS = 1;
    public const int MAX_RETENTION_DAYS = 365;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatrolSettingsRepository $settings,
        /*
         * A FLOAT IN CONFIGURATION, A WHOLE NUMBER ON THE SCREEN. The
         * installation's `gap_threshold_minutes` is a float because an importer
         * compares it against a real interval; the Settings control the design
         * draws is minutes, so the fallback is rounded once, here, rather than
         * everywhere it is read.
         */
        private float $installationGapThresholdMinutes,
        private int $installationDiscardRetentionDays,
    ) {
    }

    /** What this area runs on: its own numbers, or the installation's. */
    public function forArea(AreaOfInterest $area): PatrolAreaSettings
    {
        $row = $this->settings->findOneByArea($area);

        if (null === $row) {
            return new PatrolAreaSettings(
                (int) round($this->installationGapThresholdMinutes),
                $this->installationDiscardRetentionDays,
                areaChose: false,
            );
        }

        return new PatrolAreaSettings(
            $row->getGapThresholdMinutes(),
            $row->getDiscardRetentionDays(),
            areaChose: true,
        );
    }

    /**
     * SAVE WHAT THE AREA CHOSE. The row is created on first save, which is what
     * makes "this area has never been configured" a fact the database holds
     * rather than a guess.
     */
    public function save(AreaOfInterest $area, int $gapThresholdMinutes, int $discardRetentionDays): PatrolAreaSettings
    {
        $gap = self::clamp($gapThresholdMinutes, self::MIN_GAP_MINUTES, self::MAX_GAP_MINUTES);
        $retention = self::clamp($discardRetentionDays, self::MIN_RETENTION_DAYS, self::MAX_RETENTION_DAYS);

        $row = $this->settings->findOneByArea($area);
        if (null === $row) {
            $row = new PatrolSettings($area, $gap, $retention);
            $this->entityManager->persist($row);
        } else {
            $row->setGapThresholdMinutes($gap)->setDiscardRetentionDays($retention);
        }

        $this->entityManager->flush();

        return new PatrolAreaSettings($gap, $retention, areaChose: true);
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
