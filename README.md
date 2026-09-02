# uhifadhi/patrol-module

Field patrol effort as first-class records: GPX track ingest, en-route
observations with photos, coverage mapping and a per-user widget dashboard.
A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## Contents

- [What it does](#what-it-does)
- [Installation](#installation)
- [Configuration](#configuration)
- [Discarded patrols and retention](#discarded-patrols-and-retention)
- [Screens](#screens)
- [What patrols puts on the area overview](#what-patrols-puts-on-the-area-overview)
- [Ingest — one service, two doors](#ingest--one-service-two-doors)
- [Design decisions](#design-decisions)
- [Development](#development)
- [License](#license)

## What it does

- **Patrols** — a patrol is a typed, timed record (who led it, which station,
  when, how far) with an optional geometry track. Types are deployment
  vocabulary (`patrol.types`), never hardcoded: one deployment walks and
  drives, another patrols by boat.
- **GPX ingest** — upload a tracker's `.gpx` file; the bundle parses points,
  time span, distance and GPS gaps (flagged and stored, never smoothed), then
  a short form confirms type/station/lead.
- **Observations** — georeferenced field notes logged en route (category from
  `patrol.observation_categories`, note, photos), each with its own detail
  screen and an audit trail.
- **Coverage** — every track drawn over the area boundary; the dashboard is a
  per-user widget composition (KPIs, map, log, feed, charts, calendar).

**Dashboard surfaces ride the host's widget framework.** The module's dashboard
is composed on the HOST's `WidgetService` / `WidgetCatalog` preset component —
the same technique behind the host's departments, team, zones and incidents
surfaces — rather than a second widget implementation inside this bundle. This
module ships a catalogue (`PatrolWidgets`) and seven Twig partials; it ships no
widget mechanics at all. The host must therefore provide the widget framework
(`Uhifadhi\Service\{WidgetService,WidgetEndpoint}`, the widget entities and
`templates/widgets/_library.html.twig` with its `uhifadhi/widgets` script) and
symfony/ux-icons with the `lucide` set imported.

## Installation

```bash
composer require uhifadhi/patrol-module
```

The bundle maps its own entities and ships its own assets (AssetMapper) —
zero host configuration beyond the recipe.

Its Stimulus controllers are enabled in the host's `assets/controllers.json`:

```json
"@uhifadhi/patrol-module": {
    "coverage-map": { "enabled": true, "fetch": "eager" },
    "track-plate":  { "enabled": true, "fetch": "eager" },
    "filters":      { "enabled": true, "fetch": "eager" },
    "rows":         { "enabled": true, "fetch": "eager" }
}
```

### What the host provides for the maps

Patrols is a uhifadhi module, so its maps ride on the platform's map seam rather
than shipping a second copy of it (see `docs/design-decisions.md` §6):

- **Leaflet**, self-hosted by `uhifadhi/map-module` and linked through that
  bundle's `LEAFLET_CSS` / `LEAFLET_JS` constants (`window.L`). No CDN, never
  MapLibre.
- **Basemaps** under the importmap specifier `uhifadhi/basemaps`, exporting
  `satelliteLayer(L, map)` and `streetLayer(L)`.
- **Boundary treatment** under `uhifadhi/boundary`, exporting `drawBoundary(L,
  map, geojson, { scrim })` — the platform's one area outline: the
  outside-the-area scrim, a white casing and the jade line.

### Photographs need the storage module

Observation photos are stored by `uhifadhi/storage-module`, which is a hard
dependency: the sync endpoint writes through it and the observation screen reads
back through its authenticated route. Register both bundles it needs:

```php
League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
UhifadhiLabs\Storage\UhifadhiLabsStorageBundle::class => ['all' => true],
```

A host that forgets says so at compile time, not on the first upload.

Where the bytes go, how large a photograph may be and which types count as one
are configured **once for the whole deployment**, under `storage:` — never per
module. `patrol.photo_dir` and `patrol.photo_max_bytes` are gone for that reason.

A deployment upgrading from a pre-storage-module patrol keeps its photographs by
pointing the evidence storage at the directory they are already in:

```yaml
# config/packages/storage.yaml
storage:
    evidence:
        directory: '%kernel.project_dir%/var/patrol/photos'
```

The old `patrol-<uuid>/<uuid>.jpg` paths are valid evidence keys exactly as they
stand, and `PatrolEvidenceVoter` claims that legacy prefix alongside the new
`patrol/` one, so nothing is rewritten and nothing goes dark. Give those
photographs the preview they never had with:

```bash
bin/console patrol:photos:backfill-thumbs   # idempotent; --dry-run to look first
```

### Patrol's photographs on the Files hub

Where a host also mounts storage-module's cross-module hub at `/files`, patrol's
photographs appear on it: `PatrolFileSource` is tagged `storage.file_source` and
hands over one entry per `ObservationPhoto`, carrying the observation it belongs
to (`OBS-0214`, linked to its own page), the patrol's area, the handset's
`takenAt` and the sync time. Nothing is registered on the host's side.

It is the SECOND half of the same seam. `PatrolEvidenceVoter` answers *may you
read these bytes*; the source answers *what may be done to this file*, and both
read `PhotoEvidenceKey` so the two can never disagree about which keys are
patrol's.

**Patrol's answer is `Locked`, for everyone.** A photograph is the evidence its
observation rests on, and this module keeps no trail on which a removal could be
recorded — so it does not offer removal at all, rather than promising a recorded
removal nothing records. `FileRemovalInterface` is deliberately not implemented;
it arrives with an observation trail, not before it.

A patrol's GPX export is **not** on the hub: it is generated on demand from the
track column, so there is no stored object and no key to show.

## Configuration

```yaml
# config/packages/patrol.yaml
patrol:
    types:
        walk: { label: Walking round }
        boat: { label: Boat }
    observation_categories:
        maintenance: { label: Maintenance need }
    gap_threshold_minutes: 5
    # How long a discarded patrol is kept before patrol:purge-discarded deletes
    # it and its photographs. Measured from the discard; stopped while held.
    discard_retention_days: 90

when@dev:  { patrol: { dev_tools: true } }
when@test: { patrol: { dev_tools: true } }
```

## Discarded patrols and retention

A ranger can throw a patrol away in the field — a false start, a test run, a
device left recording in a vehicle. It still uploads, with a **required reason**,
and arrives here as `status: discarded`.

What the module then does with it:

- **It is kept and fully viewable** — track, observations and photographs. A
  record that vanished from every screen would be indistinguishable from one the
  sync lost.
- **It is counted in nothing.** Not the KPIs, not the coverage figures, not the
  department board, not the charts, not the station ranking, and its track is not
  drawn on any coverage map. A discard says the effort did not happen as
  recorded.
- **It reads quietly**: a subdued row with a `discarded` pill, the ranger's
  reason beside it and its removal date on the row.
- **It is deleted after `discard_retention_days`** (default 90), photographs and
  previews included:

```console
bin/console patrol:purge-discarded --dry-run   # name the sweep before trusting it
bin/console patrol:purge-discarded             # idempotent; run it from cron
```

Unless it is **held for review** — a web-side action on the patrol's detail page,
gated by `patrols.record`, which stops the retention clock indefinitely. Nothing
on the phone can raise a hold, clear one, or shorten the window. Releasing a hold
resumes the clock from the ORIGINAL discard: a hold pauses the deletion, it does
not grant a fresh lifetime.

Every action a ranger takes on a patrol — rename, patrol-type change, discard —
also arrives as an **append-only event** and is rendered on the detail page's
history card, newest first. The event is the story; the patrol row is the current
truth, and both are written together. See §9A of the field API contract.

## Screens

| Screen | Route |
|---|---|
| Widget dashboard | `patrol_dashboard` |
| Widget library (edit surface) | `patrol_widgets` |
| Patrol detail | `patrol_show` |
| Observation detail | `patrol_observation_show` |
| Export a recorded track as GPX | `patrol_export_gpx` |
| Import GPX | `patrol_import` |
| Log patrol (manual) | `patrol_log` |

## What patrols puts on the area overview

The host's `/areas/{uuid}` is **composed from module-contributed widgets**: the
host owns the surface, the grid, the preset framework and the area's identity,
and every operational number arrives through a seam. This module fills five of
them, all tagged explicitly in the bundle extension (a reusable bundle is not
autoconfigured):

| Seam | Tag | Class |
|---|---|---|
| Widgets + their templates | `uhifadhi.overview.widget_provider` | `Overview\PatrolOverviewContributor` |
| Tiles in the right-now strip | `uhifadhi.overview.now_tile` | `Overview\PatrolNowTiles` |
| Rows in "needs attention" | `uhifadhi.overview.attention` | `Overview\PatrolAttention` |
| Layers + legend on the plate | `uhifadhi.map.layer` | `Overview\PatrolMapLayers` |
| Moves in the area pulse | `uhifadhi.overview.pulse` | `Overview\PatrolPulse` |

Five widgets — `pl_now`, `pl_today`, `pl_gaps`, `pl_obsq` and `pl_column` (the
module's whole section as one widget, which **includes** the first three rather
than restating them). Two tiles, `PL·N1` and `PL·N2`. Three layers:
`patrols.live`, `patrols.today` and `patrols.buffer`, the last off by default.

**Today, not the month.** None of these numbers reconciles with the module's own
dashboard, and none should: the overview answers who is out and how today is
going. `PatrolOverviewService` measures that morning once and all five seams read
it, so the strip's count and the live card's rows cannot disagree.

### What the host provides for these plates

The `.ao-*` vocabulary (`.ao-by`, `.ao-live`, `.ao-col`, `.ao-colstack`,
`.ao-att`, `.ao-legend`, `.ao-move`, `.ao-dot`) belongs to the host's area
overview and is **not** shipped here. The host paints every contributor dot a
neutral fog and names no module in a rule; `public/patrol.css` paints this
module's own six selectors with the accent its tracks already wear.

### What this module cannot tell that page

**Whether an observation has been filed as an incident.** The incidents module
records the observation's uuid on its own side (`Incident::sourceRecordUuid`) and
nothing here mirrors it. So `pl_obsq` shows no unfiled count, marks no row as
unfiled, offers no "file as incident" action and raises no attention row about
one — it says so in its own copy instead. A flag on this side, or a read seam
back from incidents, is what would change that.

## Ingest — one service, two doors

`TrackIngestService` is the single parsing/validation path. The upload screen
feeds it today; a mobile tracker app POSTs to the same service via the API
endpoint later. Neither door re-implements parsing.

## Design decisions

Deliberate modeling choices (station as string, free-text team, how photos are
stored, honest sources, live tracking as a v2 third door) are recorded with
their revisit triggers in [docs/design-decisions.md](docs/design-decisions.md).
**Read it before changing the model** — none of them is an oversight.

## Development

```bash
composer check       # cs:check → phpstan (max) → phpunit
```

Integration tests need the PostGIS test container
(`PATROL_TEST_DATABASE_URL`, see `phpunit.dist.xml`).

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi host this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
