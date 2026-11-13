# Phase 1 acceptance tests

Updated 2026-11-14. Each row maps a definition-of-done item (spec 1.3) or human checklist item (spec 15) to its evidence. Local backend results come from `docs/qa/evidence/phase1-2026-11-14-check.txt`.

Result key: `not run`, `pass`, `fail`, `blocked`.

## Definition of done (spec 1.3)

| # | Criterion | Evidence | Result |
|---|---|---|---|
| 1 | `setup`, `up`, `migrate`, `seed-demo`, `check` work through Make and PowerShell | `docs/qa/evidence/x01-2026-11-14-clean-clone.txt` | partial: `setup` passes in a fresh clone; `up`, `migrate`, `seed-demo`, `check` not run there (would reuse the running stack); Makefile not run (`make` missing) |
| 2 | API boots with isolated databases; fresh migrate and rollback verified | Healthy Compose stack and MigrationRollbackTest in the isolated test database | pass locally |
| 3 | Register, login, logout, current user | Feature tests B-02 | pass locally |
| 4 | Admin creates and disables a city, creates and disables a polygon zone; no assumed coordinates or timezone | Feature tests C-02, C-04, C-05 and local live Chromium run with synthetic GeoJSON | City and zone lifecycle verified locally; map drawing and repository-wide hardcoded-coordinate review remain open |
| 5 | Serviceability returns inside/outside correctly and `NO_ACTIVE_ZONE` when nothing is configured | ServiceabilityTest | pass locally |
| 6 | Cross-tenant access denied for guessed or edited IDs | CrossTenantIsolationTest, route coverage test | pass for implemented city-scoped endpoints; merchant endpoints are pending |
| 7 | Four shells work at narrow and wide viewports; placeholders disabled and labelled | `docs/qa/evidence/x01-2026-11-14-playwright-default.txt`; Flutter widget tests (47 pass, 2 skipped, in the clean clone) | pass locally for Chromium at 375, 768 and 1440 wide; the rider app has never run on a device |
| 8 | CI runs lint, types, tests, schema validation, OpenAPI diff, security scans | `docs/qa/evidence/x01-2026-11-14-hosted-ci.txt`: web, backend and security succeeded on `099a3db`; earlier pushes and dependabot PRs green | pass for what the workflows define; not independently audited |
| 9 | README, ADRs, threat model, test evidence exist | `README.md`, `docs/architecture/decisions/`, `docs/security/threat-model.md`, `docs/qa/evidence/` | pass for artifact presence |

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

The default Playwright suite runs in Chromium at 375×812, 768×1024 and 1440×900. It checks horizontal overflow and runs axe for the closed-coverage customer banner and empty restaurant state, outside coverage and retry after a 503, disabled merchant actions, an expired admin session linking back to a local path, and the city status dialog. The dialog case checks keyboard opening, tab order, Escape dismissal and focus restoration. A separate sign-in case verifies external return-path rejection. Browser API calls and the server-rendered coverage banner use synthetic responses in this suite.

All 27 default cases passed locally, including a zone edit conflict that preserves the draft and system settings retry and permission denial at each viewport. See `docs/qa/evidence/phase1-2026-11-14-admin-settings.txt`.

The opt-in desktop run covers live admin sign-in, city creation and activation, invalid GeoJSON rejection, normalized shape persistence, zone edit and activation, exclusion add and remove, customer serviceability and denial of admin access to a signed-in customer. Cleanup pauses and archives the synthetic records. Map drawing remains open. See `docs/qa/evidence/phase1-2026-11-14-zone-exclusions.txt`.

## Phase boundary checks

| Check | Method | Result |
|---|---|---|
| No route registered for ordering, payments, dispatch, tracking, payouts | RouteInventoryTest and OpenApiDocumentTest | pass locally |
| No invented restaurants, ETAs, KPIs or balances in any shell | Manual review of screenshots plus a text search of the apps | not run |
| No hardcoded city, coordinate or map centre outside test fixtures and the gated demo seeder | Repository search | not run |
| Rider app requests no location permission | Android manifest review and Flutter test | not run |
| Production config refuses seeders, debug and MFA-less privileged login | SchemaTest, ProductionGuardTest and SessionLoginTest | pass locally |

## Stop conditions

Any failing security, authorization, geometry or migration test blocks the phase. A result is recorded only from real output.
