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
| A-01 | Install Docker Desktop (human) | `docker compose version` works | — | done: Docker Desktop started and the development stack was brought up on 2026-11-14 |
| A-02 | Initialize git, `.gitignore`, `.editorconfig`, `.env.example` | No secrets; example values are placeholders | — | done (committed on `main`) |
| A-03 | pnpm workspace with config package | `pnpm install` clean; `packageManager` pinned | A-02 | done |
| A-04 | Laravel 13 skeleton in `backend/` with module directories | `php artisan about` in the `api` container; architecture test passes | A-01 | done |
| A-05 | Compose stack: api, postgres+postgis, redis, minio, mailpit | `docker compose config` valid; health checks green; images pinned; ports documented | A-01 | done: validated, built and started healthy; object storage omitted, see ADR-012 |
| A-06 | Separate PostGIS test database | Tests never touch the dev database | A-05 | done: backend tests run in the container against `food_delivery_test` |
| A-07 | Health routes | `/health/live` minimal; `/health/ready` internal only | A-04 | done (5 tests) |
| A-08 | Three Next.js app skeletons | `pnpm build` and `pnpm typecheck` pass | A-03 | done |
| A-09 | Flutter rider skeleton | `flutter analyze` and `flutter test` pass | — | done (47 Flutter tests in the 2026-11-14 `dev.ps1 check`; see U-08) |
| A-10 | Makefile plus PowerShell scripts | `setup`, `up`, `down`, `migrate`, `seed-demo`, `check`, `logs` each have a `.ps1` equivalent that works on this workstation | A-05 | done for PowerShell: all nine `dev.ps1` tasks run on this workstation with output piped (`docs/qa/evidence/a10-dev-tasks-check.txt`); fixed a bug where piped output made `up` abort on Docker's first progress line. The Makefile is still unverified: `make` is not installed here |
| A-11 | CI workflows: backend, web, flutter, security | All run on a clean clone; actions pinned by SHA; least-privilege tokens | A-04, A-08, A-09 | done: hosted CI ran green on `61ccc84` (web, backend, security with secret scan and three image scans; `docs/qa/evidence/x01-2026-11-14-hosted-ci-61ccc84.txt`). Not independently audited; commits after `61ccc84` unverified |
| A-12 | Install Laravel Boost as a dev dependency | Boost MCP server connects | A-04 | done: installed as a dev dependency; server answers an MCP initialize request; root `.mcp.json` added; browser-log route disabled |

## Sprint 3 — database

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| D-01 | Migrations 001–017 in spec order, merged with framework defaults | `migrate:fresh`, full rollback and re-migrate pass | A-04, A-05 | done: 6 migration files cover seq 001–016; fresh, full rollback and re-migrate pass (MigrationRollbackTest). Seq 017 adds no table by design |
| D-02 | Check constraints and indexes | GiST on geometries, tenant-scoped uniques, status checks; schema test asserts them | D-01 | done: checks, tenant-scoped uniques and GiST indexes asserted in SchemaTest |
| D-03 | Confirm ULID case and add normalization | Test proves one stored case and case-insensitive lookup (ADR-003) | D-01 | done: Laravel emits lower case and storage is case-sensitive (test pins both); boundary normalization still to do in Sprint 4 |
| D-04 | Spatial round-trip test | GeoJSON in equals normalized GeoJSON out | D-01 | done: Polygon in, MultiPolygon out, lon/lat order and SRID asserted |
| D-05 | Factories and gated development seeder | Seeder refuses outside `local`/`testing`; demo city and zone are clearly labelled synthetic | D-01 | done: DevelopmentSeeder creates one labelled synthetic city and zone, is idempotent, and refuses to run in production (tested) |
| D-06 | `Money` value object | CurrencyAmountTest; no float path | A-04 | done (CurrencyAmountTest, 41 cases incl. overflow and float-hostile values) |
| D-07 | Confirm city status values (human) | ADR-000 item 8 closed | — | proceeding on the proposal (draft, active, paused, archived) as a provisional value; still needs approval |

## Sprint 4 — authentication and RBAC (Slice B)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| B-00 | Close ADR-011 open items (human + agent) | Session separation and MFA factor decided and threat-modelled | — | done: decided 2026-11-13 (ADR-011) |
| B-01 | Approve permission matrix (human) | Reviewer questions answered | — | done: accepted 2026-11-13 |
| B-02 | Register, login, logout, `/me` | RegisterCustomerTest, SessionLoginTest, SessionLogoutTest, MeEndpointTest | D-01, B-00 | done: csrf, register, login, logout, `/me` (37 feature tests) plus 12 real-request checks in `docs/qa/evidence/sprint4-auth-smoke.txt`. `PATCH /me` built for the display name only (ProfileUpdateTest, 27; CSRF checked live in `phase1-2026-11-14-profile-csrf.txt`); email and phone changes need a verification flow and are not editable |
| B-03 | Password recovery | Generic response; single-use expiring token | B-02 | done: forgot and reset endpoints and screens in all three apps; local email run. PasswordRecoveryTest verifies existing browser sessions are rejected on the next protected request after reset |
| B-04 | Throttling | RateLimitTest | B-02 | done for sign-in: 5 failures per account and address per minute, plus a per-address cap on each auth route (tested) |
| B-05 | Permission resolver and scope policies | PermissionResolverTest, ScopePolicyTest | D-01, B-01 | done: `AccessChecker` (AccessCheckerTest, 11 tests) — deny by default, scope and status read from storage on every check |
| B-06 | Role grant and revoke service | No self-grant, no grant above own role or scope; revoke race test; audit row | B-05, B-07 | done as a service: `RoleGrantServiceTest` covers permissions and audit. `ConcurrentRoleGrantTest` proves a concurrent replacement waits for revocation and leaves one active grant with the expected audit rows. HTTP endpoints built 2026-11-14 (`POST`/`DELETE /api/v1/admin/users/{user}/roles`, StaffAccessTest, 27 tests) and used by the `/access` screen |
| B-07 | Audit service | Atomic with the change; AuditRedactionTest; no update or delete path | D-01 | done as a service: `AuditLogger` with redaction (AuditAndCatalogueTest); request IP and correlation ID are accepted but nothing passes them in yet |
| B-08 | Mobile token exchange | MobileTokenScopeTest | B-02 | done: issue, refresh, revoke and `/rider/me` (RiderTokenTest, 13 tests; ten real requests); no rider approval flow, no device attestation, no Flutter sign-in screen |
| B-09 | MFA challenge step and production gate | Privileged login refused in deployed environments without MFA | B-00 | done, awaiting product owner review of recovery codes (ADR-011): authenticator-app codes and 10 recovery codes; code step at admin sign-in; enrolment-only sessions outside local/testing; `/security` enrolment page; owner reset (`users.mfa.reset`, reason required, ends sessions) and self-service code regeneration. StaffMfaTest, StaffMfaRecoveryTest, staff-mfa browser tests. Open: QR code, disabling. Admin screens other than sign-in show a "Set up two-step sign-in" panel linking to `/security` (cities, city detail, audit, settings, access); inline action errors do not yet carry the link |
| B-10 | Route coverage test | Every registered route has auth and a policy or is on an explicit public list | B-05 | done: RouteCoverageTest (8 tests); no gaps found |
| B-11 | Error envelope | ApiErrorEnvelopeTest | A-04 | done: one renderer for every API error; nothing internal leaks even with debug on (ApiErrorEnvelopeTest) |

## Sprint 5 — city and serviceability API (Slice C)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| C-01 | OpenAPI 3.1 document for all Phase 1 routes | Lints clean; CI diff check | A-04 | OpenApiDocumentTest compares the contract with registered routes. Web tests enforce OpenAPI structure, resolvable references, unique operation IDs and generated type freshness. Hosted web and backend workflows passed on `61ccc84` (`docs/qa/evidence/x01-2026-11-14-hosted-ci-61ccc84.txt`); later commits unverified |
| C-02 | City create, read, update, status transitions | CitySlugTest, CityStatusTransitionTest, AdminCityCreateTest | B-05, D-07 | done as API: list, create, show, update with status transitions, scope-filtered, audited (AdminCityTest, 13 tests). Admin cities screens built under U-05 (`docs/qa/evidence/phase1-2026-11-14-city-dialog.txt`) |
| C-03 | Zone geometry validator | ZoneGeometryValidatorTest, CoordinateBoundsTest, fuzz test | D-01 | done: structural validator (`ZoneGeometry`, ZoneGeometryValidatorTest, 32 cases) with `Coordinates` (27 cases); PostGIS rejects self-intersection at the API (AdminZoneTest); seeded fuzz tests: ZoneGeometryFuzzTest (3,000 random and mutated inputs) and ZoneGeometryPostgisFuzzTest (150 random polygons through the API, compared with `ST_IsValid`). Replayed under 30 and 15 extra seeds; no defect found |
| C-04 | Zone create and update with versioning | ZoneCreateTest; stale update returns 409; concurrent edit test | C-03, B-05 | done as API: list, create, show, update; version check returns 409 and writes nothing; PostGIS rejects self-intersection; city scope enforced; audited without geometry (AdminZoneTest). ConcurrentZoneUpdateTest verifies two PostgreSQL sessions contend and only the winning update is stored and audited. Admin zone editor (GeoJSON create and edit) is built under U-05 (`docs/qa/evidence/phase1-2026-11-14-zone-edit.txt`) |
| C-05 | Zone activate and pause | ZoneActivateTest; needs active city, valid geometry, reason on pause | C-04 | done: `POST /admin/zones/{zone}/activate` (needs `confirm: true`, an active city and a valid stored shape) and `/pause` (reason required, kept in the audit row); both check `version` (AdminZoneStatusTest, 8 tests) |
| C-06 | Zone archive through explicit status transition | Covered by transition test (ADR-000 item 9) | C-04 | done: `POST /admin/zones/{zone}/archive`, from draft or paused only; an active zone must be paused first (AdminZoneStatusTest) |
| C-07 | Coverage exclusions | Exclusion and time-window tests | C-04 | done: list and create under `/admin/zones/{zone}/exclusions`, delete at `/admin/exclusions/{exclusion}`; optional UTC time window; shape must be valid and overlap the zone; audited without geometry (AdminExclusionTest, 9 tests). No edit: delete and add |
| C-08 | Serviceability endpoint | Inside, outside, boundary, exclusion, overlap, no active city, invalid coordinate; ServiceabilityContractTest; EXPLAIN uses GiST | C-05, C-07 | done: endpoint built and tested (ServiceabilityTest, which also checks the response fields); a zone activated through the API is shown serviceable (AdminZoneStatusTest); the plan uses the GiST index with 2,500 synthetic zones (ServiceabilityPlanTest) |
| C-09 | Public config and public cities | PublicCitiesTest; empty until a city is active | C-02 | done: `GET /api/v1/public/cities`, active cities only, public fields only (ServiceabilityTest); `GET /api/v1/public/config` returns currency, display timezone, `service_open`, map style URL and the four feature flags from a fixed list (PublicConfigTest, 5 tests) |
| C-10 | Audit log and system status endpoints | Cursor, filters, redaction; no secrets | B-07 | done: `GET /admin/audit-logs` (newest first, five filters, city-scoped for city-scoped staff, redacted again on read, no address hash) and `GET /admin/system/status` (global scope only; up/down checks, counts, flags) (AuditLogAndStatusTest, 7 tests) |
| C-11 | Generated TypeScript client | Generated from C-01; CI fails if stale | C-01 | generated contract types are checked for drift by `pnpm test`. The shared browser session client uses the generated CSRF, sign-in, current-user and error types, and admin code imports types directly. Request helpers are maintained in the app and session packages |
| C-12 | Cross-tenant suite | CrossTenantIsolationTest across city and merchant scopes | C-02..C-10 | done for city scope: five city-anchored roles try all 12 parameterised admin routes against another city (404, nothing changed); lists exclude the other city; request input cannot widen a grant; revocation applies on the next request. The test fails if a new parameterised admin route is not added to it (CrossTenantIsolationTest, 14 tests). Merchant scope waits for merchant endpoints |
| C-13 | Approve vertex and extent caps (human) | ADR-002 open item closed | — | blocked (human) |

## Sprint 6 — design system and shells (Slice D)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| U-01 | Design tokens with measured contrast | Every text/background pair used meets WCAG 2.2 AA; results recorded | A-03 | done for web: 42 pairs measured and enforced by test (`docs/ux/contrast-report.md`); four tokens added, two restricted. Rider app now uses generated tokens |
| U-02 | `packages/ui` components and Storybook | Form, table, nav, dialog, toast, state components; axe clean | U-01 | partial: `packages/ui/src` holds only `map-view`, `problem-page`, `fault` and `map-state`; no Storybook and no toast; forms, tables and dialogs live in the apps. The 42 contrast pairs (U-01, `docs/ux/contrast-report.md`) are measured. Rider app theme not yet covered |
| U-03 | UI state matrix | Each view has loading, empty, error, forbidden, offline, validation, conflict, slow | U-02 | partial: matrix in `docs/qa/ui-state-matrix.md` (11 views x 9 states: 69 covered, 30 n/a, 0 gap), browser tests x 3 viewports in `tests/browser/state-matrix.spec.ts`, `customer.spec.ts`, `server-fetch-states.spec.ts` (server-rendered banner and API row, via a stand-in API with a mode switch) and `error-states.spec.ts` (`error.tsx`, `not-found.tsx` in all three apps, no stack or message shown) and `global-error.spec.ts` (`global-error.tsx` in all three apps, run against production builds because `next dev` shows its overlay instead). Open: offline cached reads (needs a service worker decision), toasts (no design decision) |
| U-04 | Map component | Client-only load; placeholder when `MAP_STYLE_URL` empty; no default centre; attribution | U-02 | done (code); tiles not hosted. MapLibre 6.16.0 + PMTiles (ADR-015): placeholder when no style, no default centre, fit to zone bounds, attribution, polygon drawing on the admin zone create and edit forms (pointer only; the GeoJSON field is the keyboard path). Open: hosted tiles (needs launch city and E-07) |
| U-05 | Admin: login, overview, cities, zones editor, users, audit, settings | Playwright scenarios 3–6, 9, 10 | C-11, U-04 | partial: shell, sign-in, cities, audit, GeoJSON zone create/edit/status, exclusions and read-only system settings are available. Chromium verifies keyboard confirmation, stale edit handling, settings errors and live coverage changes. Map drawing is built (2026-11-14) and works once tiles are hosted. Users done 2026-11-14 as "Roles and access" (`/access`): staff directory, grant and revoke on new APIs `GET /admin/users`, `GET /admin/roles`, `POST /admin/users/{user}/roles`, `DELETE /admin/users/{user}/roles/{grant}` through `RoleGrantService` (StaffAccessTest 27 tests with data-provider cases, 8 Playwright tests x 3 viewports). Staff invitations built 2026-11-14 (ADR-016; API b8baf36, StaffInvitationTest 44 + InvitationTokenTest 4; admin screens and `/invite` page built (invitations.spec.ts, 16 tests x 3 viewports)). Staff suspension built 2026-11-14 (`users.suspend`, global admins only, ADR-005; StaffSuspensionTest 31 tests; Suspend and Reactivate on Roles and access with a reason dialog; 4 Playwright tests x 3 viewports). Not built: editing the person's name or email; see PHASE1_STATUS; staff suspension verified against the live API 2026-11-14 (tests/browser/live-suspension.spec.ts, evidence phase1-2026-11-14-live-suspension.txt) |
| U-06 | Customer: home, location, login, account, labelled previews | Playwright scenarios 1, 2, 5, 7, 8 | C-11, U-04 | partial: home with a live service banner, `/login`, `/location` and a signed-in `/account` (name and email from `GET /api/v1/me`, sign-out, loading, error, empty and signed-out states; saved addresses, orders and payments listed as not available yet) are built; `tests/browser/customer.spec.ts` covers scenarios 1, 2, 5 (customer half), 7 and 8 (63 passed, 3 skipped as text only, no saved output; the 3 are the fixme for the live admin half of scenario 5, in `live-coverage.spec.ts`). `/account` also has a "Change your name" form on `PATCH /api/v1/me` (`profile-and-setup.spec.ts`, 19 tests x 3 viewports); no map pin, address entry or saved addresses (no provider chosen) |
| U-07 | Merchant shell | Scoped, no fabricated data, disabled actions labelled | C-11, U-02 | partial: overview shell and `/login` built; no merchant account exists to sign in with, and branch data waits for its API |
| U-08 | Rider shell | Flutter tests in spec 10.3; no location permission request | A-09, B-08 | partial: sign-in screen and API client against the B-08 token endpoints, sign-out, rider name from `/rider/me`; 47 Flutter tests (fake client and a loopback HTTP server). Token kept in memory only. Never run on a device or against the real API; needs `--dart-define=API_BASE_URL=...` |
| U-09 | Screenshots at 360/375, 768, 1440 from running apps | Saved under `docs/qa/evidence/` | U-05..U-08 | done 2026-11-14: 76 web images under `docs/qa/evidence/screens/<app>/` from `tests/browser/screenshots.spec.ts` (opt-in, `SCREENSHOTS=1`); rider images regenerated; API mocked, see `docs/PHASE1_STATUS.md` |
| U-10 | Select tile provider (human) | ADR-007 open item closed, or placeholder accepted for Phase 1 | ADR-015: self-hosted PMTiles chosen 2026-11-14; maplibre-gl 6.16.0 and pmtiles 4.4.1 wired; `map.spec.ts` draws a real map (18 passed). Hosting the archive waits for the launch city and E-07 | done (code); archive hosting open |

## Sprint 7 — observability and staging (Slice E)

| ID | Story | Acceptance | Depends on | Status |
|---|---|---|---|---|
| E-01 | Structured logs with request IDs and the named events | Events from spec 11.3 emitted; no exact customer coordinates logged | A-04 | done except `outbox.failure` (no outbox worker yet) and alerting (needs E-07): JSON logs with redaction, request id on every record, StructuredLogTest (9) |
| E-02 | CSP, CORS, cookie review | Negative tests pass | B-02 | partial: API sends nosniff, frame denial, no-referrer, a deny-all CSP and `no-store` on every response, HSTS over HTTPS, and no `X-Powered-By`; CORS negatives (9 lookalike origins, wildcard, unlisted headers) and cookie attributes tested (BrowserBoundaryTest, 11 tests); CSRF checked live. Web apps send a baseline CSP and headers from `packages/config`. Still open: a nonce-based script/style/connect CSP for the web apps. Deferred by the product owner on 2026-11-14: nonces need per-request rendering, which conflicts with `cacheComponents`/partial prerendering in every app; helpers `createNonce`, `contentSecurityPolicy` and `apiOriginFrom` exist in `packages/config` (tested) but are not wired in |
| E-03 | Backup and restore script and drill | Restore into a new database; migrations and spatial data verified; evidence recorded | D-01 | done locally: backup and restore-drill tasks, drill run and passed against the development database (`docs/qa/evidence/restore-drill.txt`); no schedule, off-machine copy, encryption or fault-injection case (`docs/operations/backups.md`) |
| E-04 | Security scans in CI | Secret scan, dependency audits, container scan; findings classified | A-11 | done: gitleaks, `composer audit` and `pnpm audit --prod` already ran; new `image` job builds the API image and scans it with Trivy 0.76.2 pinned by digest (fails on fixable HIGH/CRITICAL and on secrets). First scan found 38 HIGH in `libpq` 18.6, fixed by `apk upgrade` in the Dockerfile. Findings in `docs/security/container-scan.md`. Hosted security workflow passed on `61ccc84` (secret scan and three image scans; `docs/qa/evidence/x01-2026-11-14-hosted-ci-61ccc84.txt`). No Flutter dependency audit |
| E-05 | Deploy, rollback and incident runbooks | `docs/operations/` complete; rollback rehearsed | A-05 | done locally: production images (ADR-014, accepted), `deploy.md`, `runbook.md`, `docs/security/incident-response.md`; deploy, application rollback, schema rollback and restore rehearsed on a production-like stack (`docs/qa/evidence/e05-rollback-rehearsal.txt`), which found and fixed a restore bug. Not run on a real host (E-07); roles in the incident procedure unnamed |
| E-06 | Production-config test | No debug routes, `APP_DEBUG=false`, seeders refuse | A-04 | done: ProductionGuard refuses to serve in production with unsafe settings (24 tests, plus a real production-mode start that was refused); seeders already refuse; reads application settings only |
| E-07 | Staging environment (human provides hosting) | Repeatable deploy; domains satisfy ADR-011 | E-05 | blocked (human) |

## Sprint 8 — acceptance

| ID | Story | Acceptance | Status |
|---|---|---|---|
| X-01 | Run every check in `docs/qa/phase1-acceptance.md` | Real output recorded; no invented results | partial: local full check passed on 2026-11-14; also 2026-11-14: Playwright default (354 passed, 66 skipped at HEAD `c7e360f`; 267 in an earlier run) and live (1 passed), axe inside the default suite, clean-clone `setup` plus web and Flutter checks, hosted CI green on `61ccc84` (web, backend, security). text searches for hardcoded cities, coordinates, map centres, amounts, restaurant names and ETAs run (no problem found; `x01-2026-11-14-text-searches.txt`). Remaining: clean-clone `up`/`migrate`/`seed-demo`/backend tests (would collide with the running stack), manual screen-reader and keyboard review, staging (blocked on E-07, human). Evidence `docs/qa/evidence/x01-2026-11-14-*.txt` |
| X-02 | Final `docs/PHASE1_STATUS.md` | Done, partial, blocked and future separated accurately | done 2026-11-14: "Phase 1 position" lists, definition-of-done verdicts and gate verdict (not passed) at the top of `docs/PHASE1_STATUS.md`; older dated sections kept as history. Document inconsistencies listed there |
| X-03 | Phase 2 backlog | Merchant and catalogue stories with open decisions | drafted: `docs/phase2-backlog.md` (6 epics, 39 stories, 22 open decisions), awaiting product owner review |

## Human decisions needed, in order of urgency

| # | Decision | Needed before | Reference |
|---|---|---|---|
| 2 | City status values | Sprint 3 | D-07 |
| 3 | Session separation and MFA factor | Sprint 4 | B-00, ADR-011 |
| 4 | Permission matrix approval | Sprint 4 | B-01 |
| 5 | Geometry caps | Sprint 5 | C-13 |
| 6 | Tile provider, or accept placeholder | Sprint 6 | U-10 |
| 7 | Staging hosting and domain layout | Sprint 7 | E-07 |
| 8 | Android SDK and JDK install | Rider device builds | versions.md |

The launch city is deliberately not on this list. Phase 1 does not need it.
