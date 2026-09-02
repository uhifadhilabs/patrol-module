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

namespace Uhifadhi\Overview;

/**
 * DEV/TEST-ONLY stub of uhifadhi's own class of this name, carried in the
 * bundle's autoload-dev so the module's overview contribution compiles, is
 * phpstan'd and is tested standalone. COPIED VERBATIM, signatures and
 * validation alike: the moment it drifts from the host's the tests stop
 * proving anything about the seam. NOT shipped — autoload-dev is dropped on
 * install, and inside uhifadhi the real class is loaded instead.
 *
 * THE SEAM A MODULE PUTS ITS OWN WORDS INTO A HOST SENTENCE THROUGH — the fifth
 * member of the family that already holds now-tiles, attention items, map layers
 * and pulse events.
 *
 * WHY A SEAM FOR PROSE. Two of the host's own strings named module vocabulary
 * outright, copied verbatim from the design: the operational map's picker note
 * said "today's tracks and open incidents", and the "Map as ground" thesis said
 * a person could spot "a stranded patrol". They read perfectly — and they were
 * the host knowing what a patrol is, on a page whose whole argument is that it
 * does not. Uninstall incidents and the map's own note went on promising open
 * incidents to an area that had none to draw.
 *
 * A FRAGMENT, NEVER A SENTENCE. A module contributes a NOUN PHRASE — "today's
 * tracks", "a stranded patrol" — and the host builds the sentence around it:
 * the punctuation, the conjunction and the clause order are the host's copy,
 * because they belong to a sentence no single module can see the whole of. That
 * is also what lets the sentence degrade honestly: with one module installed it
 * simply says less, and with none it says only what the host can draw.
 *
 * WHAT IS NOT SEAM-FED. The future-module slot names candidate modules by name
 * on purpose — naming what the catalogue holds IS that widget's job, and there
 * is no installed module to ask.
 *
 * Tagged explicitly at both ends, for the reason
 * {@see OverviewContributorInterface} spells out.
 */
interface OverviewCopyProviderInterface
{
    public const string TAG = 'uhifadhi.overview.copy';

    /**
     * WHAT THIS MODULE PUTS ON THE OPERATIONAL PLATE, as the picker note names
     * it — "today's tracks", "open incidents". One phrase per layer group worth
     * naming, in the module's own order.
     *
     * It is not the legend: the legend is generated from the layers themselves
     * and says every one of them. This is the one line that tells somebody
     * choosing the widget what they would be looking at.
     */
    public const string SLOT_MAP_LAYERS = 'map.layers';

    /**
     * WHAT THIS MODULE MAKES A MAP-LED PAGE WORTH ADOPTING FOR — the thing a
     * person spots on a full-height plate that they would not spot in a list:
     * "a stranded patrol", "an unwatched corner".
     *
     * The direction's thesis is the host's, and it is the design's own compare
     * line verbatim. What the modules supply is the list of things being spotted,
     * because a host that wrote "a stranded patrol" itself would be a host that
     * knows what a patrol is.
     */
    public const string SLOT_MAP_GROUND_SPOTTING = 'map_ground.spotting';

    /** The slug of the module these words belong to; asked only where it is installed. */
    public function moduleSlug(): string;

    /**
     * The module's phrases for one slot, in its own order — `[]` for a slot it
     * has nothing to say in, which is an ordinary answer and not a gap.
     *
     * Lower case and unpunctuated: the host decides where the sentence starts
     * and where it ends.
     *
     * @return list<string>
     */
    public function copyFragments(string $slot): array;
}
