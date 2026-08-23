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
        // The scrim starts OFF on a detail plate: it opens deep inside the area,
        // so dimming "outside" would darken imagery with no edge in frame to
        // explain it. The DIM pill is still there to switch it on.
        const boundary = this.drawBoundary(this.payload.boundary, { scrim: false });

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

        const rings = this.rings(single);
        let currentPoint = null;
        const ringPoints = [];
        for (const observation of rings) {
            const point = parseGeometry(observation.position);
            if (!point || point.type !== 'Point' || !Array.isArray(point.coordinates)) {
                continue;
            }
            const latLng = [point.coordinates[1], point.coordinates[0]];

            // The design's dashed amber ring with the observation number in it.
            // On the observation screen the sibling rings are drawn back, so the
            // one being READ is obvious among them.
            const faded = !observation.current;
            const ring = this.L.marker(latLng, {
                icon: this.L.divIcon({
                    className: faded ? 'patrol-ring patrol-ring-faded' : 'patrol-ring',
                    html: '<span></span>',
                    iconSize: faded ? [16, 16] : [22, 22],
                    iconAnchor: faded ? [8, 8] : [11, 11],
                }),
                keyboard: false,
                interactive: Boolean(observation.url) || !faded,
            }).addTo(this.map);
            // Written as text, never interpolated into the icon HTML: everything
            // in a payload came from a person or a database.
            ring.getElement()?.querySelector('span')?.appendChild(document.createTextNode(String(observation.n)));

            const label = observation.category
                ? `obs ${observation.n} · ${observation.category}`
                : `obs ${observation.n}`;
            ring.bindTooltip(faded ? `${label} — open` : label);
            if (observation.url) {
                ring.getElement()?.classList.add('patrol-ring-link');
                ring.getElement()?.setAttribute('title', label);
                ring.on('click', () => {
                    window.location.href = observation.url;
                });
            }

            ringPoints.push(latLng);
            if (observation.current) {
                currentPoint = latLng;
            }
        }

        this.openOnSubject({ single, trackLayer, ringPoints, currentPoint, boundary });
    }

    /**
     * Every ring this plate draws, normalised to one shape.
     *
     * The patrol screen sends `observations` — every positioned observation on
     * the patrol, all of them the subject, so all of them full strength.
     *
     * The observation screen sends `observation` (the one being read) and, when
     * the server offers it, `observations` — ALL of the patrol's observations,
     * each flagged `current`, so a reader sees this one among its siblings and
     * can click straight to any other. Both arrays use the same entry shape —
     * {n, position, category, url, current} — and `position` may be null: an
     * observation recorded without a fix still holds its number in the list, so
     * it is listed but not drawn. A server that sends only the single
     * observation still works.
     */
    rings(single) {
        const siblings = (this.payload.observations ?? []).map((entry) => ({
            n: entry.n,
            position: entry.position,
            category: entry.category,
            url: entry.url,
            // On the patrol screen nothing is "current" — every ring is subject.
            current: single ? entry.current === true : true,
        }));

        if (!single) {
            return siblings;
        }

        // Siblings when the server sends them; otherwise just the one being read.
        return siblings.length > 0
            ? siblings
            : [{ n: single.n, position: single.position, category: single.category, current: true }];
    }

    /**
     * The viewport, in order of what this plate is actually ABOUT:
     *   1. the observation, when the page is about one (a close frame around it,
     *      with whatever track runs through that frame for context);
     *   2. the track, plus the observations logged along it;
     *   3. the observations, when a patrol has positions but no route;
     *   4. the area boundary — the honest empty state for a hand-logged patrol.
     */
    openOnSubject({ single, trackLayer, ringPoints, currentPoint, boundary }) {
        // Always the CURRENT observation, never the first ring drawn: with the
        // siblings on the map the first one is usually somebody else's.
        const subject = currentPoint ?? ringPoints[0];
        if (single && subject) {
            const [lat, lng] = subject;
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