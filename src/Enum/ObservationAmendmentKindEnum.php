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
 * WHAT AN AMENDMENT IS CORRECTING — in plain field language.
 *
 * The cases name THE THING ON THE PAGE a ranger is looking at — the note, what
 * it was, where it was, when it was — never "field", "attribute" or "property".
 * A correction is written by whoever walked the ground, at the end of a long
 * day, and a vocabulary borrowed from a database schema is a vocabulary that
 * gets the wrong one picked.
 *
 * {@see Other} exists so nobody is ever stuck choosing a wrong word for a real
 * correction. A trail that forced a bad category on an entry would be a trail
 * that lied in a small way about every entry that did not fit — and the whole
 * value of the record is that it does not do that.
 *
 * DELIBERATELY NOT A LIST OF COLUMNS. An amendment does not rewrite the field it
 * names: it says, in words, what is right. So the case is a heading over prose,
 * not an instruction to a mapper, and adding a case never means adding a write
 * path.
 */
enum ObservationAmendmentKindEnum: string
{
    case Note = 'note';
    case Category = 'category';
    case Position = 'position';
    case Time = 'time';
    case Photo = 'photo';
    case Other = 'other';

    /**
     * The word on the form's chip — the design's own, verbatim.
     *
     * "adding a photo" rather than "a photo" because the chip is a thing you are
     * about to DO, while the trail's pill below is a thing that WAS done. Same
     * case, two tenses; writing one string for both would make one of the two
     * screens read wrong.
     */
    public function chipLabel(): string
    {
        return match ($this) {
            self::Note => 'the note',
            self::Category => 'what it was',
            self::Position => 'where it was',
            self::Time => 'when it was',
            self::Photo => 'adding a photo',
            self::Other => 'something else',
        };
    }

    /** The pill on the trail entry: what this correction was about, in one word. */
    public function pillLabel(): string
    {
        return match ($this) {
            self::Photo => 'a photo',
            default => $this->chipLabel(),
        };
    }

    /** How the history line ends: "amended by S. Laizer — the note". */
    public function historyLabel(): string
    {
        return match ($this) {
            self::Photo => 'a photo attached',
            default => $this->pillLabel(),
        };
    }

    /**
     * Whether this kind is ABOUT a photograph — the one case the trail draws
     * differently (the design's `.att` entry, with the attachment under it).
     *
     * A photograph may be attached to any kind of amendment; this only says
     * which one is primarily about one.
     */
    public function isAttachment(): bool
    {
        return self::Photo === $this;
    }

    /** The kind a submitted value names, or null — never a guess. */
    public static function tryFromSubmitted(string $raw): ?self
    {
        return self::tryFrom(trim($raw));
    }
}
