<?php

declare(strict_types=1);

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
