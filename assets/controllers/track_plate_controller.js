import LeafletPlate, { endpoints, parseGeometry } from '../leaflet_plate.js';

/*
 * The detail plates (settled designs "detail" and "observation"), plus the two
 * recording previews:
 *
 *   patrol detail       — the track, ● start and ○ end, every positioned
 *                         observation as a numbered amber ring linking to its
 *                         own page.
 *   observation detail  — the same track drawn faded for context, with THIS
 *                         observation's ring in full strength.
 *   GPX import preview  — the parsed track, before anything is saved.
 *   manual log          — no track at all (a sketched route is not recorded
 *                         geometry): the plate shows the area, honestly empty.
 *
 * The payload always carries the area boundary, so every one of those states is
 * a real map rather than a grey box.
 *
 * THE PLATE OPENS ON ITS SUBJECT, NOT ON THE AREA. The boundary is context
 * drawn underneath; fitting to it turned a 6 km foot patrol into a speck at
 * 30 km scale. So the viewport comes from the track (detail, preview) or from
 * the observation (observation screen), and the boundary only decides the view
 * when there is no subject to show — a hand-logged patrol with no geometry.
 * Nothing is locked: no maxBounds, no minZoom, so zooming out to the whole area
 * is always one gesture away.
 */

/* How much ground an observation plate opens on: a ~3.3 km frame around the
   point, which lands on zoom ≈14 in a plate-sized viewport — close enough to
   read the immediate terrain, wide enough to see where the observation sits.
   Stated in degrees: over a frame this small the latitude/longitude difference
   does not matter, and the fit is what decides the zoom anyway. */
const OBSERVATION_SPAN_DEG = 0.015;

export default class extends LeafletPlate {
    draw() {
        const boundary = this.drawBoundary(this.payload.boundary, { faint: true });

        // One observation in the payload ⇒ the observation screen: the track is
        // context there, not the subject, so it is drawn back.
        const single = this.payload.observation ?? null;
        const color = this.payload.color ?? '#3ED9A8';

        let trackLayer = null;
        const geometry = parseGeometry(this.payload.track);
        if (geometry) {
            trackLayer = this.L.geoJSON(geometry, {
                style: {
                    color,
                    weight: single ? 2.0 : 2.6,
                    opacity: single ? 0.45 : 1,
                    lineCap: 'round',
                    fill: false,
                },
            }).addTo(this.map);

            if (!single) {
                const ends = endpoints(geometry);
                if (ends) {
                    this.L.circleMarker(ends.start, {
                        radius: 4, color, weight: 0, fillColor: color, fillOpacity: 1,
                    }).addTo(this.map).bindTooltip('start');
                    this.L.circleMarker(ends.end, {
                        radius: 4, color, weight: 2, fill: false,
                    }).addTo(this.map).bindTooltip('end');
                }
            }
        }

        const rings = single ? [single] : (this.payload.observations ?? []);
        const ringPoints = [];
        for (const observation of rings) {
            const point = parseGeometry(observation.position);
            if (!point || point.type !== 'Point' || !Array.isArray(point.coordinates)) {
                continue;
            }
            const latLng = [point.coordinates[1], point.coordinates[0]];

            // The design's dashed amber ring with the observation number in it.
            const ring = this.L.marker(latLng, {
                icon: this.L.divIcon({
                    className: 'patrol-ring',
                    html: `<span>${observation.n}</span>`,
                    iconSize: [22, 22],
                    iconAnchor: [11, 11],
                }),
                keyboard: false,
            }).addTo(this.map);

            const label = observation.category ? `obs ${observation.n} · ${observation.category}` : `obs ${observation.n}`;
            ring.bindTooltip(label);
            if (observation.url) {
                ring.on('click', () => {
                    window.location.href = observation.url;
                });
            }
            ringPoints.push(latLng);
        }

        this.openOnSubject({ single, trackLayer, ringPoints, boundary });
    }

    /**
     * The viewport, in order of what this plate is actually ABOUT:
     *   1. the observation, when the page is about one (a close frame around it,
     *      with whatever track runs through that frame for context);
     *   2. the track, plus the observations logged along it;
     *   3. the observations, when a patrol has positions but no route;
     *   4. the area boundary — the honest empty state for a hand-logged patrol.
     */
    openOnSubject({ single, trackLayer, ringPoints, boundary }) {
        if (single && ringPoints.length > 0) {
            const [lat, lng] = ringPoints[0];
            const span = OBSERVATION_SPAN_DEG;
            this.fitTo(
                this.L.latLngBounds([[lat - span, lng - span], [lat + span, lng + span]]),
                { padding: 40, maxZoom: 15 },
            );

            return;
        }

        if (trackLayer) {
            const bounds = trackLayer.getBounds();
            // Observations sit along the route, but a ring drawn just off the
            // last point must not be cropped out of its own patrol's plate.
            ringPoints.forEach((point) => bounds.extend(point));
            this.fitTo(bounds, { padding: 40, maxZoom: 17 });

            return;
        }

        if (ringPoints.length > 0) {
            this.fitTo(this.L.latLngBounds(ringPoints), { padding: 40, maxZoom: 16 });

            return;
        }

        // Nothing was recorded: the area itself is the whole of what is known.
        if (boundary) {
            this.fitTo(boundary.getBounds(), { padding: 26 });
        }
    }
}