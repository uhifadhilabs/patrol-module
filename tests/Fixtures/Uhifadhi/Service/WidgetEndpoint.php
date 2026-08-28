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

namespace Uhifadhi\Service;

use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\User;
use Uhifadhi\Exception\InvalidWidgetPreferenceException;
use Uhifadhi\Exception\UnknownWidgetPresetException;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetDom;

/**
 * The three write endpoints every widget library needs, once: save a layout,
 * adopt one of the surface's designs wholesale, throw a layout away.
 * A controller that owns a dashboard surface hands its catalogue in
 * and returns what comes back — it writes no CSRF check, no user lookup and no
 * status code of its own.
 *
 * A SERVICE rather than a trait or an abstract controller on purpose: module
 * bundles register plain controller classes with no base class (a reusable
 * bundle must not inherit the host's AbstractController), and a trait would have
 * to reach for properties the including class happens to have. Injected, this
 * works identically in a host controller and in a bundle's.
 *
 * No permission beyond "signed in" is asked: arranging your own dashboard is not
 * a privilege, and the data on show is the dashboard's, which the caller has
 * already authorised the person to read.
 */
final readonly class WidgetEndpoint
{
    public function __construct(
        private WidgetService $widgets,
        private TokenStorageInterface $tokenStorage,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * The token the library page renders into {@see WidgetDom::CSRF_TOKEN}.
     * Handed to the template by the controller rather than fetched there with
     * Twig's csrf_token(): that function only exists where the twig-bridge CSRF
     * extension is wired, and a module's screen must not depend on it.
     */
    public function csrfToken(WidgetCatalog $catalog, ?Uuid $areaUuid = null): string
    {
        return $this->csrfTokenManager->getToken(self::csrfTokenId($catalog->surface, $areaUuid))->getValue();
    }

    /**
     * The whole layout in one JSON body — the library posts its complete state
     * after every toggle, width change and drop, so a partial write can never
     * leave a half-applied layout behind.
     *
     * 204 on success, 422 for a body that is not JSON or a layout the catalogue
     * does not recognise (unprocessable rather than malformed, as the recording
     * screens answer a rejected form), 403 for a bad token.
     */
    public function save(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid = null): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $request->toArray();
            $this->widgets->save($catalog, $userId, $payload, $areaUuid);
        } catch (InvalidWidgetPreferenceException|JsonException $invalid) {
            return new Response($invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Adopt one of the surface's designs wholesale. The id is in the URL, not the
     * body: this write has no body at all, because a preset IS the layout — which
     * is also why the strip can be a plain form and needs no JavaScript.
     *
     * 204 on success, 422 for a design this surface does not name, 403 for a bad
     * token — the same three answers as save, so a caller handles one shape.
     */
    public function applyPreset(Request $request, WidgetCatalog $catalog, string $presetId, ?Uuid $areaUuid = null): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);

        try {
            $this->widgets->applyPreset($catalog, $userId, $areaUuid, $presetId);
        } catch (InvalidWidgetPreferenceException $invalid) {
            return new Response($invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Keep a layout under a name of the person's own, and put it on.
     *
     * TWO SHAPES, one route. A plain form post carries only `name` and means "the
     * dashboard as it stands" — the affordance is a name box and a button and
     * must work with no JavaScript at all. A JSON body carries `name` AND the
     * composed `order`/`widgets`, which is what the library's "+ New preset"
     * canvas posts: it was never on anyone's dashboard, so there is nothing for
     * the server to read it from.
     *
     * 204, 422 for an unusable name or an empty dashboard, 403 for a bad token.
     */
    public function createCustomPreset(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid = null): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);

        try {
            $body = self::jsonBody($request);
            $name = null !== $body
                ? (\is_string($body['name'] ?? null) ? $body['name'] : '')
                : $request->request->getString('name');
            $layout = null !== $body && (isset($body['order']) || isset($body['widgets']))
                ? ['order' => $body['order'] ?? [], 'widgets' => $body['widgets'] ?? []]
                : null;

            $this->widgets->saveCustomPreset($catalog, $userId, $areaUuid, $name, $layout);
        } catch (InvalidWidgetPreferenceException|JsonException $invalid) {
            return new Response($invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * MAKE A COPY TO CUSTOMIZE. The designs the surface ships are immutable, so
     * this is the only door from one into an editable layout: the copy is the
     * person's own preset and it becomes active at once.
     *
     * The design's id is in the URL for the same reason {@see applyPreset()}
     * takes it there — a preset IS the layout, so this write has no layout to
     * carry. An optional `name` field renames the copy on the way; without one it
     * takes the design's name and says it is a copy.
     *
     * 204, 422 for a design this surface does not name or a name it will not
     * store, 403 for a bad token.
     */
    public function copyPreset(Request $request, WidgetCatalog $catalog, string $presetId, ?Uuid $areaUuid = null): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);

        try {
            $this->widgets->copyBuiltinPreset($catalog, $userId, $areaUuid, $presetId, $request->request->getString('name'));
        } catch (InvalidWidgetPreferenceException $invalid) {
            return new Response($invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The request's JSON body, or null where it is not a JSON request at all —
     * so one route serves both the plain form and the library's fetch() without
     * either having to declare which it is.
     *
     * @return array<string, mixed>|null
     *
     * @throws JsonException on a body that claims to be JSON and is not
     */
    private static function jsonBody(Request $request): ?array
    {
        if (!str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = $request->toArray();

        return $body;
    }

    /**
     * Put one of the person's OWN saved layouts back on. Ownership is part of the
     * lookup, so another person's preset answers 404 — the same answer as one
     * that never existed, which is the only answer that leaks nothing.
     */
    public function applyCustomPreset(Request $request, WidgetCatalog $catalog, Uuid $presetUuid, ?Uuid $areaUuid = null): Response
    {
        return $this->write(
            $request,
            $catalog,
            $areaUuid,
            fn (int $userId) => $this->widgets->applyCustomPreset($catalog, $userId, $areaUuid, $presetUuid),
        );
    }

    /** 204, 422 for an unusable or already-taken name, 404 if it is not theirs, 403 for a bad token. */
    public function renameCustomPreset(Request $request, WidgetCatalog $catalog, Uuid $presetUuid, ?Uuid $areaUuid = null): Response
    {
        return $this->write(
            $request,
            $catalog,
            $areaUuid,
            fn (int $userId) => $this->widgets->renameCustomPreset($catalog, $userId, $areaUuid, $presetUuid, $request->request->getString('name')),
        );
    }

    /** 204, or 404 if it is not theirs, or 403 for a bad token. */
    public function deleteCustomPreset(Request $request, WidgetCatalog $catalog, Uuid $presetUuid, ?Uuid $areaUuid = null): Response
    {
        return $this->write(
            $request,
            $catalog,
            $areaUuid,
            fn (int $userId) => $this->widgets->deleteCustomPreset($catalog, $userId, $areaUuid, $presetUuid),
        );
    }

    /** Back to the catalogue's defaults; the row is deleted. 204, or 403 for a bad token. */
    public function reset(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid = null): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);
        $this->widgets->reset($catalog, $userId, $areaUuid);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The shape every custom-preset write shares: signed in, token checked, then
     * one call whose refusals map to the same three codes — 404 for a preset that
     * is not theirs, 422 for anything else the framework will not store.
     *
     * @param callable(int): void $write
     */
    private function write(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid, callable $write): Response
    {
        $userId = $this->userId();
        $this->denyUnlessCsrfValid($request, $catalog, $areaUuid);

        try {
            $write($userId);
        } catch (UnknownWidgetPresetException $unknown) {
            return new Response($unknown->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidWidgetPreferenceException $invalid) {
            return new Response($invalid->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** A layout belongs to a person; without one there is nothing to read or write. */
    public function userId(): int
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $id = $user instanceof User ? $user->getId() : null;
        if (null === $id) {
            throw new AccessDeniedException('Widget preferences belong to a signed-in user.');
        }

        return $id;
    }

    /**
     * Scoped per surface AND per area: a token minted for one dashboard's library
     * cannot rearrange another's, and on an area-scoped surface a token minted
     * for one area cannot rearrange another area's.
     */
    public static function csrfTokenId(string $surface, ?Uuid $areaUuid = null): string
    {
        return 'widgets_'.$surface.(null !== $areaUuid ? '_'.$areaUuid->toRfc4122() : '');
    }

    /**
     * Both writes are state-changing, so both carry the token. Read from the
     * header the library sends, falling back to the conventional "_token"
     * parameter so a plain form post can reach these endpoints too.
     */
    private function denyUnlessCsrfValid(Request $request, WidgetCatalog $catalog, ?Uuid $areaUuid): void
    {
        $submitted = $request->headers->get(WidgetDom::CSRF_HEADER)
            ?? $request->request->getString('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($catalog->surface, $areaUuid), $submitted))) {
            throw new AccessDeniedException('Invalid CSRF token for the widget library.');
        }
    }
}
