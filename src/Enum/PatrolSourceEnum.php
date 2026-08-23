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

namespace UhifadhiLabs\Patrol\Enum;

/**
 * Where a patrol record came from. A sketched manual route must never
 * impersonate a recorded track — consumers branch on this, not on guesswork.
 */
enum PatrolSourceEnum: string
{
    /** Parsed from an uploaded GPX file. */
    case Gpx = 'gpx';

    /** Entered by hand, optionally with a sketched route. */
    case Manual = 'manual';

    /** Posted by a tracking device/app through the ingest API. */
    case Api = 'api';
}
