# uhifadhi/patrol-module

Field patrol effort as first-class records: GPX track ingest, en-route
observations with photos, coverage mapping and a per-user widget dashboard.
A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## What it is

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
composer require uhifadhi/patrol-module
```

The bundle maps its own entities and ships its own assets (AssetMapper), so
there is no doctrine block and no asset wiring to write.

### Who the records point at

Five columns name a person — who led the patrol, who put it on hold, who
recorded the observation, who acted on the event, who signed the amendment —
and none of them names an account class. They are mapped to
`Uhifadhi\ModuleContracts\Entity\UserInterface`, and the installation resolves
that interface to whatever it calls its people. Install
`uhifadhi/team-module` and the answer arrives with it (0.3.2 and later states
the resolution from its own bundle); otherwise write one line naming your own
class, under the `orm:` key already in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    orm:
        resolve_target_entities:
            Uhifadhi\ModuleContracts\Entity\UserInterface: App\Entity\Person
```

Until something answers it, the bundle installs and the kernel boots, but
anything that walks the metadata — `doctrine:migrations:diff` included — stops
on the unresolved interface. The recipe's `config/packages/patrol.yaml` says the
same at length.

Deleting an account sets those five columns null and leaves the records
standing: removing somebody from the team does not un-walk the patrol they led.

### The storage bundles

Observation photos are stored by `uhifadhi/storage-module`, a hard dependency.
Register both bundles it needs:

```php
League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
Uhifadhi\Storage\UhifadhiStorageBundle::class => ['all' => true],
```

A host that forgets says so at compile time, not on the first upload. Where the
bytes go — and how a pre-storage-module deployment keeps the photographs it
already has — is in [docs/photo-storage.md](docs/photo-storage.md).

### Stimulus controllers

Its Stimulus controllers are enabled in the host's `assets/controllers.json`:

```json
"@uhifadhi/patrol-module": {
    "coverage-map": { "enabled": true, "fetch": "eager" },
    "track-plate":  { "enabled": true, "fetch": "eager" },
    "filters":      { "enabled": true, "fetch": "eager" },
    "rows":         { "enabled": true, "fetch": "eager" }
}
```

### Deployment vocabulary

Name the patrol types and observation categories this deployment uses in
`config/packages/patrol.yaml`; the full key list is in
[docs/configuration.md](docs/configuration.md).

```yaml
patrol:
    types:
        walk: { label: Walking round }
        boat: { label: Boat }
    observation_categories:
        maintenance: { label: Maintenance need }
```

### What the host must already provide

The dashboard rides the host's widget framework and the maps ride the
platform's map seam — this bundle ships neither. Both requirements are spelled
out in [docs/host-requirements.md](docs/host-requirements.md).

## Learn more

- [docs/host-requirements.md](docs/host-requirements.md) — the widget framework
  and the map seam the host must provide before these screens can draw.
- [docs/configuration.md](docs/configuration.md) — every `patrol.yaml` key.
- [docs/screens.md](docs/screens.md) — the screens this module adds, by route.
- [docs/ingest.md](docs/ingest.md) — one parsing service, two doors into it.
- [docs/photo-storage.md](docs/photo-storage.md) — where photographs live, the
  upgrade path from per-module storage, and patrol's entries on the Files hub.
- [docs/discarded-patrols.md](docs/discarded-patrols.md) — what a discard means,
  what it is counted in, the retention clock and how a review hold stops it.
- [docs/area-overview.md](docs/area-overview.md) — the five seams patrols fills
  on the host's area page, and the one thing it cannot tell that page.
- [docs/design-decisions.md](docs/design-decisions.md) — deliberate modeling
  choices (station as string, free-text team, how photos are stored, honest
  sources, live tracking as a v2 third door) recorded with their revisit
  triggers. **Read it before changing the model** — none of them is an
  oversight.
- [docs/development.md](docs/development.md) — `composer check` and the PostGIS
  test container.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi host this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
