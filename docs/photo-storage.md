# Photographs and the storage module

## Contents

- [Photographs need the storage module](#photographs-need-the-storage-module)
- [The two upload targets](#the-two-upload-targets)
- [Patrol's photographs on the Files hub](#patrols-photographs-on-the-files-hub)

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

Those `patrol-<uuid>/<uuid>.jpg` paths are valid evidence keys exactly as they
stand, and `PatrolEvidenceVoter` claims that legacy prefix alongside the new
`patrol/` one, so nothing is rewritten and nothing goes dark. Give those
photographs the preview they never had with:

```bash
bin/console patrol:photos:backfill-thumbs   # idempotent; --dry-run to look first
```

That command exists **only where `uhifadhi/devkit-module` is installed**, which
is `require-dev`. It is a migration aid run once on the release that needs it,
not an operation a production console keeps standing by, so this module ships it
as an inert `Devkit\CommandProviderInterface` descriptor and devkit materialises
it — see `src/Devkit/PatrolCommandProvider.php`.

## The two upload targets

Every file that enters this module through a browser goes through the platform's
one upload component, and this module's whole side of it is two implementations
of `Uhifadhi\Storage\Upload\UploadTargetInterface` in `src/Upload/`. No
controller, no route, no JavaScript, no stylesheet.

| Class | Kind | Takes |
|---|---|---|
| `PatrolTrackTarget` | `patrol-track` | one GPX, no larger than the deployment accepts — the entry flow's step 1 |
| `PatrolObservationPhotoTarget` | `observation` | the deployment's own evidence allowlist, unnarrowed — one observation's evidence grid |

Both file against a `PatrolDraft`, because a file arrives before the patrol it
belongs to exists; the shared half of the contract — which record, who may, who
may take it back off — is `AbstractPatrolDraftTarget`, written once so two
targets cannot drift apart on "who may". The permission is `patrols.record` **on
the draft's area**, and the draft's own owner.

**The kinds are separate from `patrol/` on purpose.** The prefix is a key's first
segment and the thing a removal, a voter and the Files hub route on. A photograph
on a SAVED patrol lives under `patrol/{uuid}/`, where the observation it belongs
to can be found from the key; one on a draft cannot, because there is no
observation yet. Two different facts, two different prefixes — and the save is
precisely the move from one to the other. `PatrolEvidenceVoter` answers for all
three: a saved patrol's evidence reads on the observation page's own rule, a
draft's reads for its owner alone.

## Patrol's photographs on the Files hub

Where an installation also mounts storage-module's cross-module hub at `/files`, patrol's
photographs appear on it: `PatrolFileSource` is tagged `storage.file_source` and
hands over one entry per `ObservationPhoto`, carrying the observation it belongs
to (`OBS-0214`, linked to its own page), the patrol's area, the handset's
`takenAt` and the sync time. Nothing is registered on the hub's side.

It is the SECOND half of the same contract. `PatrolEvidenceVoter` answers *may you
read these bytes*; the source answers *what may be done to this file*, and both
read `PhotoEvidenceKey` so the two can never disagree about which keys are
patrol's.

**Patrol's answer is `Locked`, for everyone.** A photograph is the evidence its
observation rests on, and this module keeps no trail on which a removal could be
recorded — so it does not offer removal at all, rather than promising a recorded
removal nothing records. `FileRemovalInterface` is deliberately not implemented;
it arrives with an observation trail, not before it.

A patrol's GPX **export** is not on the hub: it is generated on demand from the
track column, so there is no stored object and no key to show. The GPX a patrol
was RECORDED from is a different file — it is kept, under the patrol's own
prefix, and named by `patrol.track_file_key`; it is not listed either, because
the hub lists photographs and a source track is not one.
