# ADR-008: Routing and ETA (future)

- Status: accepted as a boundary; no implementation in Phase 1
- Date: 2026-11-12

## Decision
- Routing will be a self-hosted Valhalla instance behind a `RoutingProvider` interface in `Infrastructure/Maps`.
- `ROUTING_BASE_URL` empty means routing is disabled. Phase 1 has no route, ETA or distance output anywhere, including placeholders that look like real values.
- Straight-line distance is never presented as travel distance or time.
- Activation requires a motorcycle profile tested against local road rules and turn restrictions in the chosen city, then pilot calibration. Those tests need the launch city, which is not selected.
- Geocoding follows the same pattern: `GEOCODING_BASE_URL` empty means manual pin only.

## Consequences
Serviceability in Phase 1 is polygon containment only. The spec's statement that the "actual route must be valid" is deferred to Phase 4 and recorded in the phase boundaries.
