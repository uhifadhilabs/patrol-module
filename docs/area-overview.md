# What patrols puts on the area overview

The area module's `/areas/{uuid}` is **composed from module-contributed widgets**: the
area module owns the surface, the grid, the preset framework and the area's
identity,
and every operational number arrives through a seam. This module fills five of
them, all tagged explicitly in the bundle extension (a reusable bundle is not
autoconfigured):

| Seam | Tag | Class |
|---|---|---|
| Widgets + their templates | `uhifadhi.overview.widget_provider` | `Overview\PatrolOverviewContributor` |
| Tiles in the right-now strip | `uhifadhi.overview.now_tile` | `Overview\PatrolNowTiles` |
| Rows in "needs attention" | `uhifadhi.overview.attention` | `Overview\PatrolAttention` |
| Layers + legend on the plate | `uhifadhi.map.layer` | `Overview\PatrolMapLayers` |
| Moves in the area pulse | `uhifadhi.overview.pulse` | `Overview\PatrolPulse` |

Five widgets — `pl_now`, `pl_today`, `pl_gaps`, `pl_obsq` and `pl_column` (the
module's whole section as one widget, which **includes** the first three rather
than restating them). Two tiles, `PL·N1` and `PL·N2`. Three layers:
`patrols.live`, `patrols.today` and `patrols.buffer`, the last off by default.

**Today, not the month.** None of these numbers reconciles with the module's own
dashboard, and none should: the overview answers who is out and how today is
going. `PatrolOverviewService` measures that morning once and all five seams read
it, so the strip's count and the live card's rows cannot disagree.

## What the area module provides for these plates

The `.ao-*` vocabulary (`.ao-by`, `.ao-live`, `.ao-col`, `.ao-colstack`,
`.ao-att`, `.ao-legend`, `.ao-move`, `.ao-dot`) belongs to `uhifadhi/area-module`'s
overview and is **not** shipped here. That module paints every contributor dot a
neutral fog and names no module in a rule; `public/patrol.css` paints this
module's own six selectors with the accent its tracks already wear.

## What this module cannot tell that page

**Whether an observation has been filed as an incident.** The incidents module
records the observation's uuid on its own side (`Incident::sourceRecordUuid`) and
nothing here mirrors it. So `pl_obsq` shows no unfiled count, marks no row as
unfiled, offers no "file as incident" action and raises no attention row about
one — it says so in its own copy instead. A flag on this side, or a read seam
back from incidents, is what would change that.
