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

namespace Uhifadhi\Exception;

/**
 * "No such saved preset of yours." A custom preset is addressed by UUID, and the
 * lookup includes the owner and the scope, so a preset that belongs to someone
 * else raises exactly this — the same answer as one that never existed, which is
 * the only answer that leaks nothing about other people's dashboards.
 *
 * A subtype rather than a flag because the endpoint answers it 404, where every
 * other refused preference is a 422.
 */
final class UnknownWidgetPresetException extends InvalidWidgetPreferenceException
{
}
