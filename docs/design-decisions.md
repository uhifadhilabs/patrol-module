# Design decisions

Deliberate modeling choices, their reasoning, and the trigger that should
reopen each one. Read this before "fixing" any of them — none of these is an
oversight.

## Contents

- [1 · Station is a string, not an entity](#1--station-is-a-string-not-an-entity)
- [2 · Team is free text](#2--team-is-free-text)
- [3 · Observation photos are deferred — SETTLED](#3--observation-photos-are-deferred--settled-this-decision-has-fallen)
- [4 · Sources are honest: sketch ≠ track](#4--sources-are-honest-sketch--track)
- [5 · Live tracking is v2, and it is a third door](#5--live-tracking-is-v2-and-it-is-a-third-door)
- [6 · The maps are the atlas's, and the module writes no map JavaScript](#6--the-maps-are-the-atlass-and-the-module-writes-no-map-javascript)

## 1 · Station is a string, not an entity

`Patrol.station` is a free string ("North post"), not a foreign key.

**Why:** stations are a concept the platform will eventually own (a
stations module exists in its catalogue roadmap); this bundle must not invent
a competing `Station` entity the platform would later have to reconcile.
A string ships the screens now and loses nothing that matters yet.

**Revisit when:** a stations module exists. Migration path: add a
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
relation to the person contract.

**Why:** matches the settled designs (the roster is display information), and
many patrol members will not have accounts at all. One accountable relation
(the lead) is enough for v1.

**Revisit when:** per-member accountability is needed (who logged which
observation, per-ranger effort stats). Then: a `patrol_member` join table to
`User`, keeping the free-text field for non-account members.

## 3 · Observation photos are deferred — SETTLED, this decision has fallen

**Kept for the record.** It said photos needed a storage decision the bundle
could not make alone (local filesystem vs object storage, sizing, retention),
and that blocking the whole domain layer on that call was the wrong trade. It
was the first of these decisions expected to fall, and it did.

**What it became:** `uhifadhi/storage-module` owns the mechanism —
named Flysystem storages (a local directory or Hetzner object storage, one
config key apart), a detected-MIME allowlist, a size cap, ~400px previews and
ONE authenticated route by which anything comes back out. This module keeps what
only it can know: the `ObservationPhoto` row (evidence key, nullable thumb key,
detected type, byte size, takenAt), when to store, and — through
`PatrolEvidenceVoter` — who may read.

Two consequences worth stating plainly:

- **`mimeType` is the DETECTED type.** It used to be the type the *client
  claimed* while the detected one was merely validated. The column now holds
  what the bytes are.
- **`thumbKey` is nullable and must stay so.** No GD build decodes HEIC and an
  ImageMagick without libheif cannot either, so an iPhone photograph is
  routinely stored with no preview. Recording that honestly beats failing the
  upload — losing a ranger's photograph to a missing image library would be an
  absurd trade — and the page falls back to the original.

**Revisit when:** photographs need to be attached from the WEB as well as from
the handset. The detail screens are view-only by ruling, so today every
photograph arrives through the sync endpoint.

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

**Sequencing:** after the v1 screens are ported and installed.

## 6 · The maps are the atlas's, and the module writes no map JavaScript

A patrol map is stated in PHP and drawn by the atlas. `Service/PatrolMap` builds
an `AtlasMap` — the layers, the boundary, the legend rows — and the template
calls `render_map()`. The imagery, the control stack, the base-layer menu, the
scale bar, fullscreen and the legend's layout are the platform's; this module
defines no boundary colour, weight or opacity anywhere, and ships no map
controller, no Leaflet and no chrome markup.

```twig
{{ render_map(map, {'role': 'img', 'aria-label': 'Patrol tracks'}, patrolFilters) }}
```

The filter row goes in the plate's filter slot — one row above the map and
inside the plate — so the chips come along into fullscreen. Type, station and
zone drive the log and the lists from the chip row; the map's own type filtering
is the legend, which carries one switchable row per patrol type in the
deployment's colour for that type.

WHICH IMAGERY a satellite layer draws is the deployment's configuration
(`atlas.satellite.provider`: esri, google or its own source), read by the atlas
from the document. This module neither knows nor needs to.

The one thing each map decides for itself is whether the outside-the-area SCRIM
is drawn. The coverage map shows it, like the area map: it frames the whole
area, and dimming the outside is what makes the boundary read at a glance. The
detail and observation plates do NOT: they open deep inside the area at close
zoom, where "outside" is not in frame at all and the scrim would only darken
imagery for no gain. Both draw the identical casing and jade line.

**Why:** the platform rule is that the same layer renders identically wherever
it appears — a patrol map and an area map must not disagree about what
"satellite" means, and two Leaflets on one page are two module namespaces whose
objects each other refuses. This module is a uhifadhi module: `AreaBundle` and
`AtlasBundle` ship in the one core package it already requires, so drawing on
the atlas costs nothing extra. No CDN, and never MapLibre (raster tiles +
GeoJSON need no WebGL).
