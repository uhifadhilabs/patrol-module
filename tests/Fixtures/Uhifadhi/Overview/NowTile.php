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
 * ONE TILE OF THE RIGHT-NOW STRIP — the clearest place the seam shows.
 *
 * The host draws the strip and orders it. It writes no tile it does not own:
 * install a module and its tiles join the row, uninstall it and they leave.
 * The same tiles are what the duty board draws at board density, so a number
 * can never read one way in the strip and another on the wall.
 *
 * THE INDEX IS PROVENANCE. `PL·N1` is the patrols module's first now-tile;
 * `AO·N1` is the host's own. It is printed on the plate because provenance has
 * to survive a screenshot.
 *
 * ABSENT IS NOT ZERO. A module with nothing to say returns no tile — it does
 * not return a tile reading 0, which would claim it measured and found none.
 */
final readonly class NowTile
{
    /** The plate reads as it always does. */
    public const string TONE_PLAIN = 'plain';
    /** Something is up, and it is good or at least expected — the accent. */
    public const string TONE_HOT = 'hot';
    /** Something is wrong. The only tone that may raise an alarm on its own. */
    public const string TONE_BAD = 'bad';

    /**
     * @param string      $index    the plate's own index, e.g. `PL·N1` — contributor prefix, then N and a number
     * @param string      $value    the number, already formatted the way the module says it
     * @param string|null $unit     the small unit that rides the number, e.g. `km`
     * @param string      $subline  one line under the number: what the number is made of
     * @param string|null $alarm    the part of the subline that is wrong, drawn in the failure colour
     * @param bool        $live     whether this tile is refreshed by the surface's one polling endpoint
     * @param int         $priority lower sorts earlier; ties keep provider order
     */
    public function __construct(
        public string $index,
        public string $moduleSlug,
        public string $label,
        public string $value,
        public string $subline = '',
        public ?string $unit = null,
        public ?string $alarm = null,
        public string $tone = self::TONE_PLAIN,
        public bool $live = false,
        public ?string $url = null,
        public int $priority = 100,
    ) {
        if ('' === $index || '' === $label || '' === $moduleSlug) {
            throw new \InvalidArgumentException('A now-tile needs an index, a module and a label.');
        }
        if (!\in_array($tone, [self::TONE_PLAIN, self::TONE_HOT, self::TONE_BAD], true)) {
            throw new \InvalidArgumentException(\sprintf('Now-tile "%s" asks for the tone "%s", which the strip does not draw.', $index, $tone));
        }
    }
}
