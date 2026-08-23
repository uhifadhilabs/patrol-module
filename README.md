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
| Import GPX | `patrol_import` |
| Log patrol (manual) | `patrol_log` |

## Ingest — one service, two doors

`TrackIngestService` is the single parsing/validation path. The upload screen
feeds it today; a mobile tracker app POSTs to the same service via the API
endpoint later. Neither door re-implements parsing.

## Design decisions

Deliberate modeling choices (station as string, free-text team, deferred
photos, honest sources, live tracking as a v2 third door) are recorded with
their revisit triggers in [docs/design-decisions.md](docs/design-decisions.md).
**Read it before changing the model** — none of them is an oversight.

## Development

```bash
composer check       # cs:check → phpstan (max) → phpunit
```

Integration tests need the PostGIS test container
(`PATROL_TEST_DATABASE_URL`, see `phpunit.dist.xml`).