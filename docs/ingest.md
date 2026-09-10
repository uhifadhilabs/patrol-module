# Ingest — one service, two doors

## Contents

- [One parsing path](#one-parsing-path)
- [What the upload does](#what-the-upload-does)
- [The third door](#the-third-door)

## One parsing path

`TrackIngestService` is the single parsing/validation path. The upload screen
feeds it today; a mobile tracker app POSTs to the same service via the API
endpoint later. Neither door re-implements parsing.

## What the upload does

Upload a tracker's `.gpx` file and the bundle parses points, time span, distance
and GPS gaps (flagged and stored, never smoothed), then a short form confirms
type/station/lead. `gap_threshold_minutes` in
[configuration.md](configuration.md) is what counts as a gap.

## The third door

Live tracking is a v2 third door, recorded with its revisit trigger in
[design-decisions.md](design-decisions.md) §5.
