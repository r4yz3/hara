# Phase 1 acceptance tests

Date: 2026-11-12. Each row maps a definition-of-done item (spec 1.3) or human checklist item (spec 15) to the evidence that will prove it. Results are filled in Sprint 8 from real command output saved under `docs/qa/evidence/`. Nothing here has been run yet.

Result key: `not run`, `pass`, `fail`, `blocked`.

## Definition of done (spec 1.3)

| # | Criterion | Evidence | Result |
|---|---|---|---|
| 1 | `setup`, `up`, `migrate`, `seed-demo`, `check` work through Make and PowerShell | Clean-clone transcript on this workstation | not run |
| 2 | API boots with isolated databases; fresh migrate and rollback verified | `migrate:fresh`, `migrate:rollback`, re-migrate output | not run |
| 3 | Register, login, logout, current user | Feature tests B-02 | not run |
| 4 | Admin creates and disables a city, creates and disables a polygon zone; no assumed coordinates or timezone | Feature tests C-02, C-04, C-05; Playwright 3–5; grep for hardcoded coordinates | not run |
| 5 | Serviceability returns inside/outside correctly and `NO_ACTIVE_ZONE` when nothing is configured | ServiceabilityQueryTest, ServiceabilityContractTest | not run |
| 6 | Cross-tenant access denied for guessed or edited IDs | CrossTenantIsolationTest, route coverage test | not run |
| 7 | Four shells work at narrow and wide viewports; placeholders disabled and labelled | Playwright 8; Flutter widget tests; screenshots | not run |
| 8 | CI runs lint, types, tests, schema validation, OpenAPI diff, security scans | Link to a green run | not run |
| 9 | README, ADRs, threat model, test evidence exist | File list | ADRs and threat model written; rest not run |

## Geometry cases (must all pass)

| Case | Expected |
|---|---|
| Point strictly inside an active zone of an active city | serviceable, zone returned |
| Point exactly on the zone boundary | serviceable (`ST_Covers`) |
| Point outside all zones | not serviceable, reason given |
| Point inside a zone and inside an active exclusion | not serviceable |
| Point inside an exclusion whose window has ended or not started | serviceable |
| Point in two overlapping active zones | higher priority wins; tie broken by ID |
| Zone active but city draft or paused | not serviceable |
| No active zone anywhere | `serviceable: false`, `NO_ACTIVE_ZONE`, null city and zone |
| Latitude or longitude out of range, missing or non-numeric | 422, no query executed |
| Longitude and latitude swapped in GeoJSON for a valid-looking polygon | Stored as given; test proves axis order end to end with an asymmetric fixture |
| Self-intersecting ring | 422, nothing stored |
| Ring with fewer than four positions or not closed | 422, nothing stored |
| Vertex count or extent above the cap | 422, nothing stored |
| `Polygon` input | Stored and returned as `MultiPolygon` |
| Stale `version` on update | 409, stored geometry unchanged |

Fixtures use the spec's synthetic coordinates or generated shapes. No real service area appears in tests.

## Authorization cases (must all pass)

| Case | Expected |
|---|---|
| Unauthenticated request to any admin route | 401 |
| Customer calls an admin route | 403 or 404, never data |
| City manager of city A reads, edits or activates a zone in city B | 404 |
| City manager of A creates a zone with `city_id` of B in the body | 404 or 422, nothing stored |
| Registration with `role` or `scope` fields in the body | Fields ignored; user is a customer |
| User grants themselves any role | Denied and logged |
| Grantor assigns a role above their own or outside their scope | Denied |
| Grant revoked, then used | Denied on the next request |
| Merchant staff of merchant X reads merchant Y | 404 |
| Rider token used on a web-only or admin route | Denied |
| Any registered route lacking auth and policy, not on the public list | Test fails |

## Browser scenarios (spec 10.2)

Scenarios 1–10 run in Playwright against the running stack at 375×812, 768×1024 and 1440×900, with axe checks. Each result links to its trace or screenshot.

## Phase boundary checks

| Check | Method | Result |
|---|---|---|
| No route registered for ordering, payments, dispatch, tracking, payouts | Route list compared with the Phase 1 list in the OpenAPI document | not run |
| No invented restaurants, ETAs, KPIs or balances in any shell | Manual review of screenshots plus a text search of the apps | not run |
| No hardcoded city, coordinate or map centre outside test fixtures and the gated demo seeder | Repository search | not run |
| Rider app requests no location permission | Android manifest review and Flutter test | not run |
| Production config refuses seeders, debug and MFA-less privileged login | Config tests | not run |

## Stop conditions

Any failing security, authorization, geometry or migration test blocks the phase. A result is recorded only from real output.
