# What patrols puts on the area overview

`AreaBundle`'s `/areas/{uuid}` is **composed from module-contributed widgets**:
that bundle owns the surface, the grid, the preset framework and the area's
identity, and every operational number arrives through a contribution point.

## Contents

- [The five this module fills](#the-five-this-module-fills)
- [What the area provides for these plates](#what-the-area-provides-for-these-plates)
- [What this module cannot tell that page](#what-this-module-cannot-tell-that-page)

## The five this module fills

All tagged explicitly in the bundle extension, because a reusable bundle is not
autoconfigured:

| Contribution point | Tag | Class |
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
going. `PatrolOverviewService` measures that morning once and all five providers
read it, so the strip's count and the live card's rows cannot disagree.

## What the area provides for these plates

The `.ao-*` vocabulary (`.ao-by`, `.ao-live`, `.ao-col`, `.ao-colstack`,
`.ao-att`, `.ao-legend`, `.ao-move`, `.ao-dot`) belongs to `AreaBundle`'s
overview and is **not** shipped here. That bundle paints every contributor dot a
neutral fog and names no module in a rule; `public/patrol.css` paints this
module's own six selectors with the accent its tracks already wear.

## What this module cannot tell that page

**Whether an observation has been filed as an incident.** The incidents module
records the observation's uuid on its own side (`Incident::sourceRecordUuid`) and
nothing here mirrors it. So `pl_obsq` shows no unfiled count, marks no row as
unfiled, offers no "file as incident" action and raises no attention row about
one — it says so in its own copy instead. A flag on this side, or a contract to
read back from incidents, is what would change that.
