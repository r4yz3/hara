# ADR-007: Map rendering and tiles

- Status: accepted; tile provider is **open**
- Date: 2026-11-12

## Decision
- Web maps use MapLibre GL JS installed from npm at a pinned version. No CDN script.
- The registry's current major is 6 (6.18.0 on 2026-11-12), which is ESM-only. Sprint 6 adopts v6 if it works with the pinned Next.js; otherwise the last v5 release is pinned and the reason recorded here.
- MapLibre loads only on the client (dynamic import, no SSR).
- The style comes from `MAP_STYLE_URL`. When it is empty the map area shows a labelled placeholder and the GeoJSON text editor remains fully usable. OpenStreetMap's public tile servers are never used.
- Attribution required by the data and the provider is always visible.
- With no active city the map shows an empty state. There is no default centre or zoom. With a city but no zone, the screen prompts to define coverage and draws nothing.
- Zone status is shown by text and pattern as well as colour, with an accessible table that mirrors the map.
- A failed style load offers retry and never blocks saving valid GeoJSON.

## Open
The tile and style provider, its licence terms and its cost. This is a human decision (spec section 16, item 8). Until then every environment runs with the placeholder.

## Consequences
The zone editor must be complete without tiles, which also makes it testable in CI.
