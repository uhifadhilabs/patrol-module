# Ingest — one service, two doors

## Contents

- [One parsing path](#one-parsing-path)
- [What the upload does](#what-the-upload-does)
- [The third door](#the-third-door)
- [What the handset is allowed to say](#what-the-handset-is-allowed-to-say)

## One parsing path

`TrackIngestService` is the single parsing/validation path. The entry flow's
step 1 feeds it today; a mobile tracker app POSTs to the same service via the API
endpoint later. Neither door re-implements parsing.

## What the upload does

A track is dropped on **the platform's upload component** — step 1 of the one
entry flow, described in [screens.md](screens.md#the-one-entry-flow). This module
writes no dropzone, no file input and no upload JavaScript; it implements
`UploadTargetInterface` as `Upload\PatrolTrackTarget` (kind `patrol-track`) and
answers the four things the storage cannot know.

The bytes land, and the target parses them there and then: points, time span,
distance and GPS gaps (flagged and stored, never smoothed). What comes back is
the chip the design draws on a finished row — `parsed · 14.2 km · 2 h 05 ·
3 gaps` — in the module's own words. `gap_threshold_minutes` in
[configuration.md](configuration.md) is what counts as a gap.

The file itself is **kept**, not discarded after parsing: a patrol's GPX is the
source of a field record, and the receipt says `stored` rather than `parsed` for
that reason. On save it is re-homed under the patrol's own prefix and named by
`patrol.track_file_key`.

**The deployment's allowlist has to accept it.** The storage validates every file
against `storage.evidence.allowed_mime_types`, and a target may narrow that list
but never widen past it. A GPX is detected from its BYTES, which on most
platforms reads as generic XML, so an installation that wants step 1 to work adds
the three spellings:

```yaml
# config/packages/storage.yaml
storage:
    evidence:
        allowed_mime_types:
            ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp',
             'application/gpx+xml', 'application/xml', 'text/xml']
```

The rest of the form confirms type/station/lead — chosen from the AREA's own
patrol types and stations, as chip rows.

## The third door

Live tracking is a v2 third door, recorded with its revisit trigger in
[design-decisions.md](design-decisions.md) §5.

## What the handset is allowed to say

`GET /api/patrols/vocabulary?areaId={uuid}` — the sync's one READ, beside the
seven writes. It answers with the words one area lets a field client use, so a
new station is on the phones at the next sync rather than at the next store
release:

```json
{
  "areaId": "…",
  "generatedAt": "2026-09-11T09:00:00+00:00",
  "patrolTypes": [
    { "key": "walk", "label": "Walking round", "active": true, "position": 0, "updatedAt": "…" }
  ],
  "stations": [
    { "key": "river-post", "label": "River Post", "active": true, "position": 0, "updatedAt": "…", "point": null }
  ],
  "observationKinds": [
    { "key": "…", "label": "…", "active": true, "position": 0, "updatedAt": "…", "subcategories": [ … ] }
  ]
}
```

- **The `key` is the wire value** the client sends back on every write, and a
  rename never changes it. Only `label` changes.
- **`active: false` means "stop offering it, keep what you already hold".** A
  retired word is SENT, not withheld: a handset holding a patrol filed under one
  still has to be able to print it.
- **`updatedAt` makes a delta possible.** Pass the newest one held back as
  `&since=<ISO-8601>` and only what has changed since comes back.
- It requires `patrols.record`, like every other endpoint here, and refuses an
  unknown `areaId` with the contract's `unknown_area`.

**A word the handset sends that this area has never heard of is never a
refusal.** The contract names no error code for an unknown type or an unknown
station, and refusing would throw away a real patrol because a settings screen
and an app build disagreed about a word. The sync creates the word as a
**retired** record instead: the patrol is kept, and the disagreement shows up
dimmed on the module's Settings section for somebody to rename into an existing
post or reactivate.
