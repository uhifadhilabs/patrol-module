# Screens

## Contents

- [The routes](#the-routes)
- [The dashboard's filter](#the-dashboards-filter)
- [The module frame](#the-module-frame)
- [Observation kinds](#observation-kinds)
- [Settings](#settings)
- [Export](#export)

## The routes

| Screen | Route |
|---|---|
| Widget dashboard (Overview tab) | `patrol_dashboard` |
| Every patrol (Patrols tab) | `patrol_list` |
| Widget library (configure section) | `patrol_widgets` |
| Patrol detail | `patrol_show` |
| Observation detail | `patrol_observation_show` |
| Export a recorded track as GPX | `patrol_export_gpx` |
| Export the filtered log (`csv`) or its tracks (`gpx`) | `patrol_export` |
| Import GPX | `patrol_import` |
| Log patrol (manual) | `patrol_log` |
| Observation kinds (configure section) | `patrol_kinds` |
| Save the module's settings | `patrol_settings_save` |
| Add / rename / retire a patrol type | `patrol_settings_type_add`, `patrol_settings_type_act` |
| Add / rename / retire a station | `patrol_settings_station_add`, `patrol_settings_station_act` |

`patrol_taxonomy` (`…/patrols/taxonomy`) still answers, permanently redirecting to
`patrol_kinds` (`…/patrols/kinds`) — a saved link does not become a 404 over a
rename.

The dashboard and the widget library are compositions on the shell's widget
framework — see [what-it-stands-on.md](what-it-stands-on.md).

## The module frame

This module draws no navigation of its own. It declares two lists and the shell
draws both:

- **Two data tabs** — `Overview` and `Patrols` — through `ModuleTabsInterface`
  (`Uhifadhi\Patrol\Shell\PatrolModuleTabs`). A tab is a place where DATA lives;
  the patrol and observation detail screens keep the `Patrols` tab lit, because
  opening a record does not leave the place the record lives in.
- **Three configure sections** — `Widget library`, `Observation kinds`,
  `Settings` — through `ConfigurationSectionsInterface`
  (`Uhifadhi\Patrol\Shell\PatrolConfigurationSections`). The first two keep an
  address of their own, exactly as the settled design draws them; `Settings` is a
  body the shell renders inside its own configure page.

There is one configuration entry per surface — the shell's `Configure` action —
and no `Settings`, `Observation kinds` or `Widget library` button anywhere else,
and no "Back to dashboard": the first data tab, the lit `Configure` and the crumb
are the three ways back.

## The dashboard's filter

`patrol_dashboard` reads four query parameters, and every widget on the screen
reads the one answer they select:

| Parameter | Value | Absent means |
|---|---|---|
| `type` | one configured patrol type key | every type |
| `station` | one station's key | every station |
| `zone` | one zone's name, as the spatial join reports it | every zone |
| `month` | `YYYY-MM` | the month containing today |

Every chip and dropdown option in the filter bar is a link carrying exactly
these, so the map, the log and the charts can never be answering different
questions, and the screen somebody is looking at is the screen they can send
somebody else. The reasoning is [design-decisions.md
§7](design-decisions.md#7--the-filter-is-a-query-not-a-conversation).

Two things are deliberately NOT narrowed by it: the KPI strip's coverage figure
and the coverage layer drawn under the tracks. Both are the whole month's, and
both are the same measurement — the shape on the plate is the number in the
strip.

## Observation kinds

`patrol_kinds` (`/areas/{uuid}/modules/patrols/kinds`) is the area-scoped
section for the observation vocabulary a ranger logs against: **kinds** on top (the
chips on the handset) and a level of **sub-categories** under each. It rhymes with
the incident module's own kinds section and shares a stylesheet vocabulary, but is **shallow**
— sub-categories are labels only: no behaviour blocks, no colour per kind, no
term, no money. The moment an observation needs a structured question, a clock or
a fine, the right control is "File as incident".

- **Area-scoped (option B):** keyed to the current area; each area owns its own
  list, and the two never merge. Empty by default — the platform ships and
  suggests nothing; the empty screen shows an inert ghost sketch of the shape and
  a "write the first kind" path.
- **Deactivate, never delete:** a retired row is dimmed and reactivatable; the
  observations filed under it keep it. No delete control exists.
- **Wire-codes are per-area and frozen:** a label is unique within its scope (kind
  in the area, sub in its parent kind); a wire-code is unique within the area and
  never changes across renames, so a saved filter, an export column and an offline
  handset can hold it.
- **Gated by `patrols.manage` + CSRF:** managing the vocabulary is a separate
  authority from `patrols.record` — logging a patrol is not enough to name the
  words everybody else uses. The controller exists only where SecurityBundle can
  enforce it; its logic (`patrol.taxonomy_admin`) is unconditional.

**This is a parallel model.** `Observation::$category` still reads the flat
`patrol.observation_categories` deployment config; wiring observation capture (the
web module and the field app) onto this area-scoped list is a **follow-up**, not
part of this section.

## Settings

The last section of the configure page, at the bare address
`/areas/{uuid}/modules/patrols/configure`, saved by one POST to
`patrol_settings_save`. It reads and writes one row per area
(`patrol_settings`), and an area that has never saved runs on the installation's
own `patrol:` configuration — so an untouched default and a chosen number stay
distinguishable.

| Card | What it does |
|---|---|
| Patrol types (SET·01) | **Editable.** One row per type the area keeps: its label, its wire key, how many patrols are filed under it, and Rename / Retire — or Reactivate on a retired one, drawn dimmed with a `retired` chip. `+ New patrol type` adds one. |
| Observation categories (SET·02) | A pointer into the `Observation kinds` section, with this area's counts. |
| Stations (SET·03) | **Editable.** Exactly the same row, for the places a patrol sets off from. |
| Thresholds (SET·04) | **Editable.** The GPS-gap threshold and how long a discarded patrol stays recoverable, bounded as the design bounds them and clamped on the way in. |

The two word-lists write a row at a time, at the addresses the design names:

| Route | Method | Path |
|---|---|---|
| `patrol_settings_save` | POST | `…/configure/settings` |
| `patrol_settings_type_add` | POST | `…/configure/settings/types` |
| `patrol_settings_type_act` | POST | `…/configure/settings/types/{uuid}/{rename\|retire\|reactivate}` |
| `patrol_settings_station_add` | POST | `…/configure/settings/stations` |
| `patrol_settings_station_act` | POST | `…/configure/settings/stations/{uuid}/{rename\|retire\|reactivate}` |

All five ride on `patrols.manage` and a CSRF token, and all five exist only
where SecurityBundle can enforce the permission. **Nothing is ever deleted:**
retiring flips a flag, and the patrols filed under a retired word keep it.

**The installation's `patrol.types` is the SEED for a NEW area and nothing
else.** An area with no types yet is given the configured list the first time
its Settings section or its log form is opened; from then on the area's list is
its own, and a config change never reaches back into it.

The **copy-from-another-area** first-setup gesture is **deferred**: it needs to
enumerate areas and read their names, which requires an area-directory contract
that is not yet ruled. The empty-state template marks where it will attach; the
first-kind start is complete without it.

## Export

`patrol_export` at `/areas/{uuid}/modules/patrols/export.{_format}` —
`_format` is `csv` or `gpx`, and nothing else is an address.

**The file always carries the filter on screen.** It reads the same four query
parameters the dashboard and the log read (`type`, `station`, `zone`, `month`,
plus `q`) and narrows through the same predicate the log page is built from
(`PatrolListService::filtered()`), so the file and the table above it can never
be answering different questions. That is why there is no export screen: there
is nothing to choose.

| Format | What it holds |
|---|---|
| `csv` | The log's own columns in the log's own order: `ref, name, type, type_label, station, station_label, zone, lead, team, started_at, ended_at, distance_km, observations, source, status, note`. Both the wire KEY and the label are present, because they are different things — a key is what a saved filter holds, a label what a person reads. |
| `gpx` | One `<trk>` per patrol that actually RECORDED a route, written by the same `GpxWriter` the per-patrol export uses. A hand-logged patrol has no geometry and is absent: a sketch handed out as a `.gpx` would re-enter the world as a recording ([design-decisions.md §4](design-decisions.md#4--sources-are-honest-sketch--track)). A month with no recorded track is an empty document, never a 404. |

The design's `Export` page action sits beside `Configure` on the dashboard and
on the log, and links the CSV; the dashboard's `Export & reporting` widget
(PL·18) links both files. The monthly PDF report the same widget describes is
not built.
