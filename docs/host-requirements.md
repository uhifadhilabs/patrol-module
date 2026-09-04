# What the host provides

This module ships domain capability, not platform mechanics. Two surfaces —
the dashboard and the maps — ride seams the host already owns, and a host that
does not provide them gets a module that installs but cannot draw.

## The widget framework

**Dashboard surfaces ride the host's widget framework.** The module's dashboard
is composed on the HOST's `WidgetService` / `WidgetCatalog` preset component —
the same technique behind the host's departments, team, zones and incidents
surfaces — rather than a second widget implementation inside this bundle. This
module ships a catalogue (`PatrolWidgets`) and seven Twig partials; it ships no
widget mechanics at all. The host must therefore provide the widget framework
(`Uhifadhi\Service\{WidgetService,WidgetEndpoint}`, the widget entities and
`templates/widgets/_library.html.twig` with its `uhifadhi/widgets` script) and
symfony/ux-icons with the `lucide` set imported.

## What the host provides for the maps

Patrols is a uhifadhi module, so its maps ride on the platform's map seam rather
than shipping a second copy of it (see [design-decisions.md](design-decisions.md) §6):

- **Leaflet**, self-hosted by `uhifadhi/map-module` and linked through that
  bundle's `LEAFLET_CSS` / `LEAFLET_JS` constants (`window.L`). No CDN, never
  MapLibre.
- **Basemaps** under the importmap specifier `uhifadhi/basemaps`, exporting
  `satelliteLayer(L, map)` and `streetLayer(L)`.
- **Boundary treatment** under `uhifadhi/boundary`, exporting `drawBoundary(L,
  map, geojson, { scrim })` — the platform's one area outline: the
  outside-the-area scrim, a white casing and the jade line.

## What the host provides for the overview plates

The `.ao-*` vocabulary the area overview's plates are painted in belongs to the
host, not to this bundle — see [area-overview.md](area-overview.md), which also
lists the five seams this module fills on that page.
