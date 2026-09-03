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

namespace Uhifadhi\Patrol\Overview;

use Uhifadhi\Overview\OverviewCopyProviderInterface;

/**
 * THE MODULE'S WORDS INSIDE THE HOST'S SENTENCES.
 *
 * The area overview's map plate has a line in the widget picker saying what a
 * person would be looking at, and the "Map as ground" direction has a thesis
 * saying what a full-height plate is worth adopting for. Both used to name this
 * module's subject matter in the host's own copy — "today's tracks", "a stranded
 * patrol" — which is the host knowing what a patrol is, on the one page whose
 * argument is that it does not.
 *
 * So the phrases live here, beside the layers and the tiles they describe. The
 * host writes the sentence round them, and an area without this module simply
 * gets a shorter sentence rather than a promise of tracks nobody draws.
 *
 * PHRASES, NOT SENTENCES: lower case, unpunctuated, in the order this module
 * would have them read. Where the conjunction goes is the host's business.
 */
final readonly class PatrolOverviewCopy implements OverviewCopyProviderInterface
{
    public function moduleSlug(): string
    {
        return PatrolOverviewContributor::SLUG;
    }

    public function copyFragments(string $slot): array
    {
        return match ($slot) {
            // WHAT THIS MODULE PUTS ON THE PLATE, in the words the legend uses.
            // The live positions are not named separately: they are the heads of
            // the same tracks, and a picker line is one line.
            self::SLOT_MAP_LAYERS => ['today’s tracks'],

            // WHY A MAP-LED PAGE IS WORTH IT, when patrols is installed. Both are
            // things this module can only show ON a map: a patrol that has
            // stopped pinging is a dot that stopped moving, and a corner nobody
            // has entered is an absence with a shape. Neither reads in a list.
            self::SLOT_MAP_GROUND_SPOTTING => ['a stranded patrol', 'an unwatched corner'],

            // A slot this module has nothing to say in. Silence is an answer.
            default => [],
        };
    }
}
