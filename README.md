# uhifadhilabs/patrol-module

Field patrol effort as first-class records: GPX track ingest, en-route
observations with photos, coverage mapping and a per-user widget dashboard.
A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## Contents

- [What it does](#what-it-does)
- [Installation](#installation)
- [Configuration](#configuration)
- [Screens](#screens)
- [Ingest — one service, two doors](#ingest--one-service-two-doors)
- [Design decisions](#design-decisions)
- [Development](#development)

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

## Installation

```bash
composer require uhifadhilabs/patrol-module
```

The bundle maps its own entities and ships its own assets (AssetMapper) —
zero host configuration beyond the recipe.

Its Stimulus controllers are enabled in the host's `assets/controllers.json`:

```json
"@uhifadhilabs/patrol-module": {
    "coverage-map": { "enabled": true, "fetch": "eager" },
    "track-plate":  { "enabled": true, "fetch": "eager" },
    "filters":      { "enabled": true, "fetch": "eager" },
    "rows":         { "enabled": true, "fetch": "eager" }
}
```

### What the host provides for the maps

Patrols is a uhifadhi module, so its maps ride on the platform's map seam rather
than shipping a second copy of it (see `docs/design-decisions.md` §6):

- **Leaflet**, self-hosted, at the asset paths `leaflet/leaflet.css` and
  `leaflet/leaflet.js` (`window.L`). No CDN, never MapLibre.
- **Basemaps** under the importmap specifier `uhifadhi/basemaps`, exporting
  `satelliteLayer(L, map)` and `streetLayer(L)`.
- **Boundary treatment** under `uhifadhi/boundary`, exporting `drawBoundary(L,
  map, geojson, { scrim })` — the platform's one area outline: the
  outside-the-area scrim, a white casing and the jade line.

### Photographs need the storage module

Observation photos are stored by `uhifadhilabs/storage-module`, which is a hard
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

when@dev:  { patrol: { dev_tools: true } }
when@test: { patrol: { dev_tools: true } }
```

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