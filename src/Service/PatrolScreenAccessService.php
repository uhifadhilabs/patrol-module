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

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Patrol\Controller\PatrolRecordController;

/**
 * WHETHER TO DRAW A DOOR — asked in one place, because it is TWO questions and
 * a screen that asks only one of them hands people a link they cannot open.
 *
 * The first is about the INSTALLATION: the screens that create patrols are
 * registered only where SecurityBundle is, so where it is absent there is no
 * route to link at. That is decided at compile time and arrives as a flag.
 *
 * The second is about THE VIEWER: those screens enforce their permission in
 * code, so somebody without it who follows the link gets a 403.
 *
 * A CONTROL THE VIEWER MAY NOT HAVE IS ABSENT, never greyed out — a disabled
 * button tells a ranger a screen exists and they are not trusted with it, and a
 * live link that fails tells them nothing until they have lost the click.
 */
final readonly class PatrolScreenAccessService
{
    /**
     * @param bool                               $recordScreens whether the recording screens EXIST in this installation
     * @param AuthorizationCheckerInterface|null $authorization null where the installation runs no security, which is also where the screens do not exist
     */
    public function __construct(
        private bool $recordScreens = false,
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    public function mayRecord(): bool
    {
        return $this->recordScreens
            && null !== $this->authorization
            && $this->authorization->isGranted(PatrolRecordController::RECORD_PERMISSION);
    }
}
