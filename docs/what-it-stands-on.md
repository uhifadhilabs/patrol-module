# What this module stands on

This module ships domain capability, not platform mechanics. Everything that is
not about patrols arrives as another module bundle, and each is a composer
requirement rather than something an installation is expected to have written.

## The page frame

Every patrol screen extends `@UhifadhiShell/page.html.twig` and fills sockets:
the breadcrumb, the title, the subtitle, the actions and the body. It types no
page furniture of its own — no `.page` wrapper, no crumb markup, no flash loop —
so a saved patrol reads exactly like a saved anything else in the installation.

Patrols is **area-scoped**: every route sits under `/areas/{uuid}/modules/patrols`,
so the frame's default tab strip (the area's own) is left in place. An org-wide
hub blanks that socket; this module deliberately does not.

`uhifadhi/shell-module` is a suggestion rather than a requirement — the field-sync
API and every command work without it. What it costs to leave out is the screens:
they have no frame to extend.

## The widget framework

The dashboard **is** a widget dashboard, not a page with widgets on it, so
`uhifadhi/widget-module` is a hard requirement. This module ships a catalogue
(`Uhifadhi\Patrol\Widget\PatrolWidgets`) and seven Twig partials; it ships no
widget mechanics at all. The library screen hands that bundle's own component the
whole contract, and every write is answered by `widget.endpoint` — this module
validates no token and chooses no status code.

`PatrolWidgets` implements `WidgetSurfaceInterface` and is **tagged**
(`uhifadhi.widget_surface`). Being findable is the half a catalogue alone cannot
do: `widget:prune` walks the registry, and layouts keyed to a surface no service
claims are exactly what it deletes.

Layouts are keyed by `(surface, person, area)`, so the same person may lay one
area's patrols out one way and another area's another.

## The area

`uhifadhi/area-module` owns the place a patrol happens in — `AreaOfInterest`,
the zones the gap card reads, the KPI seam the department figures are reported
through and the six overview seams this module contributes to. Every one of those
tags is applied by hand in this bundle's extension, because a reusable bundle is
not autoconfigured.

Two things about that seam are worth stating, because both used to be otherwise:

- **A department arrives as a `DepartmentRef`** — id, uuid and name — never as an
  entity. Departments belong to `uhifadhi/team-module` and nothing publishes a
  contract for one, so a seam typed against that class would make every module
  that reports a figure hard-require team.
- **An area always has a boundary.** `area_of_interest.geom` is NOT NULL, so the
  boundaryless area this module's coverage query still guards against is no
  longer reachable.

## The evidence store

Observation photographs are stored by `uhifadhi/storage-module`, a hard
requirement, and this module fills both halves of its permission seam: a voter
that says who may read a photograph, and a file source that says which
photographs exist. Where the bytes go is in [photo-storage.md](photo-storage.md).

## The maps

Patrols' maps ride the platform's map seam rather than shipping a second copy of
it (see [design-decisions.md](design-decisions.md) §6):

- **Leaflet**, self-hosted by `uhifadhi/map-module` and linked through that
  bundle's `LEAFLET_CSS` / `LEAFLET_JS` constants (`window.L`). No CDN, never
  MapLibre.
- **Basemaps** under the importmap specifier `uhifadhi/basemaps`, exporting
  `satelliteLayer(L, map)` and `streetLayer(L)`.
- **Boundary treatment** under `uhifadhi/boundary`, exporting `drawBoundary(L,
  map, geojson, { scrim })` — the platform's one area outline: the
  outside-the-area scrim, a white casing and the jade line.

## The per-area catalogue

`uhifadhi/seam-module` holds the catalogue an area switches modules on in. This
bundle registers one `ModuleProviderInterface` (`uhifadhi.module`) declaring the
slug `patrols`, its category, its icon, its one permission and its entry route.
The seam resolves that route, so an area's module grid opens the patrol dashboard
directly rather than a generic module page.

The grid itself is the seam's screen and is not built yet. Until it is, the
breadcrumb's "modules" step renders as plain text rather than a link — see
`PatrolTrailExtension`, which answers null for a route the installation did not
mount instead of throwing the page away.

## The generic component vocabulary

`.c`, `.tab`, `.kpi`, `.tbl`, `.chip`, `.mchip`, `.crumb`, `.pghead`, `.backbtn`,
`.open-btn`, `.tgl` and the page scaffold are the **shell's** stylesheet;
`.w-grid`, `.w-cell` and `.w-span-*` are the **widget module's**. This bundle's
`public/patrol.css` adds only what a patrol screen needs and no other surface
has — the coverage viewer, the type chips, the calendar, the track plate — and
never restates a rule it did not invent.

The `.ao-*` vocabulary the area overview's plates are painted in belongs to the
area module; see [area-overview.md](area-overview.md), which also lists the
seams this module fills on that page.
