# Screens

## Contents

- [The routes](#the-routes)
- [Observation taxonomy admin](#observation-taxonomy-admin)

## The routes

| Screen | Route |
|---|---|
| Widget dashboard | `patrol_dashboard` |
| Widget library (edit surface) | `patrol_widgets` |
| Patrol detail | `patrol_show` |
| Observation detail | `patrol_observation_show` |
| Export a recorded track as GPX | `patrol_export_gpx` |
| Observation taxonomy admin | `patrol_taxonomy` |

The dashboard and the widget library are compositions on the shell's widget
framework — see [what-it-stands-on.md](what-it-stands-on.md).

## Observation taxonomy admin

`patrol_taxonomy` (`/areas/{uuid}/modules/patrols/taxonomy`) is the area-scoped
admin for the observation vocabulary a ranger logs against: **kinds** on top (the
chips on the handset) and a level of **sub-categories** under each. It rhymes with
the incident taxonomy admin and shares a stylesheet vocabulary, but is **shallow**
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
web module and the field app) onto this area-scoped taxonomy is a **follow-up**,
not part of this admin.

The **copy-from-another-area** first-setup gesture is **deferred**: it needs to
enumerate areas and read their names, which requires an area-directory contract
that is not yet ruled. The empty-state template marks where it will attach; the
first-kind start is complete without it.
