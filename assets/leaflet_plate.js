import { Controller } from '@hotwired/stimulus';
import { satelliteLayer, streetLayer } from 'uhifadhi/basemaps';

/*
 * The shared imagery plate every patrol map is built on — the design's viewer
 * frame (zoom column, layers menu, fullscreen) over real tiles.
 *
 * Leaflet, self-hosted, read off `window.L`: the host ships it as a classic
 * <script> (assets/leaflet/leaflet.js) and the patrol base template loads the
 * same file, so there is exactly ONE Leaflet on the page and no CDN. MapLibre
 * is deliberately not used — see the host's map_controller.js for why (raster
 * tiles + GeoJSON need no WebGL, and WebGL failed silently in constrained
 * environments).
 *
 * The two base layers come from the HOST's one basemap module
 * (`uhifadhi/basemaps`, an importmap specifier): satellite is Google's official
 * Map Tiles API, falling back to keyless imagery where no key is configured.
 * The module must not define a third opinion about what satellite looks like.
 *
 * Subclasses implement draw() — what this particular plate puts on the map —
 * and fitTo() is how they hand back the bounds to open on.
 */

/* Fixed hexes, never theme tokens: everything here is drawn over satellite
   imagery, which is dark in both themes. Same reasoning (and the same values)
   as PatrolDashboardService::TRACK_COLORS. */
export const BOUNDARY_LINE = 'rgba(234,242,236,.55)';
export const BOUNDARY_LINE_FAINT = 'rgba(234,242,236,.35)';
export const BOUNDARY_FILL = '#3ED9A8';
/* The observation ring's amber lives in patrol.css (.patrol-ring) — it is drawn
   as a DOM marker, not a canvas shape, so the stylesheet owns its colour. */

/** Parse a geometry column's GeoJSON text; anything unusable is simply not drawn. */
export function parseGeometry(text) {
    if (typeof text !== 'string' || text === '') {
        return null;
    }
    try {
        const value = JSON.parse(text);
        return value && typeof value === 'object' && value.type ? value : null;
    } catch {
        return null;
    }
}

/**
 * The first and last vertex of a (Multi)LineString, as Leaflet [lat, lng] — the
 * design's ● start and ○ end. Null for anything that is not a line.
 */
export function endpoints(geometry) {
    let line = null;
    if (geometry?.type === 'LineString') {
        line = geometry.coordinates;
    } else if (geometry?.type === 'MultiLineString') {
        line = (geometry.coordinates ?? []).flat();
    }
    if (!Array.isArray(line) || line.length < 1) {
        return null;
    }
    const first = line[0];
    const last = line[line.length - 1];

    return { start: [first[1], first[0]], end: [last[1], last[0]] };
}

export default class extends Controller {
    static targets = ['canvas', 'layerMenu', 'layerBtn'];
    static values = { payload: Object };

    connect() {
        const L = window.L;
        if (!L) {
            console.error('[patrol] window.L (Leaflet) is not loaded — the patrol base template must include leaflet.js');
            return;
        }
        this.L = L;

        // The design's chrome owns the corners, so Leaflet's own zoom control is
        // off: the +/− pills call zoomIn/zoomOut instead.
        // Wheel zoom is OFF until Ctrl (⌘ on a Mac) is held: a plate sits inside a
        // scrolling page, and scrolling past a map must never zoom it. The same
        // bargain Google Maps embeds make, and the hint says so.
        this.map = L.map(this.canvasTarget, {
            zoomControl: false,
            attributionControl: true,
            scrollWheelZoom: false,
        });
        // Imagery attribution is not optional — it rides bottom-right, styled as
        // one of the design's pills (patrol.css).
        this.map.attributionControl.setPrefix(false);
        // A live scale bar replaces the design's static "500 m ⎯⎯⎯" label: the
        // plate zooms, so a fixed number would be a lie.
        L.control.scale({ imperial: false, position: 'bottomright' }).addTo(this.map);

        // The host's basemaps, not the module's own: the same imagery a person
        // sees on the area map, on every patrol map.
        this.bases = {
            satellite: satelliteLayer(L, this.map),
            osm: streetLayer(L),
        };
        this.bases.satellite.addTo(this.map); // the design's default

        // A plate with nothing to show still has to be a map: this is the view
        // it opens on when neither a boundary nor a track fits it.
        this.map.setView([-3.2, 35.5], 8);

        this.payload = this.hasPayloadValue ? this.payloadValue : {};
        this.draw();

        // Clicking anywhere else — the map, the page, another control — closes
        // the layer menu, which is closed to begin with.
        this.closeLayers = (event) => {
            if (!this.hasLayerMenuTarget || this.layerMenuTarget.hidden) {
                return;
            }
            if (this.layerMenuTarget.contains(event.target)) {
                return;
            }
            if (this.hasLayerBtnTarget && this.layerBtnTarget.contains(event.target)) {
                return; // the toggle handles itself
            }
            this.setLayerMenu(false);
        };
        document.addEventListener('click', this.closeLayers);
        this.watchWheel();
        this.dismissLayers = (event) => {
            if (event.key === 'Escape') {
                this.setLayerMenu(false);
            }
        };
        document.addEventListener('keydown', this.dismissLayers);
    }

    /*
     * Tear the map down on disconnect (Turbo navigation, cached previews).
     * Without this, revisiting the page runs L.map() on an already-initialised
     * container and Leaflet throws — the map then never builds.
     */
    disconnect() {
        this.canvasTarget.removeEventListener('wheel', this.onWheel);
        document.removeEventListener('fullscreenchange', this.onFullscreen);
        document.removeEventListener('keydown', this.onModifier);
        document.removeEventListener('keyup', this.onModifier);
        clearTimeout(this.hintTimer);
        document.removeEventListener('click', this.closeLayers);
        document.removeEventListener('keydown', this.dismissLayers);
        this.map?.remove();
        this.map = null;
    }

    /*
     * Ctrl/⌘ + wheel zooms; a plain wheel scrolls the page and flashes the hint.
     * In full screen there is no page behind the map, so a plain wheel zooms —
     * the modifier exists only to protect the scroll it would otherwise steal.
     */
    watchWheel() {
        this.onWheel = (event) => {
            if (this.fullscreen) {
                return;
            }
            if (event.ctrlKey || event.metaKey) {
                this.map.scrollWheelZoom.enable();
                this.hideHint();

                return;
            }
            this.map.scrollWheelZoom.disable();
            this.showHint();
        };
        this.canvasTarget.addEventListener('wheel', this.onWheel, { passive: true });

        // Arm it on the key press, not on the first wheel tick — otherwise the
        // first notch of a Ctrl+scroll is swallowed while the handler switches on.
        this.onModifier = (event) => {
            if (this.fullscreen || !this.map) {
                return;
            }
            if (event.ctrlKey || event.metaKey) {
                this.map.scrollWheelZoom.enable();
            } else {
                this.map.scrollWheelZoom.disable();
            }
        };
        document.addEventListener('keydown', this.onModifier);
        document.addEventListener('keyup', this.onModifier);

        this.onFullscreen = () => {
            this.fullscreen = document.fullscreenElement?.contains(this.element) ?? false;
            if (this.fullscreen) {
                this.map.scrollWheelZoom.enable();
                this.hideHint();
            } else {
                this.map.scrollWheelZoom.disable();
            }
            this.map.invalidateSize();
        };
        document.addEventListener('fullscreenchange', this.onFullscreen);
    }

    showHint() {
        if (!this.hint) {
            this.hint = document.createElement('span');
            this.hint.className = 'patrol-wheelhint';
            // ⌘ on a Mac, Ctrl everywhere else — the key people actually press.
            const mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
            this.hint.textContent = mac ? 'Use ⌘ + scroll to zoom the map' : 'Use Ctrl + scroll to zoom the map';
            this.element.appendChild(this.hint);
        }
        this.hint.classList.add('on');
        clearTimeout(this.hintTimer);
        this.hintTimer = setTimeout(() => this.hint?.classList.remove('on'), 1400);
    }

    hideHint() {
        clearTimeout(this.hintTimer);
        this.hint?.classList.remove('on');
    }

    /** What this plate draws. Subclasses override. */
    draw() {}

    /**
     * Open on these bounds, unless they are empty (nothing was drawn).
     *
     * `maxZoom` stops a tiny subject — one observation, a 200 m walk — being
     * fitted to a meaningless street-level zoom. Nothing here constrains the
     * map afterwards: there is no maxBounds and no minZoom, so a person can
     * always zoom out to the whole area by hand.
     */
    fitTo(bounds, { padding = 26, maxZoom } = {}) {
        if (bounds && bounds.isValid()) {
            this.map.fitBounds(bounds, { padding: [padding, padding], maxZoom });
        }
    }

    /** The area outline: the design's dashed hairline, no fill on a detail plate. */
    drawBoundary(text, { faint = false, fill = false } = {}) {
        const geometry = parseGeometry(text);
        if (!geometry) {
            return null;
        }

        return this.L.geoJSON(geometry, {
            interactive: false,
            style: {
                color: faint ? BOUNDARY_LINE_FAINT : BOUNDARY_LINE,
                weight: faint ? 1.2 : 1.4,
                dashArray: '6 4',
                fill,
                fillColor: BOUNDARY_FILL,
                fillOpacity: 0.05,
            },
        }).addTo(this.map);
    }

    /* ── the design's chrome ─────────────────────────────────────────────── */

    zoomIn() {
        this.map?.zoomIn();
    }

    zoomOut() {
        this.map?.zoomOut();
    }

    /** The layers pill: the menu is closed by default and this is what opens it. */
    toggleLayers(event) {
        event?.stopPropagation();
        this.setLayerMenu(this.hasLayerMenuTarget && this.layerMenuTarget.hidden);
    }

    setLayerMenu(open) {
        if (!this.hasLayerMenuTarget) {
            return;
        }
        this.layerMenuTarget.hidden = !open;
        if (this.hasLayerBtnTarget) {
            this.layerBtnTarget.classList.toggle('on', open);
            this.layerBtnTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    /** Choose a base layer: it applies, it is marked, and the menu closes. */
    showBase(event) {
        const chosen = event.currentTarget.dataset.patrolBase;
        Object.entries(this.bases).forEach(([name, layer]) => {
            if (name === chosen) {
                layer.addTo(this.map);
            } else {
                this.map.removeLayer(layer);
            }
        });
        event.currentTarget.parentElement.querySelectorAll('b').forEach((b) => {
            b.classList.toggle('on', b === event.currentTarget);
        });
        this.setLayerMenu(false);
    }

    /*
     * Fullscreen takes the whole widget card, not just the tiles: the filter
     * chips and the legend are part of reading the map, and the design says the
     * legend floats bottom-right in full screen (patrol.css does that).
     */
    expand() {
        const frame = this.element.closest('.c') ?? this.element;
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else if (frame.requestFullscreen) {
            frame.requestFullscreen().catch(() => {});
        }
        // fullscreenchange re-sizes the map and switches the wheel bargain.
    }
}