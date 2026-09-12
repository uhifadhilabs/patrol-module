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

namespace Uhifadhi\Patrol\Model;

use Uhifadhi\Patrol\Enum\ObservationPlacementEnum;
use Uhifadhi\Patrol\Enum\PatrolBaseEnum;

/**
 * WHAT A BASE PREFILLS INTO A TYPE, IN ONE PLACE.
 *
 * Choosing a base writes these four values into the type's own columns, where
 * they can then be changed per type. So this is a SEED and not a lookup: a
 * screen, a legend, a coverage measurement and the handset all read the type's
 * own numbers, never this table, and a later release that tunes a default never
 * silently re-tunes an area that had already chosen.
 *
 * THE NUMBERS ARE THE ONES THE SECTION PRINTS IN WORDS. Each row's tunables
 * carry a sentence naming them ("prefilled from the surface base: pace 2–45
 * km/h, buffer 150 m, observations at the ranger's position"), and the sentence
 * is composed from this object, so the two cannot drift.
 *
 * THE GLYPH IS PART OF WHAT A BASE MEANS. A base always has one, so a row whose
 * type picked no mark still draws a mark rather than an empty square. The five
 * in {@see self::GLYPHS} are what the strip OFFERS; a base's own may be outside
 * them — aerial's is — because it is what the badge falls back to and not
 * something a person chose.
 */
final readonly class PatrolBaseDefaults
{
    /**
     * THE HOUSE SET, in the order the glyph strip draws it. A name outside it is
     * a name this module ships no file for, which on a deployment with icon
     * fetching disabled is an empty square.
     *
     * @var list<string>
     */
    public const array GLYPHS = ['route', 'footprints', 'bike', 'car', 'truck'];

    private function __construct(
        public int $paceMinKmh,
        public int $paceMaxKmh,
        public int $coverageBufferM,
        public ObservationPlacementEnum $observationPlacement,
        public string $glyph,
    ) {
    }

    /**
     * The mark a row draws: the type's own if it picked one, else the base's own,
     * else the question mark a row with no base is ASKING with.
     */
    public const string UNCHOSEN_GLYPH = 'circle-question';

    /**
     * THE BOUNDS THE SECTION'S FIELDS CARRY, and the bounds a hand-posted value is
     * CLAMPED to. A form is not a security boundary: a number outside these is one
     * the browser's own `min`/`max` would never have sent, so it is brought inside
     * rather than refused with a sentence nobody typed their way into.
     *
     * A pace floor of zero is deliberate — a patrol that stops is at zero, and the
     * band is what "stopped" is read against.
     */
    public const int MIN_PACE_KMH = 0;

    public const int MAX_PACE_KMH = 120;

    public const int MIN_BUFFER_M = 5;

    public const int MAX_BUFFER_M = 2000;

    public static function glyphOf(?PatrolBaseEnum $base, ?string $chosen): string
    {
        if (null !== $chosen && '' !== $chosen) {
            return $chosen;
        }

        return null === $base ? self::UNCHOSEN_GLYPH : self::of($base)->glyph;
    }

    public static function of(PatrolBaseEnum $base): self
    {
        return match ($base) {
            // A person on foot at 2 km/h and a vehicle on a track at 45: the
            // band a patrol of this base is expected to keep, which is what gap
            // detection and the stopped rule are read against.
            PatrolBaseEnum::Surface => new self(2, 45, 150, ObservationPlacementEnum::AtPosition, 'route'),
            // A flight covers ground faster and sees wider, and the operator's
            // own position is not the coverage — so the place a sighting is at
            // has to be marked.
            PatrolBaseEnum::Aerial => new self(15, 70, 400, ObservationPlacementEnum::OnMap, 'aerial'),
        };
    }

    /**
     * The half-sentence the section prints under a row's tunables, naming every
     * number this base seeded. The row names the base itself.
     */
    public function sentence(): string
    {
        return \sprintf(
            'pace %d–%d km/h, buffer %d m, observations %s',
            $this->paceMinKmh,
            $this->paceMaxKmh,
            $this->coverageBufferM,
            $this->observationPlacement->sentence(),
        );
    }
}
