# Live ranger tracking (planned)

A patrol today is a record read after the walk: the track, the observations, the
photos, all synced when signal returns. This feature adds the other tense — the
**present** one: seeing, right now, where rangers are and which stations are
staffed. It is **not built yet**; this doc records the shape it should take so the
decision is made before the code is.

## Contents

- [What it is](#what-it-is)
- [How the position gets there: a presence heartbeat](#how-the-position-gets-there-a-presence-heartbeat)
- [Occupancy is a geofence, never a point](#occupancy-is-a-geofence-never-a-point)
- [What it will need](#what-it-will-need)
- [Status](#status)

## What it is

A live map of rangers on patrol, and from it the question that matters
operationally: **which stations are occupied, and who is at each?** A supervisor
opens the map and sees presence, not history.

## How the position gets there: a presence heartbeat

The field app, while a patrol is running, pushes its current position to the
server on a **fixed time interval** — a heartbeat every N seconds — and the
server fans those out to subscribers over **Mercure** (the same-origin push the
installation already runs; follow the live-chat reference pattern).

The heartbeat is a **time interval, not a movement threshold**, and that choice is
load-bearing:

- Occupancy is a *still* question. A ranger standing at a station is exactly the
  case you most need to see — and a movement-triggered stream goes quiet precisely
  then. Only a timed ping tells you a ranger is **still there**, not merely that
  they arrived once.
- It **unifies the two platforms.** Android's location provider already emits on a
  time interval; iOS's CoreLocation goes deliberately silent while stationary (a
  distance filter, for battery). A server-driven heartbeat cadence makes both
  behave identically for presence, instead of iOS under-reporting a stationary
  ranger. (The separate "patrol map stuck on waiting-for-gps while stationary" bug
  in the field app is already fixed and is not this.)

The recorded patrol track and the live heartbeat are different concerns: the track
is the walked path, kept for the record; the heartbeat is presence, kept only long
enough to answer "where now."

## Occupancy is a geofence, never a point

A station is "occupied" when a ranger's position falls **inside the station's
zone** — a spatial containment test, not a coordinate match. This is deliberate:
consumer GPS jitters several metres in the open and much more under canopy, so no
point is trustworthy to the metre. A zone is far larger than the jitter, so
zone-membership stays reliable even when the fix is loose. Zones are the module's
existing spatial primitive; station occupancy rides on them.

## What it will need

- A server endpoint to receive heartbeats and a Mercure topic to publish presence.
- Subscriber authorization on that topic (a supervisor sees presence; a ranger does
  not subscribe to the fleet).
- Retention: presence is ephemeral — last-known position with a freshness age, not
  a second copy of the track.
- The field app: a timed presence push while on patrol, on both platforms.

## Status

**Deferred.** Recorded here as the intended design; not implemented. Build it as a
timed presence heartbeat → Mercure, with occupancy computed by zone containment.
