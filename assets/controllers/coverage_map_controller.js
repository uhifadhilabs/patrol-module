import LeafletPlate, { endpoints, parseGeometry } from '../leaflet_plate.js';

/*
 * PL·05 — the coverage map: the area boundary plus every patrol that recorded a
 * track, coloured by patrol type (the same colours the chips, charts and legend
 * use — PatrolDashboardService::typeColors, so PHP and JS cannot disagree).
 *
 * A hand-logged patrol carries no geometry and is not in the payload at all
 * (PatrolDashboardService::coveragePayload), so it is never drawn as a guess.
 * An area with no recorded tracks still gets a real map: just the boundary.
 *
 * Stations ride on top as the design's labelled markers. They are free strings
 * with no coordinates of their own, so each one is placed at the first recorded
 * point of a patrol that set out from it (see coveragePayload) — the best
 * evidence there is, never an invented position.
 *
 * One filter drives the map AND the log (the design's caption). The chips live
 * in a different widget, so they travel as document events:
 *   patrol:filter    {type, station}  — 'all' or a patrol type key / station name
 *   patrol:highlight {uuid}           — a log row under the cursor, null on leave
 */
export default class extends LeafletPlate {
    connect() {
        this.tracks = new Map();
        this.stations = new Map();
        this.filter = { type: 'all', station: 'all' };

        this.onFilter = (event) => this.applyFilter(event.detail ?? {});
        this.onHighlight = (event) => this.highlight(event.detail?.uuid ?? null);
        document.addEventListener('patrol:filter', this.onFilter);
        document.addEventListener('patrol:highlight', this.onHighlight);

        super.connect();
    }

    disconnect() {
        document.removeEventListener('patrol:filter', this.onFilter);
        document.removeEventListener('patrol:highlight', this.onHighlight);
        super.disconnect();
    }

    draw() {
        const bounds = this.L.latLngBounds([]);

        const boundary = this.drawBoundary(this.payload.boundary, { fill: true });
        if (boundary) {
            bounds.extend(boundary.getBounds());
        }

        for (const patrol of this.payload.patrols ?? []) {
            const geometry = parseGeometry(patrol.track);
            if (!geometry) {
                continue;
            }
            // The design's track: the type colour, 2.1 wide, round caps, .9 opacity.
            const layer = this.L.geoJSON(geometry, {
                style: { color: patrol.color, weight: 2.1, opacity: 0.9, lineCap: 'round', fill: false },
            }).addTo(this.map);
            layer.bindTooltip(`${patrol.ref} · ${patrol.type}`, { sticky: true });

            // Start ● and end ○, as the design draws them.
            const ends = endpoints(geometry);
            const markers = [];
            if (ends) {
                markers.push(
                    this.L.circleMarker(ends.start, {
                        radius: 3.2, color: patrol.color, weight: 0, fillColor: patrol.color, fillOpacity: 1,
                    }).addTo(this.map),
                    this.L.circleMarker(ends.end, {
                        radius: 3.6, color: patrol.color, weight: 2, fill: false,
                    }).addTo(this.map),
                );
            }

            this.tracks.set(patrol.uuid, { patrol, layer, markers });
            bounds.extend(layer.getBounds());
        }

        // The design's station markers: a small light square with the station's
        // name beside it (patrol.css draws both).
        for (const station of this.payload.stations ?? []) {
            const marker = this.L.marker([station.lat, station.lon], {
                icon: this.L.divIcon({
                    className: 'patrol-station',
                    html: `<i></i><b></b>`,
                    iconSize: [8, 8],
                    iconAnchor: [4, 4],
                }),
                keyboard: false,
                interactive: false,
            }).addTo(this.map);
            // Written as text, never interpolated into the HTML above: a station
            // is free text a person typed.
            marker.getElement()?.querySelector('b')?.appendChild(document.createTextNode(station.name));
            this.stations.set(station.name, marker);
            bounds.extend([station.lat, station.lon]);
        }

        this.fitTo(bounds);
    }

    /** Show only the tracks the chips select — 'all'/'all' shows every one. */
    applyFilter({ type = 'all', station = 'all' }) {
        this.filter = { type, station };

        const standing = new Set();
        for (const entry of this.tracks.values()) {
            const shown = this.matches(entry.patrol);
            this.setShown(entry, shown);
            if (shown && entry.patrol.station) {
                standing.add(entry.patrol.station);
            }
        }

        // A station with nothing left on show is not part of this view.
        for (const [name, marker] of this.stations.entries()) {
            const shown = standing.has(name);
            if (shown) {
                marker.addTo(this.map);
            } else if (this.map.hasLayer(marker)) {
                this.map.removeLayer(marker);
            }
        }
    }

    matches(patrol) {
        const { type, station } = this.filter;

        return (type === 'all' || patrol.type === type)
            && (station === 'all' || patrol.station === station);
    }

    /** Hovering a log row spotlights its track; everything else falls back. */
    highlight(uuid) {
        for (const [key, entry] of this.tracks.entries()) {
            if (!this.matches(entry.patrol)) {
                continue;
            }
            const dimmed = uuid !== null && key !== uuid;
            entry.layer.setStyle({
                weight: key === uuid ? 3.4 : 2.1,
                opacity: dimmed ? 0.25 : 0.9,
            });
        }
    }

    setShown(entry, shown) {
        const layers = [entry.layer, ...entry.markers];
        for (const layer of layers) {
            if (shown) {
                layer.addTo(this.map);
            } else if (this.map.hasLayer(layer)) {
                this.map.removeLayer(layer);
            }
        }
    }
}
