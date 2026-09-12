/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

import { Controller } from '@hotwired/stimulus';

/*
 * ONE POINT, PLACED BY HAND.
 *
 * The Stations section asks where a station's patrols set off from, and a
 * coordinate is not something anybody should have to type. So the section states
 * a plate in PHP with ONE marker on it, and this is what lets that marker be
 * dragged: the fix travels to the server in two hidden fields, and the section
 * saves it with everything else in one POST.
 *
 * IT BUILDS NO MAP AND IMPORTS NO MAP LIBRARY, which is the whole reason it can
 * exist here at all. Every map on the platform is the atlas's plate, and UX Map's
 * own connect event hands over the markers IT created, already on the map it
 * created:
 *
 *     event.detail.markers   the created markers
 *     event.detail.map       the map they are on
 *
 * so making one draggable is `marker.dragging.enable()` on an object somebody
 * else made. Nothing here reaches for the map library, holds a second copy of it,
 * or draws anything.
 *
 * @see vendor/symfony/ux-map/assets/dist/abstract_map_controller.js — dispatches
 *      "connect" with { map, markers, … }
 * @see https://symfony.com/bundles/ux-map/current/index.html
 *
 * THE EVENT IS LISTENED FOR ON THIS ELEMENT, not on the map element: both of UX
 * Map's lifecycle events bubble, and this controller sits on the panel that also
 * holds the fields and the read-out, which is the element that has to react.
 *
 * CLICKING THE PLATE MOVES THE MARKER TOO, because the design says so — dragging
 * a marker across a whole area to a place already on screen is a long way round.
 */
export default class extends Controller {
    static targets = ['lat', 'lng', 'readout'];

    connect() {
        this.onMap = (event) => this.placed(event);
        this.element.addEventListener('ux:map:connect', this.onMap);
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this.onMap);
        this.marker = null;
    }

    /**
     * The plate exists and its one marker is on it. Let it be moved, and read out
     * wherever it lands.
     */
    placed(event) {
        const { map, markers } = event.detail;

        this.marker = markers?.[0] ?? null;
        if (null === this.marker) {
            return;
        }

        this.marker.dragging?.enable();
        this.marker.on('dragend', () => this.moved(this.marker.getLatLng()));
        map.on('click', (click) => {
            this.marker.setLatLng(click.latlng);
            this.moved(click.latlng);
        });
    }

    /**
     * Where it now is: into the two fields the section posts, and into the chip
     * beside them — in the degrees-minutes-seconds every coordinate in this module
     * is printed in, so the chip and the row it will become read alike.
     */
    moved({ lat, lng }) {
        this.latTarget.value = lat;
        this.lngTarget.value = lng;

        if (this.hasReadoutTarget) {
            this.readoutTarget.textContent = `${dms(lat, 'N', 'S')} ${dms(lng, 'E', 'W')}`;
        }
    }
}

/** One component of a position, cased and rounded the way the server prints it. */
function dms(value, positive, negative) {
    const total = Math.round(Math.abs(value) * 3600);
    const degrees = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const pad = (n) => String(n).padStart(2, '0');

    return `${degrees}°${pad(minutes)}'${pad(seconds)}"${value < 0 ? negative : positive}`;
}
