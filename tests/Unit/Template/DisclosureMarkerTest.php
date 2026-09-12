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
 * A DISCLOSURE THIS MODULE DRAWS SHOWS NO TRIANGLE.
 *
 * `<details>` is how these screens open a rename field or a base picker without
 * shipping a script, and it is the right mechanism — but a `<summary>` wears a
 * browser marker by default, so a row's control renders as "▸RENAME" and a base
 * badge as "▸SURFACE". The design has no such marker anywhere, and could not
 * have: it draws plain buttons, and the disclosure is this port's own
 * adaptation. So hiding the marker is this port's own job, and it is CSS —
 * which means no functional test can see it, which is why it is pinned here.
 *
 * THE CLASS, NOT THE INSTANCES. The summary classes are read out of the
 * TEMPLATES, so a screen that discloses with a new class and forgets the rule
 * fails here rather than shipping a triangle somebody has to notice.
 *
 * Three declarations, because three engines answer for it: `list-style` is the
 * standard's, `::marker` is what a summary's marker actually is, and
 * `::-webkit-details-marker` is the legacy pseudo-element WebKit still draws.
 * The module's own calendar chip already states exactly this triple; this holds
 * every other disclosure to it.
 */
final class DisclosureMarkerTest extends TestCase
{
    public function testEverySummaryClassTheTemplatesDiscloseWithHasItsMarkerHidden(): void
    {
        $classes = self::summaryClasses();
        self::assertNotSame([], $classes, 'the templates disclose with at least one classed summary');

        $sheet = self::stylesheet();

        $missing = [];
        foreach ($classes as $class) {
            // The rule may name the class alone or qualify it by the element, and
            // may share its block with a sibling selector; all of those hide the
            // marker, and which reads better is the sheet's business rather than
            // this test's. So the BLOCKS are read and their selector lists
            // searched, instead of one shape of rule being insisted on.
            $hidden = self::declares($sheet, $class, 'list-style')
                && str_contains($sheet, '.'.$class.'::-webkit-details-marker')
                && str_contains($sheet, '.'.$class.'::marker');

            if (!$hidden) {
                $missing[] = $class;
            }
        }

        sort($missing);

        self::assertSame([], $missing, \sprintf(
            'The templates disclose with [%s] and the sheet does not hide the marker on them — each renders a browser '
            .'triangle before its label. State list-style, ::marker and ::-webkit-details-marker, as .patrol-morechip does.',
            implode(', ', array_map(static fn (string $c): string => '.'.$c, $missing)),
        ));
    }

    /**
     * Whether any rule whose selector list names this class declares a property.
     *
     * @param string $sheet the stylesheet, as text
     */
    private static function declares(string $sheet, string $class, string $property): bool
    {
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $sheet, $rules, \PREG_SET_ORDER);

        foreach ($rules as $rule) {
            foreach (explode(',', $rule[1]) as $selector) {
                $selector = trim($selector);
                if (str_ends_with($selector, '.'.$class) && str_contains($rule[2], $property)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * THE BANNER IS DEFINED ONCE. It is drawn by three screens now — the kinds
     * manager, the patrol types and the stations — and only one of them links
     * the taxonomy sheet, so it belongs to the module's own sheet. Two
     * definitions of one component render differently depending on which sheet
     * a page happened to link last, which is the whole failure this pins.
     */
    public function testTheAreaScopeBannerIsDefinedInTheModulesSheetAndOnlyThere(): void
    {
        self::assertStringContainsString('.tx-say{', self::stylesheet());
        self::assertStringNotContainsString('.tx-say', self::taxonomyStylesheet());
    }

    /**
     * Every class written on a `<summary>` in this bundle's templates, with the
     * Twig expressions a class attribute may carry stripped out of it.
     *
     * @return list<string>
     */
    private static function summaryClasses(): array
    {
        $classes = [];
        foreach (self::templateFiles() as $file) {
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));

            preg_match_all('/<summary[^>]*\sclass="([^"]*)"/', $markup, $found);
            foreach ($found[1] as $attribute) {
                // A conditional class is a class the row may or may not carry;
                // the marker has to be hidden either way, so the literal names
                // are taken and the expression around them dropped.
                $literal = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $attribute);
                foreach (preg_split('/\s+/', trim($literal)) ?: [] as $class) {
                    if ('' !== $class) {
                        $classes[$class] = $class;
                    }
                }
            }
        }

        $names = array_values($classes);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private static function templateFiles(): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(self::bundlePath().'/templates', \FilesystemIterator::SKIP_DOTS);
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.html.twig')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function stylesheet(): string
    {
        return self::read('/public/patrol.css');
    }

    private static function taxonomyStylesheet(): string
    {
        return self::read('/public/taxonomy.css');
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents(self::bundlePath().$path);
        self::assertIsString($contents);

        return $contents;
    }

    private static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }
}
