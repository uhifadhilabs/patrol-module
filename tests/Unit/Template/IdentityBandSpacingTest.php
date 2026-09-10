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

namespace Uhifadhi\Patrol\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE BAND OWNS THE GAP UNDER IT, AND NOTHING ADDS A SECOND ONE.
 *
 * The shell's `.factband` carries `margin: 0 0 20px`, so the space between the
 * identity band and whatever a screen puts under it is already spent. An
 * element that then states its own top margin spends it twice, and the band
 * floats away from the page it belongs to by an amount that depends on which
 * screen you are on. This module wears the band on both detail screens, so both
 * are read.
 *
 * The next element declares nothing, or declares `margin: 0` to say out loud
 * that the band is providing the gap.
 *
 * It is a spacing rule and not a rendered check, which is the limit of what it
 * can promise: it catches the doubled gap, not a gap that is wrong in the first
 * place.
 *
 * @see \Uhifadhi\Bundle\AreaBundle\Tests\Unit\Template\IdentityBandSpacingTest the same rule over the core's own templates
 */
final class IdentityBandSpacingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        $root = \dirname(__DIR__, 3).'/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('twig' === $file->getExtension()) {
                yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    #[DataProvider('templates')]
    public function testNothingUnderTheIdentityBandStatesATopMarginOfItsOwn(string $path): void
    {
        $markup = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));

        $offset = 0;
        $found = 0;
        while (preg_match('/<(div|span)\b[^>]*\bclass="[^"]*\bfactband\b/', $markup, $band, \PREG_OFFSET_CAPTURE, $offset)) {
            ++$found;
            $after = self::pastElement($markup, (int) $band[0][1], (string) $band[1][0]);

            if (1 === preg_match('/<[a-z][^>]*>/i', $markup, $next, 0, $after)) {
                self::assertStringNotContainsString('margin-top', $next[0], \sprintf(
                    '%s puts a top margin on the element under the identity band. The band already '
                    .'carries `margin: 0 0 20px`; state `margin: 0` if the element has to say the gap '
                    .'is the band\'s, and nothing otherwise.',
                    basename($path),
                ));
            }

            $offset = $after;
        }

        self::assertGreaterThanOrEqual(0, $found);
    }

    /**
     * The offset just past the element opening at `$start`, by counting its own
     * tag in and out again. The band nests only spans and divs, so a scan of
     * that one tag name is the whole of it.
     */
    private static function pastElement(string $markup, int $start, string $tag): int
    {
        $depth = 0;
        $offset = $start;

        while (preg_match('#<(/?)'.$tag.'\b[^>]*>#i', $markup, $token, \PREG_OFFSET_CAPTURE, $offset)) {
            $at = (int) $token[0][1] + \strlen((string) $token[0][0]);
            $depth += '' === $token[1][0] ? 1 : -1;

            if (0 === $depth) {
                return $at;
            }

            $offset = $at;
        }

        return \strlen($markup);
    }
}
