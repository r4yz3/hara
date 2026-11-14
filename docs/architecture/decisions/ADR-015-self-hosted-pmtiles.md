# ADR-015: Self-hosted PMTiles for map tiles

- Status: accepted (operator decision, 2026-11-14; closes the open item in ADR-007 and backlog U-10)
- Date: 2026-11-14

## Decision
- Tiles come from a Protomaps PMTiles archive (OpenStreetMap data) that we host ourselves on object storage or a CDN we control. No third-party tile API, no API key, no per-request billing.
- MapLibre GL JS is pinned at 6.16.0 and the `pmtiles` protocol package at 4.4.1, both exact versions older than two weeks at install time (AGENTS.md rule 10). The `pmtiles://` protocol is registered once, when the map library first loads on the client.
- `MAP_STYLE_URL` stays the only switch. It points at a style JSON on our tile host whose vector source is `pmtiles://https://<tile host>/<file>.pmtiles`. Empty still means the labelled placeholder (ADR-007).
- MapLibre's worker is emitted by the bundler as an asset and set with `setWorkerUrl`, so the map works from the built app without a CDN.
- The CSP helper takes the tile host's origin (`tileOriginFrom`) and adds it to `connect-src` only, plus `worker-src 'self'` (the worker is a same-origin asset, so no `blob:`). The per-request policy is still unwired (E-02 deferred); the baseline CSP does not restrict connections.

## Hosting requirements (operator)
- The host must support HTTP Range requests and return CORS headers allowing `GET` and `Range` from the admin origin.
- Attribution "© OpenStreetMap contributors" and "Protomaps" must be in the style's source `attribution`; the map's attribution control shows it.
- The archive is extracted for the chosen coverage only after the launch city is chosen. No file, bounds or coordinates are committed to this repository.

## Not done here
- No PMTiles archive, style JSON or tile host exists yet; every environment still shows the placeholder until the operator sets `MAP_STYLE_URL`.
- Drawing zones on the map (as opposed to viewing them) is not built; GeoJSON editing remains the complete path.

## Consequences
- No provider lock-in or key rotation. We pay storage and egress, and we refresh the archive ourselves when OpenStreetMap data changes.
