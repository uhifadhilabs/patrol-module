# Photographs and the storage module

## Photographs need the storage module

Observation photos are stored by `uhifadhi/storage-module`, which is a hard
dependency: the sync endpoint writes through it and the observation screen reads
back through its authenticated route. Register both bundles it needs:

```php
League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
Uhifadhi\Storage\UhifadhiStorageBundle::class => ['all' => true],
```

An installation that forgets says so at compile time, not on the first upload.

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

## Patrol's photographs on the Files hub

Where an installation also mounts storage-module's cross-module hub at `/files`, patrol's
photographs appear on it: `PatrolFileSource` is tagged `storage.file_source` and
hands over one entry per `ObservationPhoto`, carrying the observation it belongs
to (`OBS-0214`, linked to its own page), the patrol's area, the handset's
`takenAt` and the sync time. Nothing is registered on the hub's side.

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
