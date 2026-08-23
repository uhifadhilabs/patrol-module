<?php

declare(strict_types=1);

namespace UhifadhiLabs\Patrol\Exception;

/** The uploaded file is not a usable GPX track (broken XML, or no track points). */
final class InvalidGpxException extends \RuntimeException
{
}
