# Configuration

Every key `config/packages/patrol.yaml` accepts, and what each one decides.

## Contents

- [The file](#the-file)
- [Deployment vocabulary](#deployment-vocabulary)
- [`types` is a seed, not a source](#types-is-a-seed-not-a-source)
- [Retention](#retention)

## The file

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
```

## Deployment vocabulary

Types and observation categories are deployment vocabulary, never hardcoded: one
deployment walks and drives, another patrols by boat.

## `types` is a seed, not a source

`patrol.types` is the list a **new area** starts from, and nothing else. An area
with no patrol types yet is given a copy of it — as its own records — the first
time somebody opens that area's Settings section or its log form. From then on
the area owns its list: renaming, retiring and adding a type happens on SET·01
of the module's configure page, per area, and changing this file never reaches
back into an area somebody has curated.

Stations have no configuration key at all. They are written on SET·03, or
created retired by the handset sync when a phone reports a word the area has not
heard of — see [design-decisions.md
§1](design-decisions.md#1--a-station-is-a-record-the-area-keeps).

## Retention

What `discard_retention_days` measures, and what stops its clock, is in
[discarded-patrols.md](discarded-patrols.md).
