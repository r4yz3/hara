# Phase 1 backlog

Date: 2026-11-12. Stories are grouped by the spec's slices (section 9) and ordered by sprint (section 12). Each story is done only with migration, policy, request validation, resource, OpenAPI entry and tests where they apply (AGENTS.md rule 6). Test names refer to spec section 10.

Status key: `todo`, `blocked`, `done`.

## Sprint 1 — discovery and ADRs

| ID | Story | Acceptance | Status |
|---|---|---|---|
| S1-01 | Record tool and package versions | `docs/architecture/versions.md` lists measured versions and gaps | done |
| S1-02 | Write ADR-001 to ADR-010 and record source discrepancies | Files in `docs/architecture/decisions/`; open items marked | done |
| S1-03 | Threat model and permission matrix | `docs/security/` files exist; open risks listed | done (awaiting review) |
| S1-04 | Phase boundaries and acceptance tests | `phase-boundaries.md`, `docs/qa/phase1-acceptance.md` | done |
| S1-05 | No silent city guess | No city name, coordinate or map centre in any Sprint 1 output except the spec's own synthetic example | done |

## Sprint 2 — repository and infrastructure (Slice A)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| A-01 | Install Docker Desktop (human) | `docker compose version` works | — | blocked (human) |
| A-02 | Initialize git, `.gitignore`, `.editorconfig`, `.env.example` | No secrets; example values are placeholders | — | done (no commit yet) |
| A-03 | pnpm workspace with config package | `pnpm install` clean; `packageManager` pinned | A-02 | done |
| A-04 | Laravel 13 skeleton in `backend/` with module directories | `php artisan about` in the `api` container; architecture test passes | A-01 | partial: skeleton, modules and architecture test done natively; not yet run in the `api` container |
| A-05 | Compose stack: api, postgres+postgis, redis, minio, mailpit | `docker compose config` valid; health checks green; images pinned; ports documented | A-01 | written, unverified (no Docker); object storage omitted, see ADR-012 |
| A-06 | Separate PostGIS test database | Tests never touch the dev database | A-05 | written, unverified (no Docker) |
| A-07 | Health routes | `/health/live` minimal; `/health/ready` internal only | A-04 | done (5 tests) |
| A-08 | Three Next.js app skeletons | `pnpm build` and `pnpm typecheck` pass | A-03 | done |
| A-09 | Flutter rider skeleton | `flutter analyze` and `flutter test` pass | — | done (4 tests) |
| A-10 | Makefile plus PowerShell scripts | `setup`, `up`, `down`, `migrate`, `seed-demo`, `check`, `logs` each have a `.ps1` equivalent that works on this workstation | A-05 | partial: `dev.ps1 check` verified; `up`, `down`, `migrate`, `logs` and the Makefile unverified; `seed-demo` waits for D-05 |
| A-11 | CI workflows: backend, web, flutter, security | All run on a clean clone; actions pinned by SHA; least-privilege tokens | A-04, A-08, A-09 | written, never run (no remote repository) |
| A-12 | Install Laravel Boost as a dev dependency | Boost MCP server connects | A-04 | todo |

## Sprint 3 — database

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| D-01 | Migrations 001–017 in spec order, merged with framework defaults | `migrate:fresh`, full rollback and re-migrate pass | A-04, A-05 | todo |
| D-02 | Check constraints and indexes | GiST on geometries, tenant-scoped uniques, status checks; schema test asserts them | D-01 | todo |
| D-03 | Confirm ULID case and add normalization | Test proves one stored case and case-insensitive lookup (ADR-003) | D-01 | todo |
| D-04 | Spatial round-trip test | GeoJSON in equals normalized GeoJSON out | D-01 | todo |
| D-05 | Factories and gated development seeder | Seeder refuses outside `local`/`testing`; demo city and zone are clearly labelled synthetic | D-01 | todo |
| D-06 | `Money` value object | CurrencyAmountTest; no float path | A-04 | todo |
| D-07 | Confirm city status values (human) | ADR-000 item 8 closed | — | blocked (human) |

## Sprint 4 — authentication and RBAC (Slice B)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| B-00 | Close ADR-011 open items (human + agent) | Session separation and MFA factor decided and threat-modelled | — | blocked (human) |
| B-01 | Approve permission matrix (human) | Reviewer questions answered | — | blocked (human) |
| B-02 | Register, login, logout, `/me` | RegisterCustomerTest, SessionLoginTest, SessionLogoutTest, MeEndpointTest | D-01, B-00 | todo |
| B-03 | Password recovery | Generic response; single-use expiring token | B-02 | todo |
| B-04 | Throttling | RateLimitTest | B-02 | todo |
| B-05 | Permission resolver and scope policies | PermissionResolverTest, ScopePolicyTest | D-01, B-01 | todo |
| B-06 | Role grant and revoke service | No self-grant, no grant above own role or scope; revoke race test; audit row | B-05, B-07 | todo |
| B-07 | Audit service | Atomic with the change; AuditRedactionTest; no update or delete path | D-01 | todo |
| B-08 | Mobile token exchange | MobileTokenScopeTest | B-02 | todo |
| B-09 | MFA challenge step and production gate | Privileged login refused in production config without MFA | B-00 | todo |
| B-10 | Route coverage test | Every registered route has auth and a policy or is on an explicit public list | B-05 | todo |
| B-11 | Error envelope | ApiErrorEnvelopeTest | A-04 | todo |

## Sprint 5 — city and serviceability API (Slice C)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| C-01 | OpenAPI 3.1 document for all Phase 1 routes | Lints clean; CI diff check | A-04 | todo |
| C-02 | City create, read, update, status transitions | CitySlugTest, CityStatusTransitionTest, AdminCityCreateTest | B-05, D-07 | todo |
| C-03 | Zone geometry validator | ZoneGeometryValidatorTest, CoordinateBoundsTest, fuzz test | D-01 | todo |
| C-04 | Zone create and update with versioning | ZoneCreateTest; stale update returns 409; concurrent edit test | C-03, B-05 | todo |
| C-05 | Zone activate and pause | ZoneActivateTest; needs active city, valid geometry, reason on pause | C-04 | todo |
| C-06 | Zone archive through explicit status transition | Covered by transition test (ADR-000 item 9) | C-04 | todo |
| C-07 | Coverage exclusions | Exclusion and time-window tests | C-04 | todo |
| C-08 | Serviceability endpoint | Inside, outside, boundary, exclusion, overlap, no active city, invalid coordinate; ServiceabilityContractTest; EXPLAIN uses GiST | C-05, C-07 | todo |
| C-09 | Public config and public cities | PublicCitiesTest; empty until a city is active | C-02 | todo |
| C-10 | Audit log and system status endpoints | Cursor, filters, redaction; no secrets | B-07 | todo |
| C-11 | Generated TypeScript client | Generated from C-01; CI fails if stale | C-01 | todo |
| C-12 | Cross-tenant suite | CrossTenantIsolationTest across city and merchant scopes | C-02..C-10 | todo |
| C-13 | Approve vertex and extent caps (human) | ADR-002 open item closed | — | blocked (human) |

## Sprint 6 — design system and shells (Slice D)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| U-01 | Design tokens with measured contrast | Every text/background pair used meets WCAG 2.2 AA; results recorded | A-03 | todo |
| U-02 | `packages/ui` components and Storybook | Form, table, nav, dialog, toast, state components; axe clean | U-01 | todo |
| U-03 | UI state matrix | Each view has loading, empty, error, forbidden, offline, validation, conflict, slow | U-02 | todo |
| U-04 | Map component | Client-only load; placeholder when `MAP_STYLE_URL` empty; no default centre; attribution | U-02 | todo |
| U-05 | Admin: login, overview, cities, zones editor, users, audit, settings | Playwright scenarios 3–6, 9, 10 | C-11, U-04 | todo |
| U-06 | Customer: home, location, login, account, labelled previews | Playwright scenarios 1, 2, 5, 7, 8 | C-11, U-04 | todo |
| U-07 | Merchant shell | Scoped, no fabricated data, disabled actions labelled | C-11, U-02 | todo |
| U-08 | Rider shell | Flutter tests in spec 10.3; no location permission request | A-09, B-08 | todo |
| U-09 | Screenshots at 360/375, 768, 1440 from running apps | Saved under `docs/qa/evidence/` | U-05..U-08 | todo |
| U-10 | Select tile provider (human) | ADR-007 open item closed, or placeholder accepted for Phase 1 | — | blocked (human) |

## Sprint 7 — observability and staging (Slice E)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| E-01 | Structured logs with request IDs and the named events | Events from spec 11.3 emitted; no exact customer coordinates logged | A-04 | todo |
| E-02 | CSP, CORS, cookie review | Negative tests pass | B-02 | todo |
| E-03 | Backup and restore script and drill | Restore into a new database; migrations and spatial data verified; evidence recorded | D-01 | todo |
| E-04 | Security scans in CI | Secret scan, dependency audits, container scan; findings classified | A-11 | todo |
| E-05 | Deploy, rollback and incident runbooks | `docs/operations/` complete; rollback rehearsed | A-05 | todo |
| E-06 | Production-config test | No debug routes, `APP_DEBUG=false`, seeders refuse | A-04 | todo |
| E-07 | Staging environment (human provides hosting) | Repeatable deploy; domains satisfy ADR-011 | E-05 | blocked (human) |

## Sprint 8 — acceptance

| ID | Story | Acceptance | Status |
|---|---|---|---|
| X-01 | Run every check in `docs/qa/phase1-acceptance.md` | Real output recorded; no invented results | todo |
| X-02 | Final `docs/PHASE1_STATUS.md` | Done, partial, blocked and future separated accurately | todo |
| X-03 | Phase 2 backlog | Merchant and catalogue stories with open decisions | todo |

## Human decisions needed, in order of urgency

| # | Decision | Needed before | Reference |
|---|---|---|---|
| 1 | Install Docker Desktop | Sprint 2 | A-01 |
| 2 | City status values | Sprint 3 | D-07 |
| 3 | Session separation and MFA factor | Sprint 4 | B-00, ADR-011 |
| 4 | Permission matrix approval | Sprint 4 | B-01 |
| 5 | Geometry caps | Sprint 5 | C-13 |
| 6 | Tile provider, or accept placeholder | Sprint 6 | U-10 |
| 7 | Staging hosting and domain layout | Sprint 7 | E-07 |
| 8 | Android SDK and JDK install | Rider device builds | versions.md |

The launch city is deliberately not on this list. Phase 1 does not need it.
