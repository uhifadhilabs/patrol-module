# UPGRADE FROM 0.5 to 0.6

## Contents

- [Every gate names a (concern, verb) pair](#every-gate-names-a-concern-verb-pair)
- [The mapping, route by route](#the-mapping-route-by-route)
- [What an organization must re-tick, and why nothing does it for you](#what-an-organization-must-re-tick-and-why-nothing-does-it-for-you)
- [Reading the module is now a permission](#reading-the-module-is-now-a-permission)
- [Two powers were separated: holding and amending](#two-powers-were-separated-holding-and-amending)
- [A gate is asked WITH its area](#a-gate-is-asked-with-its-area)
- [What changed for code that calls this module](#what-changed-for-code-that-calls-this-module)
- [What this release requires](#what-this-release-requires)

## Every gate names a (concern, verb) pair

The core moved its permission model from flat values to **concerns × six
verbs**, declared by whoever enforces them and spelled `<concern>.<verb>`
(`uhifadhi/uhifadhi` 1.0, `UPGRADE-1.0.md`). This module follows it.

It used to declare **two flat permissions** through the deprecated
`ModulePermission` seam — `patrols.record` and `patrols.manage` — and enforce
them in controller code. It now declares **four concerns** through
`Uhifadhi\Contracts\Access\ConcernSourceInterface`
(`src/Access/PatrolConcerns.php`, tagged `uhifadhi.access.concerns`), and
every route carries `#[IsGranted('<pair>', subject: 'area')]`.

| Concern | Label | Verbs |
| --- | --- | --- |
| `patrols` | Patrols | read · record · manage · configure · export |
| `patrol-types` | Patrol types | read · configure |
| `patrol-stations` | Patrol stations | read · configure |
| `observation-kinds` | Observation kinds | configure |

**Nothing here is declared sensitive.** A patrol is field effort the
organization recorded about its own work, and the three word-lists are its own
vocabulary. The photographs an observation carries ARE a fact about a person or
a case, and they are withheld by the storage module that holds their bytes,
under its own concern.

**There is no `patrols.delete`.** Nothing in this module deletes a patrol: a
discarded one is swept by `patrol:purge-discarded` on the retention window,
which is a scheduled command and not somebody's click, and `patrols.manage` is
what holds one back from that sweep. A declared power nothing enforces is a box
an administrator can tick that changes nothing, and
`tests/Unit/Access/EveryRouteNamesItsPairTest` fails the build in both
directions.

**There is no `own` scope.** "My patrols" is a filter this module draws, not a
reach it enforces; scope is the ground a record lies in, never whose name is on
it.

## The mapping, route by route

Scan this: it is the whole of what changed about who can do what here.

| Route | Was | Is |
| --- | --- | --- |
| `patrol_dashboard`, `patrol_list`, `patrol_calendar`, `patrol_show`, `patrol_observation_show`, `patrol_kinds_overview` | *(ungated)* | `patrols.read` |
| `patrol_widgets` and every widget write (`…_save`, `…_reset`, `…_preset*`) | *(ungated)* | `patrols.read` |
| `patrol_import`, `patrol_log` | `patrols.record` (in code) | `patrols.record` |
| `patrol_hold` | `patrols.record` (in code) | **`patrols.manage`** |
| `patrol_observation_amend` | `patrols.record` (in code) | **`patrols.manage`** |
| `patrol_export`, `patrol_export_gpx` | *(ungated)* | `patrols.export` |
| `patrol_settings_save` | `patrols.manage` (in code) | `patrols.configure` |
| `patrol_types` | *(ungated)* | `patrol-types.read` |
| `patrol_types_save`, `patrol_type_act` | `patrols.manage` (in code) | `patrol-types.configure` |
| `patrol_stations` | *(ungated)* | `patrol-stations.read` |
| `patrol_stations_save`, `patrol_station_act` | `patrols.manage` (in code) | `patrol-stations.configure` |
| `patrol_kinds`, `patrol_taxonomy` | `patrols.manage` (in code) | `observation-kinds.configure` |
| every `patrol_kinds_kind_*` / `patrol_kinds_sub_*` write | `patrols.manage` (in code) | `observation-kinds.configure` |
| `/api/patrols…` (the field-sync endpoints) | `patrols.record`, asked with no area | `patrols.record`, asked **with the area** |

## What an organization must re-tick, and why nothing does it for you

**This release ships no grant migration, and that is deliberate.** The grants
table is `team_position`, which the core owns; a module writing somebody's
permissions into another package's table would be a module deciding who may do
what, which is the one thing the model says a module never does. So the
re-ticking is an administrator's, on the positions page, once.

The core's own `Version20260921002000` copied each position's old flat values
into its `grants` column, so a position that held `patrols.record` or
`patrols.manage` still holds a pair spelled that way. Read the table below
against what each position holds today.

| A position that held | Keeps | Must also be ticked, to keep working as it did |
| --- | --- | --- |
| `patrols.record` | Logging a patrol | `patrols.read` (to open any page at all), `patrols.export` if they exported |
| `patrols.manage` | Nothing it used to do — the configure screens moved | `patrols.read`, `patrols.configure`, `patrol-types.configure`, `patrol-stations.configure`, `observation-kinds.configure` |
| neither, but could see the module | — | `patrols.read`, `patrol-types.read`, `patrol-stations.read` |

**`patrols.manage` now means something new**, so tick it deliberately rather
than by habit: it is holding a discarded patrol back from the purge and
appending a signed correction to somebody else's observation. It is not the
configure page.

## Reading the module is now a permission

Every page this module ships used to be open to anybody the installation let
through its firewall; the only gate was the registry's, which answers 404 in an
area that has PARKED the module — a statement about the area, not about the
reader. Reading is `patrols.read` now, which is the model the core moved to:
nothing is held unless a position says so, a person with no position holds
nothing, and reading is not the exception.

**The practical consequence is that the module goes dark until somebody is
granted `patrols.read`.** Grant it in the same sitting as the upgrade.

## Two powers were separated: holding and amending

Holding a discarded patrol and amending an observation both used to ride on
`patrols.record`, on the argument that a deployment which decided who may write
patrol records had already answered the question. The six verbs answer it
better: `record` is making a fact from the field, `manage` is acting on facts
other people recorded — the core's own definition of the verb names holding and
amending explicitly.

So **a ranger who logs patrols can no longer hold one or amend an observation**
unless the position also holds `patrols.manage`. That is a real narrowing;
it is listed in the table above, and it is the one change here that can take
something away from somebody who had it yesterday.

## A gate is asked WITH its area

`#[IsGranted('patrols.read')]` with no `subject:` asks the voter with a null
subject, and a null subject means *no area in context* — which any placement
reaching any ground at all satisfies. Every route of this module now passes the
resolved area, and `tests/Functional/RouteByComposedPositionTest` drives every
page as somebody holding exactly the right pairs **at a different area** and
requires a 403.

The same applies off a route: `PatrolApiContext::requireRecorder()` **now takes
the area** and every sync processor resolves the ground before it asks, so a
handset whose ranger reaches one area cannot write a patrol into another.

## What changed for code that calls this module

| Was | Is |
| --- | --- |
| `PatrolRecordController::RECORD_PERMISSION` | `Grant::of(PatrolConcerns::PATROLS, Verb::Record)` |
| `PatrolTaxonomyController::MANAGE_PERMISSION` | one of the three `*.configure` pairs — see the mapping |
| `PatrolVocabularyController::MANAGE_PERMISSION`, `PatrolSettingsController::MANAGE_PERMISSION` | as above |
| `PatrolModuleProvider::permissions()` returning two `ModulePermission`s | returns `[]`; the module declares concerns instead |
| `PatrolScreenAccessService::mayRecord()` / `mayManage()` | `mayRecord(AreaInterface)`, `mayManage(AreaInterface)`, plus `mayConfigure()`, `mayConfigureTypes()`, `mayConfigureStations()`, `mayConfigureObservationKinds()`, `mayExport()` — each takes the area |
| `PatrolApiContext::requireRecorder()` | `requireRecorder(?AreaOfInterest $area)` |
| parameters `patrol.record_screens`, `patrol.manage_screens` | gone — the door service asks the core's `Door` |

`PatrolScreenAccessService` is constructed from
`Uhifadhi\Bundle\TeamBundle\Access\Door` alone. An installation that aliased or
decorated it must re-read its constructor.

## What this release requires

`uhifadhi/uhifadhi` 1.0 — the concern seam, `GrantVoter`, the `door()` helper
and `AccessConformanceTestCase` all arrive with it. There is no build of this
release that works against an 0.x core.

Nothing in this release changes the database. Run
`php bin/console cache:clear` and `php bin/console asset-map:compile` after the
update, as after any module change.
