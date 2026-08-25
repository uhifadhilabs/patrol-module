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

namespace Uhifadhi\Module;

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Module\DepartmentKpi, carried in autoload-dev so the
 * bundle compiles and tests standalone; the real class replaces it in-app. Kept BYTE-FOR-BYTE in
 * step with the host's copy — it is a contract, and a stub that drifts is worse than no stub.
 *
 * ONE figure a module computed for ONE department over ONE period.
 *
 * This is the whole vocabulary of the department KPI seam: a module bundle hands these back and
 * the host renders them, so a new module adds plates to every performance surface without a line
 * of host code changing.
 *
 * The two rules the model canon puts on this value object:
 *
 *  1. **`null` is unknown, and unknown is not zero.** The patrol module's coverage query answers
 *     null when no track was recorded in the window — "we did not measure" and "we measured
 *     nothing" are different facts, and a performance page that prints 0% for the first is lying.
 *     Every surface renders an unknown as a dashed labelled slot.
 *  2. **A count moves in percent, a share moves in points.** 88 patrols against 79 is +11.4%;
 *     54% coverage against 61% is −7 pts, never −11.5%. The unit decides, so no caller has to.
 */
final readonly class DepartmentKpi
{
    /** The unit that means "this figure is a share", and therefore moves in points. */
    public const string SHARE = '%';

    /**
     * @param string      $key        stable across modules and releases ('patrols', 'coverage') — the
     *                                board's columns are these, and a goal's kpiRef names one
     * @param string      $label      what a plate calls it ('Patrols logged')
     * @param string      $moduleSlug the module that computed it; the host checks it is attached
     * @param string      $moduleName that module's display name, for the "what produced this" table
     * @param float|null  $value      null means UNKNOWN — never render it as 0
     * @param string      $unit       '' for a bare count, 'km', or {@see self::SHARE}
     * @param float|null  $previous   the same figure over the period before, for the MoM delta
     * @param list<float> $spark      oldest-first series behind the sparkline; [] draws none
     * @param string      $caption    the plate's own provenance line ('Patrols module · Ngorongoro')
     * @param string|null $areaName   NULL means this is the department TOTAL — the figure every
     *                                headline plate, board cell and goal is scored from. A name
     *                                means it is one area's share of that total, which the
     *                                per-area widget reads and nothing else does. A module that
     *                                cannot split its figure by area simply returns totals, and
     *                                the per-area widget says so rather than inventing a split.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $moduleSlug,
        public string $moduleName,
        public ?float $value,
        public string $unit = '',
        public ?float $previous = null,
        public array $spark = [],
        public string $caption = '',
        public ?string $areaName = null,
    ) {
        if ('' === $key || '' === $moduleSlug) {
            throw new \InvalidArgumentException('A department KPI must name its key and the module that computed it.');
        }
    }

    /** Whether there is a figure at all. A false here is a dashed slot, never a zero. */
    public function isKnown(): bool
    {
        return null !== $this->value;
    }

    /** Whether this is the department total rather than one area's share of it. */
    public function isTotal(): bool
    {
        return null === $this->areaName;
    }

    public function isShare(): bool
    {
        return self::SHARE === $this->unit;
    }

    /**
     * The move against the period before: PERCENTAGE POINTS for a share, PERCENT for anything
     * else. Null when there is nothing to compare against — a first month has no delta, and
     * inventing one from zero would read as infinite growth.
     */
    public function delta(): ?float
    {
        if (null === $this->value || null === $this->previous) {
            return null;
        }
        if ($this->isShare()) {
            return $this->value - $this->previous;
        }
        if (0.0 === $this->previous) {
            return null;
        }

        return ($this->value - $this->previous) / $this->previous * 100.0;
    }

    /**
     * The delta as the design writes it: "+11.4%" or "−7 pts", with a REAL minus sign (U+2212)
     * rather than a hyphen, so a negative number lines up with the digits above it.
     */
    public function deltaLabel(): ?string
    {
        $delta = $this->delta();
        if (null === $delta) {
            return null;
        }

        $sign = $delta < 0 ? "\u{2212}" : '+';
        $magnitude = abs($delta);

        return $this->isShare()
            ? \sprintf('%s%s pts', $sign, self::trim($magnitude, 1))
            : \sprintf('%s%s%%', $sign, self::trim($magnitude, 1));
    }

    /**
     * 'good', 'bad' or '' — the class the plate's delta chip wears.
     *
     * Every KPI this seam carries is better when larger (more patrols, more kilometres, more
     * coverage, more observations). A module that ships a KPI where less is better must invert
     * before handing it over, because a department page cannot know which way a stranger's
     * number should point.
     */
    public function direction(): string
    {
        $delta = $this->delta();
        if (null === $delta || 0.0 === $delta) {
            return '';
        }

        return $delta > 0.0 ? 'good' : 'bad';
    }

    /** The figure as a plate prints it: thousands separated, shares and counts whole, km to 0. */
    public function display(): string
    {
        if (null === $this->value) {
            return "\u{2014}";
        }

        return number_format($this->value, 0, '.', ',');
    }

    /**
     * The sparkline's polyline points in the design's own 120×30 viewBox, oldest at x=0.
     *
     * Computed here rather than in Twig because it is arithmetic, and computed at all rather
     * than shipped as an image because the series is the department's. Fewer than two readings
     * cannot draw a line, so they draw nothing.
     */
    public function sparkPoints(float $width = 120.0, float $height = 30.0): string
    {
        $count = \count($this->spark);
        if ($count < 2) {
            return '';
        }

        $low = min($this->spark);
        $high = max($this->spark);
        $range = $high - $low;
        $top = 5.0;
        $bottom = $height - 3.0;

        $points = [];
        foreach ($this->spark as $index => $reading) {
            $x = $width * $index / ($count - 1);
            // A flat series sits on the baseline rather than dividing by zero.
            $y = 0.0 === $range ? $bottom : $bottom - ($reading - $low) / $range * ($bottom - $top);
            $points[] = \sprintf('%.1f,%.1f', $x, $y);
        }

        return implode(' ', $points);
    }

    /** Trailing ".0" is noise on a delta chip; a real decimal is not. */
    private static function trim(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, '.', ',');

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }
}
