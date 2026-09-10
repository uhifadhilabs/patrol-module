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

/**
 * A patrol whose end does not follow its start.
 *
 * Carried out of {@see \Uhifadhi\Patrol\Service\PatrolRecordingService} so the
 * rule about a patrol RECORD lives with the write, and each caller words it for
 * whoever is listening — a sentence under a form field, a wire code on an API.
 */
final class InvalidPatrolTimesException extends \RuntimeException
{
    public function __construct(
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $endedAt,
    ) {
        parent::__construct(\sprintf(
            'A patrol that started at %s cannot end at %s.',
            $startedAt->format(\DateTimeInterface::ATOM),
            $endedAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
