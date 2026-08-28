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

namespace UhifadhiLabs\Patrol\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Model\WidgetDom;

/**
 * THE WIDGET LIBRARY'S WIRE, as URLs — the map the host's shared preset
 * component is handed, with THIS AREA named in every one of them.
 *
 * Its own service rather than a private method on a controller because a second
 * copy would eventually name one route the other did not — the same reasoning
 * as the incidents module's IncidentWidgetUrls, whose shape this mirrors.
 *
 * Arranging one area's patrols dashboard can never rearrange another's, and
 * that is stated in the URLs rather than trusted to a check.
 */
final readonly class PatrolWidgetUrls
{
    public function __construct(
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * A template carries {@see WidgetDom::ID_PLACEHOLDER} where a preset's id or
     * uuid goes; the library's script substitutes into it, because a preset card
     * that only exists after a click has no server-rendered href to read.
     *
     * @return array<string, string>
     */
    public function forArea(AreaOfInterest $area): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;
        $uuid = ['uuid' => $area->getUuidString()];
        $url = fn (string $route, array $extra = []): string => $this->router->generate($route, [...$uuid, ...$extra]);

        return [
            'save' => $url('patrol_widgets_save'),
            'reset' => $url('patrol_widgets_reset'),
            'preset' => $url('patrol_widgets_preset', ['presetId' => $id]),
            'copy' => $url('patrol_widgets_preset_copy', ['presetId' => $id]),
            'presets' => $url('patrol_widgets_preset_create'),
            'apply' => $url('patrol_widgets_preset_apply', ['presetUuid' => $id]),
            'rename' => $url('patrol_widgets_preset_rename', ['presetUuid' => $id]),
            'delete' => $url('patrol_widgets_preset_delete', ['presetUuid' => $id]),
            'dashboard' => $url('patrol_dashboard'),
        ];
    }
}
