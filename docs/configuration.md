# Configuration

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

Types and observation categories are deployment vocabulary, never hardcoded: one
deployment walks and drives, another patrols by boat.

What `discard_retention_days` measures, and what stops its clock, is in
[discarded-patrols.md](discarded-patrols.md).
