# ADR-002: Location storage and spatial predicates

- Status: accepted; numeric caps are **open**
- Date: 2026-11-12

## Context
Serviceability decides whether a customer can order at all. Boundary handling, axis order and invalid polygons are the usual sources of wrong answers.

## Decision
- PostGIS is required. Points are `geometry(Point,4326)`; coverage and exclusions are `geometry(MultiPolygon,4326)`; each has a GiST index.
- Containment uses `ST_Covers`, so a point exactly on a zone boundary is inside the zone. A point on an exclusion boundary is excluded for the same reason.
- Distances in metres cast to `geography`. No planar distance on SRID 4326 values.
- Axis order is longitude, latitude everywhere a pair is positional (GeoJSON, `ST_MakePoint`). API query parameters are named `latitude` and `longitude` and never positional. Latitude is validated to [-90, 90] and longitude to [-180, 180] before any SQL runs.
- Admin input is GeoJSON `Polygon` or `MultiPolygon`. The server converts to MultiPolygon, drops altitude, rejects rings with fewer than four positions or unclosed rings, rejects self-intersections (`ST_IsValid`), rejects empty geometry and enforces vertex and extent caps. The server never repairs geometry silently with `ST_MakeValid`; invalid input is returned as a validation error.
- The stored geometry is authoritative. Clients re-fetch after save.
- Overlapping active zones resolve by `priority DESC, id ASC`.
- Backend tests run on PostGIS. sqlite is not used.

## Open
The spec requires vertex and area caps but gives no numbers. They are configuration values, not constants. Proposed starting values for human approval: 5,000 vertices per zone and a bounding box no larger than 2 degrees on either axis.

## Consequences
- No geometry is active without passing database `CHECK` constraints and application validation.
- No city, coordinate or map centre appears in code or in non-test seed data.


## Caps decision (2026-11-14, backlog C-13)
Operator approved: at most about 500 vertices per zone outline, and no self-intersection. Self-intersection is already rejected (`ST_IsValid`). The proposal above and `backend/config/geo.php` say 5,000 vertices, so the approved cap is **stricter than the code default**. To apply it, set `GEO_ZONE_MAX_VERTICES=500` and `GEO_CAPS_APPROVED=true` (or change the default, with tests). Not changed in this edit. The 2-degree extent cap was not part of the decision and stays proposed. No real polygon was added.

### Caps applied in code (2026-11-14)
`backend/config/geo.php` now defaults `zone_max_vertices` to 500 and `caps_approved` to true; `.env.example` and `infra/staging/.env.example` set `GEO_ZONE_MAX_VERTICES=500` and `GEO_CAPS_APPROVED=true`. The count covers all rings of a submitted outline. Tests: 500 vertices pass and 501 give a 422 `VALIDATION_ERROR` on `geometry`. **The 2-degree extent cap was not approved and remains a proposed value**, although the single `caps_approved` flag (shown as "Geometry caps: Approved" in the admin system status) now reads true; a separate flag for the extent cap is a decision for the operator. The demo zone is a 0.02-degree synthetic square with 5 vertices.
