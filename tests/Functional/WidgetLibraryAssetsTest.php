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

namespace UhifadhiLabs\Patrol\Tests\Functional;

use PHPUnit\Framework\TestCase;
use UhifadhiLabs\Patrol\Controller\PatrolWidgetsController;

/**
 * The seam between the rendered page and public/widgets.js.
 *
 * This exists because of a real bug: the controller validated a CSRF token, the
 * template rendered one — and widgets.js never sent it. Every server-side test
 * passed, because each one builds its own header; only a browser ever hit the
 * 403. Nothing in a functional test that talks HTTP can catch that, so these
 * assertions read the shipped asset as TEXT and check that the names on both
 * sides of the seam are literally the same string.
 *
 * If a name here has to change, it changes in three places at once — which is
 * the point.
 */
final class WidgetLibraryAssetsTest extends TestCase
{
    private static function widgetsJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/public/widgets.js');
    }

    private static function libraryTemplate(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/templates/widgets/show.html.twig');
    }

    public function testTheScriptSendsTheCsrfHeaderTheControllerReads(): void
    {
        $js = self::widgetsJs();

        self::assertStringContainsString(
            "var CSRF_HEADER = '".PatrolWidgetsController::CSRF_HEADER."';",
            $js,
            'widgets.js must send the header PatrolWidgetsController reads.',
        );
        // Declaring it is not sending it: both writes must actually attach it.
        self::assertStringContainsString('headers[CSRF_HEADER] = csrfToken();', $js);
        // Two CALLS (the save and the reset), not counting the declaration.
        self::assertSame(
            2,
            substr_count($js, 'postHeaders({'),
            'Both the save and the reset POST must go through postHeaders().',
        );
    }

    public function testTheScriptReadsTheTokenAttributeTheTemplateRenders(): void
    {
        $js = self::widgetsJs();
        $template = self::libraryTemplate();

        self::assertStringContainsString("var CSRF_ATTRIBUTE = 'data-patrol-csrf-token';", $js);
        // The template renders the token on the very element the script reads it
        // from — the same element that carries the two URLs.
        self::assertStringContainsString('data-patrol-csrf-token="{{ csrfToken }}"', $template);
        self::assertStringContainsString("var ROOT_SELECTOR = '[data-patrol-widgets]';", $js);
        self::assertStringContainsString('data-patrol-widgets', $template);
        self::assertStringContainsString('root.getAttribute(CSRF_ATTRIBUTE)', $js);
    }

    public function testEveryHookTheScriptQueriesIsRenderedByTheTemplate(): void
    {
        $template = self::libraryTemplate();

        // The attributes widgets.js drives the page through. A rename on either
        // side leaves the library silently inert, which is exactly the class of
        // bug this test exists for.
        foreach ([
            'data-patrol-save-url',
            'data-patrol-reset-url',
            'data-patrol-notice',
            'data-patrol-reset',
            'data-patrol-widget',
            'data-patrol-on',
            'data-patrol-cols',
            'data-patrol-grip',
            'data-patrol-toggle',
            'data-patrol-toggle-label',
            'data-patrol-span',
            'data-patrol-state',
        ] as $hook) {
            self::assertStringContainsString($hook, $template, \sprintf('The library template must render %s.', $hook));
            self::assertStringContainsString($hook, self::widgetsJs(), \sprintf('widgets.js must use %s.', $hook));
        }
    }

    public function testResetAsksThroughTheHostsSharedConfirmModal(): void
    {
        $template = self::libraryTemplate();

        // The module states WHAT to ask; the host's controller owns the dialog,
        // so every module asks the same way. confirm() is not acceptable here.
        self::assertStringContainsString('data-controller="confirm-modal"', $template);
        self::assertStringContainsString('data-action="click->confirm-modal#ask"', $template);
        self::assertStringContainsString('data-confirm-modal-danger-value="true"', $template);
        self::assertStringContainsString("'confirm-modal:confirmed'", self::widgetsJs());
        self::assertStringNotContainsString('confirm(', self::widgetsJs());
    }
}
