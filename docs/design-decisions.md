# Design decisions

Deliberate modeling choices, their reasoning, and the trigger that should
reopen each one. Read this before "fixing" any of them — none of these is an
oversight.

## Contents

- [1 · Station is a string, not an entity](#1--station-is-a-string-not-an-entity)
- [2 · Team is free text](#2--team-is-free-text)
- [3 · Observation photos are deferred](#3--observation-photos-are-deferred)
- [4 · Sources are honest: sketch ≠ track](#4--sources-are-honest-sketch--track)
- [5 · Live tracking is v2, and it is a third door](#5--live-tracking-is-v2-and-it-is-a-third-door)
- [6 · The maps run on the host's Leaflet and the host's basemaps](#6--the-maps-run-on-the-hosts-leaflet-and-the-hosts-basemaps)

## 1 · Station is a string, not an entity

`Patrol.station` is a free string ("North post"), not a foreign key.

**Why:** stations are a concept the host platform will eventually own (a
stations module exists in its catalogue roadmap); this bundle must not invent
a competing `Station` entity that the host would later have to reconcile.
A string ships the screens now and loses nothing that matters yet.

**Revisit when:** the host's stations module exists. Migration path: add a
nullable FK, backfill by name-matching, keep the string as a fallback label
until every deployment has migrated.

**Consequence on the map:** a station therefore has no coordinates of its own,
but the settled coverage design labels each station on the map. The marker is
placed at the FIRST recorded point of a patrol that set out from that station
(`PatrolDashboardService::coveragePayload`) — the best evidence the bundle
holds, and never an invented position: a station whose patrols were all
hand-logged (no track) gets no marker at all. When the stations module lands
with real geometry, that derivation goes away.

## 2 · Team is free text

`Patrol.team` is a comma-separated roster string; only `lead` is a real
relation to the host `User`.

**Why:** matches the settled designs (the roster is display information), and
many patrol members will not have accounts at all. One accountable relation
(the lead) is enough for v1.

**Revisit when:** per-member accountability is needed (who logged which
observation, per-ranger effort stats). Then: a `patrol_member` join table to
`User`, keeping the free-text field for non-account members.

## 3 · Observation photos are deferred

`Observation` has no photo entity yet, although the settled designs show
photos on the observation detail screen.

**Why:** photos need a storage decision the bundle cannot make alone — local
filesystem vs host-provided object storage, sizing, retention. Blocking the
whole domain layer on that call was the wrong trade.

**Revisit when:** the observation screens are wired for upload (this is the
FIRST of these decisions expected to fall). Shape: an `ObservationPhoto`
entity (filename, stored path/key, takenAt), storage behind a small interface
the host may replace.

## 4 · Sources are honest: sketch ≠ track

`PatrolSourceEnum` (gpx | manual | api) is load-bearing, not bookkeeping: a
hand-sketched manual route must never render or aggregate as if it were a
recorded track, and GPS gaps are flagged and stored, never smoothed.
Consumers branch on the source; do not "simplify" this away.

## 5 · Live tracking is v2, and it is a third door

A patrol in progress is a stream of positions; a GPX file is a finished
artifact. Live tracking therefore does NOT extend `TrackIngestService` — it
adds a `PositionIngestService`: the tracker app POSTs batched positions
(store-and-forward, deduped by device + timestamp), the server publishes to a
Mercure topic per area, the coverage map subscribes, and closing the patrol
assembles the streamed positions into the same stored LineString with the
same honesty metadata as an import. Wildlife-collar feeds (vendor APIs) are
consumers of the same pipeline shape, on sibling topics.

**Sequencing:** after the v1 screens are ported and installed in the host.

## 6 · The maps run on the host's Leaflet and the host's basemaps

The patrol base template loads the HOST's self-hosted Leaflet
(`asset('leaflet/leaflet.css'|'leaflet/leaflet.js')`), and the map controllers
import the host's map modules — `uhifadhi/basemaps` for the satellite and street
layers, `uhifadhi/boundary` for how an area outline is drawn — rather than
holding tile sources or boundary styling of their own. The bundle defines no
boundary colour, weight or opacity anywhere: there is one definition, in the
host, and nothing to keep in sync.

The one thing each map decides for itself is whether the outside-the-area SCRIM
is drawn. The coverage map (PL·05/PL·08) shows it, like the host's area map: it
frames the whole area, and dimming the outside is what makes the boundary read
at a glance. The detail and observation plates do NOT: they open deep inside the
area at close zoom, where "outside" is not in frame at all and the scrim would
only darken imagery for no gain. Both still draw the identical casing and jade
line.

**Why:** the platform rule is that the same layer renders identically wherever
it appears — a patrol map and an area map must not disagree about what
"satellite" means, and two copies of Leaflet on one page is a bug waiting to
happen. This bundle is a uhifadhi module: it already binds to the host's
`AreaOfInterest`, so depending on the host's map seam costs nothing extra. No
CDN, and never MapLibre (raster tiles + GeoJSON need no WebGL).

**Revisit when:** the bundle is ever wanted in a non-uhifadhi host. Then the two
asset paths and the two module specifiers become configuration, with the current
values as defaults.
