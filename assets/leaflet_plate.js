import { Controller } from '@hotwired/stimulus';
import { satelliteLayer, streetLayer } from 'uhifadhi/basemaps';
import { drawBoundary } from 'uhifadhi/boundary';
import { mountMapChrome } from 'uhifadhi/map-chrome';

/*
 * The shared imagery plate every patrol map is built on — the design's viewer
 * frame (zoom column, layers menu, fullscreen) over real tiles.
 *
 * Leaflet, self-hosted, read off `window.L`: the host ships it as a classic
 * <script> (uhifadhi/map-module's public/leaflet/) and the patrol base template loads the
 * same file, so there is exactly ONE Leaflet on the page and no CDN. MapLibre
 * is deliberately not used — see the host's map_controller.js for why (raster
 * tiles + GeoJSON need no WebGL, and WebGL failed silently in constrained
 * environments).
 *
 * The two base layers come from the platform's one basemap module
 * (`uhifadhi/basemaps`, an importmap specifier): satellite is Google's official
 * Map Tiles API, falling back to keyless imagery where no key is configured.
 * The boundary comes from the host's `uhifadhi/boundary` the same way. The
 * module must not hold a second opinion about what satellite or a boundary
 * looks like — the same layer renders identically everywhere.
 *
 * Subclasses implement draw() — what this particular plate puts on the map —
 * and fitTo() is how they hand back the bounds to open on.
 */

/* The observation ring's amber lives in patrol.css (.patrol-ring) — it is drawn
   as a DOM marker, not a canvas shape, so the stylesheet owns its colour.
   The boundary's colours are NOT defined here at all: they come from the host's
   one boundary module (`uhifadhi/boundary`), so a boundary reads the same on an
   area page and on a patrol plate. */

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
    static targets = ['canvas'];
    static values = { payload: Object };

    connect() {
        const L = window.L;
        if (!L) {
            console.error('[patrol] window.L (Leaflet) is not loaded — the patrol base template must include leaflet.js');
            return;
        }
        this.L = L;

        // Controls, the live scale bar, attribution and the Ctrl/⌘-scroll bargain
        // all come from the host's platform chrome module, mounted after draw().
        this.map = L.map(this.canvasTarget, { zoomControl: false, attributionControl: true });
        this.canvasTarget.classList.add('map-chrome-host');

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
        // What is DRAWN must never be able to kill the map itself. A throw in
        // draw() used to abort connect(), so a bad payload cost the zoom pills,
        // the layer menu, fullscreen and the scroll bargain as well as the
        // overlay — the whole plate went dead. Now the tiles and the chrome
        // survive it and the console says what actually broke.
        try {
            this.draw();
        } catch (error) {
            console.error('[patrol] the map overlay failed to draw', error);
        }

        // After draw(), so the DIM pill has this plate's scrim to switch.
        // Fullscreen takes the whole widget card, not just the tiles: the filter
        // chips and the legend are part of reading the map.
        this.chrome = mountMapChrome(L, this.map, this.element, {
            bases: this.bases,
            scrim: this.scrimLayer,
            scrimOn: Boolean(this.scrimLayer) && this.map.hasLayer(this.scrimLayer),
            fullscreenTarget: this.element.closest('.c') ?? this.element,
            onResize: () => this.refit(),
        });
    }

    /*
     * Tear the map down on disconnect (Turbo navigation, cached previews).
     * Without this, revisiting the page runs L.map() on an already-initialised
     * container and Leaflet throws — the map then never builds.
     */
    disconnect() {
        this.chrome?.destroy();
        this.chrome = null;
        // stop() before remove(): a zoom/pan animation still in flight fires its
        // transitionend on a pane that remove() has already detached, and
        // Leaflet throws "Cannot read properties of undefined (reading
        // '_leaflet_pos')". Navigating away mid-zoom is an ordinary thing to do.
        this.map?.stop();
        this.map?.remove();
        this.map = null;
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
            // Remembered so the plate can re-frame the SAME subject when the
            // viewport changes size (entering or leaving full screen).
            this.lastFit = { bounds, padding, maxZoom };
            this.map.fitBounds(bounds, { padding: [padding, padding], maxZoom });
        }
    }

    /** Re-frame whatever this plate last opened on. */
    refit() {
        if (this.lastFit) {
            const { bounds, padding, maxZoom } = this.lastFit;
            this.map?.fitBounds(bounds, { padding: [padding, padding], maxZoom });
        }
    }

    /**
     * The area outline, in the platform's one boundary treatment: the
     * outside-the-area scrim, a white casing and the jade line. It must be
     * unmistakable where the area is, so there is no faint variant — every
     * patrol map draws it exactly as the host's area map draws it.
     *
     * `scrim: false` is for the close-zoom detail plates: there the whole frame
     * sits inside the area, so dimming "outside" dims nothing you can see and
     * only darkens the imagery.
     */
    drawBoundary(text, { scrim = true } = {}) {
        // One return value, and it is always a layer with getBounds() — see the
        // contract note in the host's map_boundary.js. The DIM handle rides on
        // it rather than being a second thing to destructure.
        const boundary = drawBoundary(this.L, this.map, parseGeometry(text), { scrim });
        this.scrimLayer = boundary?.scrimLayer ?? null;

        return boundary;
    }
}