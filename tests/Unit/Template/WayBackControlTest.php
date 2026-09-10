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

use PHPUnit\Framework\TestCase;

/**
 * THE WAY BACK IS THE SHELL'S CONTROL, NOT THIS MODULE'S OWN.
 *
 * Every patrol screen opens with a way back — "‹ All modules" on the dashboard,
 * "‹ All patrols" on the detail, the log, the import and the calendar, "‹ Patrol
 * PT-…" on an observation. The shell already ships the control for a quiet
 * secondary action, `.tgl`, and it is what every other module's way back wears.
 * Patrol dressed the same control in a private `.backbtn` of its own — a pill
 * with a different size, radius and hover — so one platform control rendered two
 * ways depending on which module the reader was standing in.
 *
 * The glyph goes the same way: the chevron is `shell:chevron-left` from the
 * shell's icon set, never an svg hand-drawn into the markup, so a change to the
 * platform's chevron reaches these pages like it reaches every other.
 */
final class WayBackControlTest extends TestCase
{
    /** The path data of the chevron that was hand-drawn into five templates. */
    private const string INLINE_CHEVRON = 'm15 18-6-6 6-6';

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        $root = \dirname(__DIR__, 3).'/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('twig' !== $file->getExtension()) {
                continue;
            }
            yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testTheWayBackWearsTheShellsOwnControl(string $path): void
    {
        $markup = self::withoutComments((string) file_get_contents($path));
        $back = self::wayBack($markup);

        if (null === $back) {
            // A partial, or a screen whose way out is a page action — nothing to say.
            self::expectNotToPerformAssertions();

            return;
        }

        self::assertMatchesRegularExpression(
            '/\bclass="(?:[^"]*\s)?tgl(?:\s[^"]*)?"/',
            $back,
            \sprintf('%s dresses the way back in a class of its own; the shell ships .tgl for it.', basename($path)),
        );
        self::assertStringContainsString(
            "ux_icon('shell:chevron-left')",
            $back,
            \sprintf('%s draws its own chevron; the glyph is the shell\'s.', basename($path)),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testNoTemplateShipsThePrivateBackPill(string $path): void
    {
        $markup = self::withoutComments((string) file_get_contents($path));

        self::assertStringNotContainsString('backbtn', $markup, basename($path).' still carries the private back pill.');
        self::assertStringNotContainsString(
            self::INLINE_CHEVRON,
            $markup,
            basename($path).' hand-draws the chevron the shell\'s icon set already ships.',
        );
    }

    /**
     * The way back is the anchor a page opens its body with — the first element
     * inside `patrol_page`, above everything the screen is actually about. The
     * "Back to dashboard" anchors in `shell_page_actions` are page actions, and
     * wear the shell's `.cta` by the design's own choice.
     */
    private static function wayBack(string $markup): ?string
    {
        $body = preg_split('/\{%-?\s*block patrol_page\s*-?%\}/', $markup, 2);
        if (!\is_array($body) || 2 !== \count($body)) {
            return null;
        }

        if (1 !== preg_match('/<[a-zA-Z][^>]*>/', $body[1], $first, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        if (!str_starts_with($first[0][0], '<a')) {
            return null;
        }

        $anchor = substr($body[1], (int) $first[0][1]);

        return 1 === preg_match('#^<a\b.*?</a>#s', $anchor, $whole) ? $whole[0] : null;
    }

    /** Twig comments are not markup: a design note may name every class it likes. */
    private static function withoutComments(string $twig): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $twig);
    }
}
