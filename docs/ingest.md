# Ingest — one service, two doors

## Contents

- [One parsing path](#one-parsing-path)
- [What the upload does](#what-the-upload-does)
- [The third door](#the-third-door)
- [What the handset is allowed to say](#what-the-handset-is-allowed-to-say)

## One parsing path

`TrackIngestService` is the single parsing/validation path. The upload screen
feeds it today; a mobile tracker app POSTs to the same service via the API
endpoint later. Neither door re-implements parsing.

## What the upload does

Upload a tracker's `.gpx` file and the bundle parses points, time span, distance
and GPS gaps (flagged and stored, never smoothed), then a short form confirms
type/station/lead — chosen from the AREA's own patrol types and stations, as
chip rows. `gap_threshold_minutes` in
[configuration.md](configuration.md) is what counts as a gap.

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
