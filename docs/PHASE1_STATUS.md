# Phase 1 status

Last updated: 2026-11-13, Sprint 4 in progress (roles, permissions, audit and sign-in done; recovery, MFA and mobile tokens not started). Sprint 3 gate met; Sprint 2 gate still open.

## Summary
Sprint 1 documents await human review. The Docker stack now starts healthy and the backend checks run inside it against PostGIS and Redis. The Sprint 2 gate ("clean clone boot smoke with logs") is **still not met**: the boot was done in the working tree, not from a clean clone, and CI has never run. The product owner supplied front-end reference images; the customer home screen is built in that direction.

## Sprint 4, third slice: sign-in screens and demo accounts (2026-11-13)
Evidence: `docs/qa/evidence/sprint4-signin-ui-check.txt` (`dev.ps1 check`, exit 0; 202 backend tests), plus `admin-login-refused.jpg` and `admin-signed-in.jpg`.

- `packages/session`: a hand-written browser client for the session endpoints, a shared sign-in form and a "signed in as" control. It stands in for the generated API client (backlog C-11) and should be replaced by it.
- Each web app has a `/login` page and shows the live session state in its header: "Sign in", or "Signed in as ..." with a sign-out button. The answer comes from the API on every page load.
- `DevelopmentSeeder` creates `demo-owner@example.test` (platform owner) and `demo-customer@example.test` when `DEMO_PASSWORD` is set in the local `.env`. No password is in the repository. The seeder still refuses to run outside local and testing.

Checked in a real browser on the admin app, against the running API: the demo customer is refused with the generic message, the password field is cleared and focused; the demo owner signs in and the header shows the name; sign-out returns to "Sign in". That exercised the CSRF token, the per-app cookie and CORS from an actual page.

Not checked: the customer and merchant sign-in pages in a browser (they build and share the same form); the rate-limit, network-error and unavailable messages on screen; keyboard-only use and a screen reader; phone width for `/login`; dark theme.

Known gaps: pages are not protected yet, so a signed-out visitor still sees each app's shell (there is nothing private in them yet). The `next` return path is honoured only for same-site paths, but nothing sets it yet. There is no merchant demo account, so the merchant app cannot be signed in to.

## Sprint 4, second slice: sign-in (2026-11-13)
Evidence: `docs/qa/evidence/sprint4-auth-check.txt` (`dev.ps1 check`, exit 0; 201 backend tests) and `docs/qa/evidence/sprint4-auth-smoke.txt` (12 real requests against the running stack).

Routes now registered (seven in total, each in the route inventory test and in `backend/openapi/openapi.yaml`): `GET /health/live`, `GET /health/ready`, `GET /api/v1/auth/csrf`, `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`.

How it behaves, each point tested:
- **One session per app.** The app is identified by the browser's Origin header matching a configured app URL exactly. Each gets its own cookie (`fd_customer_session`, `fd_merchant_session`, `fd_admin_session`), and a session created on one app is refused on another. Requests with no known origin get no session.
- **Who may sign in where.** Customer app: any active account. Admin app: only holders of a live staff role. Merchant app: only active staff of a merchant. Entitlement is re-checked on every request, so a revoked role or suspended account loses its session on the next request.
- **No account discovery.** A wrong password, unknown address, suspended account and not-entitled account give the identical 401. Registration gives the identical answer for a new and an already-registered address.
- **Registration** is off unless `FEATURE_PUBLIC_REGISTRATION` is on, is offered only on the customer app, and always creates a customer whatever the request says.
- **Throttling:** five failures per account and address per minute.
- **Production gate:** the admin app refuses all sign-in when `APP_ENV=production`, because two-step verification is not built.
- **Errors:** one envelope with a request ID in the body and header; no internals even with debug on.
- **Passwords:** Argon2id (confirmed on a real stored hash).

Verified by real requests only (the test framework switches CSRF checking off): sign-in without the CSRF token is refused; the session cookie is HttpOnly and SameSite=Lax; an unknown origin gets no CORS permission; a customer session survives an attempt to use it on the admin app.

Design notes that differ from the letter of earlier documents:
- ADR-011 said "own cookie and own guard per app". There is one guard; separation is enforced by the per-app cookie plus a surface value stored in the session and checked on every request.
- The CSRF token is read from `GET /api/v1/auth/csrf` and sent as a header, not taken from Sanctum's `/sanctum/csrf-cookie`, because that cookie's name would be shared by all three apps. That route is not registered.
- Registration answers 202 without signing the user in, to avoid revealing whether an address is taken.
- Minimum password length is 10 characters (my choice; no policy was specified).

Not done: the session cookie is not marked Secure locally (plain HTTP); `SESSION_SECURE_COOKIE=true` must be set wherever HTTPS is used, and nothing enforces that yet. Email verification, password recovery, `PATCH /me`, the rider's mobile token, the TOTP step, the first-owner bootstrap command, policies on admin routes (none exist yet), the web apps' sign-in screens, and the generated TypeScript client. The Sprint 4 gate is not met.

## Sprint 4, first slice: roles, permissions and audit (2026-11-13)
Evidence: `docs/qa/evidence/sprint4-rbac-check.txt` (`dev.ps1 check`, exit 0; 164 backend tests).

Decisions accepted by the product owner on 2026-11-13: separate session cookie and guard per web app, TOTP as the second factor, and the permission matrix as proposed (ADR-011, `docs/security/permission-matrix.md`).

Built, with no HTTP routes yet (the route list is still the two health routes):
- `backend/config/rbac.php`: the role and permission catalogue, written to the database by `RbacSeeder` (idempotent, runs in every environment). A test keeps the dangerous columns of the matrix equal to the seeded data.
- `AccessChecker`: answers "may this user do this, here?". Deny by default; a revoked grant or suspended user is refused on the next check because nothing is cached.
- `RoleGrantService`: the only way to grant or revoke a role. Refuses self-grants, `platform_owner`, roles at or above the grantor's own, scopes outside the grantor's own, scopes the role does not support, scopes that do not exist, and duplicates. Each grant or revoke writes its audit row in the same transaction; a denied attempt stores nothing.
- `AuditLogger`: redacts credentials, tokens, one-time codes, encrypted columns and exact positions at any depth, and stores a keyed hash of the IP, never the address.

Process note: for this slice the tests were written straight after the code, not before it. AGENTS.md rule 5 asks for test-first on authorization.

Not done in Sprint 4 yet: sign-in, registration, logout, `/me`, password recovery, throttling, mobile tokens, the MFA step, the error envelope, policies bound to routes, the route-coverage test, a bootstrap command for the first owner, and the concurrent grant/revoke race test. The Sprint 4 gate is not met.

## Sprint 3: database (2026-11-13)
Evidence: `docs/qa/evidence/sprint3-check.txt` (`dev.ps1 check`, exit 0; 133 backend tests in the container against PostGIS).

Migration inventory (spec 5.2 sequence → file):

| Seq | File | Tables |
|---|---|---|
| 001 | `2026_11_13_000001_enable_postgis` | PostGIS extension |
| 002 | `0001_01_01_000000_create_users_table` (framework file, merged) | users, password_reset_tokens, sessions |
| 003–004 | `..._000003_create_rbac_tables` | roles, permissions, role_permissions, user_role_scopes |
| 005 | `..._000005_create_devices_table` | devices (`personal_access_tokens` arrives with Sanctum in Sprint 4) |
| 006–008 | `..._000006_create_city_and_zone_tables` | cities, city_settings, delivery_zones, coverage_exclusions |
| 009–012 | `..._000009_create_merchant_and_rider_shells` | merchants, branches, merchant_staff, merchant_branch_access, riders, rider_city_access, rider_vehicles, customer_profiles, saved_addresses |
| 013–016 | `..._000013_create_consent_audit_outbox_flag_tables` | privacy_consents, audit_logs, outbox_events, feature_flags |
| 017 | none | Throttling uses the rate limiter; `mfa_factors` waits for ADR-011 |

What the database now enforces, each with a test: valid and non-empty zone geometry (a self-intersecting polygon is rejected), boundary points count as inside, one live role grant per user/role/scope, global grants carry no scope ID, an append-only audit log (UPDATE, DELETE and TRUNCATE all refused by trigger), lower-case emails, one default address per user, and known status values everywhere.

Decisions taken without sign-off, all reversible by a one-line constraint change: city statuses (draft, active, paused, archived); user statuses (active, suspended); merchant statuses (pending, active, suspended); rider statuses (pending, approved, suspended); vehicle type limited to motorcycle; a landmark is mandatory on saved addresses (master plan).

Problem found and fixed: inside the container the test suite was connected to the **development** database, because the container's environment outranks `phpunit.xml`. The first run executed `migrate:fresh` there. The development database had never been migrated and held no data, so nothing was lost. `tests/bootstrap.php` now forces the test settings, and the base test case refuses to start unless the database name ends in `_test`.

Not done in Sprint 3: models and factories exist only for User and City; the fuzz test of the geometry validator against PostGIS; an EXPLAIN check that serviceability uses the GiST index (there is no serviceability query yet). Without Docker the backend suite can no longer run, because the database tests need PostgreSQL.

Sprint 3 gate (migrate fresh/rollback and PostGIS tests green): met.

## Sprint 2: verified by running it
Evidence: `docs/qa/evidence/sprint2-check-7.txt` (`.\infra\scripts\dev.ps1 check`, exit code 0; earlier runs are kept beside it).

| Check | Result |
|---|---|
| Web lint, type check, production build (3 apps) | Pass |
| Backend format (Pint) | Pass |
| Backend static analysis (Larastan level 6) | Pass, no errors |
| Backend tests | 202 passed, 628 assertions, inside the `api` container against PostGIS |
| Stack boot | api, postgres, redis, mailpit all healthy |
| `/health/ready` | 200 with database and Redis up |
| Design token tests | 7 passed |
| Flutter analyze | No issues |
| Flutter tests | 7 passed, 2 skipped (the opt-in render test) |
| `composer audit`, `pnpm audit --prod` | No known vulnerabilities |
| Customer app served by `next start` | HTTP 200 with the scaffold page |

What the tests cover: liveness body; readiness hidden from public addresses and limited to up/down flags; framework default `/up` removed; no ordering or restaurant routes; the route list equals exactly the two health routes; domain modules exist and do not import the HTTP layer or each other's internals; rider toggle disabled; rider manifest requests no location permission; money arithmetic stays in integers, rejects mixed currencies and throws on overflow; coordinates are bounds-checked and GeoJSON positions are read longitude-first.

## Sprint 2: written but never run
| Item | Why unverified |
|---|---|
| `dev.ps1 up`, `down`, `migrate`, `logs`, and `setup` end to end | The stack was started with the same Compose command the script uses, but these script tasks themselves were not run |
| `Makefile` | `make` not installed |
| `.github/workflows/*` and Dependabot config | No remote repository |

## Sprint 2: not done
- `infra/compose.test.yaml`, `infra/terraform`, monitoring config, Storybook, Playwright: not created; they belong to later sprints or were not reached.
- `packages/*` are empty placeholders.
- Android device build not attempted (no Android SDK or JDK).

## Done ahead of Docker (second pass)
- D-06 `Money` value object (`backend/app/Support/Money`), test-first.
- `Coordinates` value object (`backend/app/Domain/Geo`), the first part of C-03.
- A-12 Laravel Boost installed as a dev dependency. Its MCP server answers an initialize request; the root `.mcp.json` registers it for Claude Code and takes effect in a new session.

## Done ahead of Docker (third pass)
- Structural zone geometry validator (`backend/app/Domain/Geo/ZoneGeometry.php`), test-first: type, nesting, ring length and closure, degenerate rings, coordinate bounds, altitude dropped, Polygon wrapped to MultiPolygon, vertex and extent caps. **It does not detect self-intersection**; that is PostGIS's job at storage time and is untested until a database exists. Nothing calls the validator yet: there is no zone endpoint.
- Caps live in `backend/config/geo.php` at the proposed values (5,000 vertices, 2 degrees), still awaiting approval (C-13).
- Design tokens package with measured contrast. The spec's palette is unchanged; four tokens were added and two restricted (see findings). The three web apps now import the generated `tokens.css`.

## Sprint 5 (started 2026-11-13)
- City API: `GET/POST /api/v1/admin/cities`, `GET/PATCH /api/v1/admin/cities/{city}`. New cities start as drafts; slug, country and currency are fixed once created; status moves only along allowed transitions; an archived city cannot change. Lists are filtered by the caller's scope in the query, and a city outside that scope returns 404. Every change writes an audit row in the same transaction. Tests: AdminCityTest (13).
- Public: `GET /api/v1/public/cities` (active only, public fields) and the serviceability endpoint (inside, outside, boundary, exclusion and time window, overlap priority, invalid coordinates, rate limit). Tests: ServiceabilityTest.
- Full backend suite: 229 passed (865 assertions) in the `api` container; full `dev.ps1 check` output in `docs/qa/evidence/sprint5-cities-check.txt`.
- Zone API (C-04): `GET/POST /api/v1/admin/cities/{city}/zones`, `GET/PATCH /api/v1/admin/zones/{zone}`. Zones start as drafts at version 1. An edit must send the version it loaded; a stale version returns 409 and changes nothing (also enforced in the UPDATE's WHERE clause). Geometry passes the structural validator and caps, then `ST_IsValid`; a self-intersecting shape is 422 and the PostGIS reason (which echoes coordinates) is not returned. Archived cities take no zones; archived zones cannot be edited. Audit rows record name, slug, status, priority and version, not the shape. Tests: AdminZoneTest (14). `dev.ps1 check` passed with 243 backend tests; output in `docs/qa/evidence/sprint5-zones-check.txt`.
- Zone status (C-05, C-06): `POST /api/v1/admin/zones/{zone}/activate`, `/pause`, `/archive`. Same edges as cities: draft to active or archived, active to paused, paused to active or archived. Activation needs `confirm: true`, an active city (read under a shared lock) and a valid stored shape; pausing needs a reason, which goes in the audit row. Every transition checks `version` and adds 1 to it. One test activates a zone through the API and sees the public serviceability answer change. Tests: AdminZoneStatusTest (8). `dev.ps1 check` passed with 251 backend tests; output in `docs/qa/evidence/sprint5-zone-status-check.txt`.
- Open point: pausing or archiving a city does not change its zones' statuses. Serviceability already ignores zones of a city that is not active, so nothing is served, but the zones still read `active` in the admin API.
- Exclusions (C-07): `GET/POST /api/v1/admin/zones/{zone}/exclusions`, `DELETE /api/v1/admin/exclusions/{exclusion}`. An exclusion has a reason, a shape and an optional window (`active_from`, `active_until`, sent with an offset, stored in UTC). The shape gets the zone checks plus `ST_Intersects` with the zone, so one that excludes nothing is refused. Archived zones take none. Exclusions are not edited. Tests show service stopping when one is added and returning when it is deleted. Tests: AdminExclusionTest (9). `dev.ps1 check` passed with 260 backend tests; output in `docs/qa/evidence/sprint5-exclusions-check.txt`.
- Discrepancy with spec 7.4, not yet decided: the spec lists zone list and create as `GET/POST /api/v1/admin/zones`; they were built as `GET/POST /api/v1/admin/cities/{city}/zones`, so the city comes from the path and is checked against the caller's scope. The single-zone routes match the spec. The spec has no routes for exclusions or for archiving a zone; the ones built are additions. Needs a decision: change the routes or amend the spec with an ADR.
- Public config (C-09): `GET /api/v1/public/config` returns the currency, display timezone, `service_open` (an active zone in an active city exists), `map_style_url` (null unless `MAP_STYLE_URL` is an https URL) and the four feature flags. The response is built from a fixed list. Tests: PublicConfigTest (5).
- Spatial index (C-08): ServiceabilityPlanTest loads 2,500 synthetic zones, runs EXPLAIN on the real serviceability statement (now the constant `ServiceabilityQuery::SQL`) and requires `delivery_zones_coverage_gix` with no sequential scan of the zones table. 2,500 is a guess at scale, not a measured launch figure.
- Oversight (C-10): `GET /api/v1/admin/audit-logs` needs `audit.read`; staff holding it for certain cities see only entries tied to those cities, so platform-level entries are hidden from them. Filters: action, subject type, subject id, actor, city. `before` and `after` are redacted again on read; the address hash is never returned. `GET /api/v1/admin/system/status` needs `system.status.read` with global scope and returns environment, debug flag, database/Redis/PostGIS up or down, city and zone counts by status, feature flags, whether a map style is set, and `geometry_caps_approved` (false until `GEO_CAPS_APPROVED` is set by a person, C-13). Tests: AuditLogAndStatusTest (7). `dev.ps1 check` passed with 274 backend tests; output in `docs/qa/evidence/sprint5-oversight-check.txt`.
- Cross-tenant suite (C-12): CrossTenantIsolationTest signs in as each of the five roles that can be anchored to a city (city manager, platform admin, dispatcher, support, finance) and aims every admin route with a path parameter at another city's city, zone and exclusion. Each attempt must return 404 without echoing the record, and a before/after snapshot of the other city's rows must match. It also checks lists, that a `city_id` in the body or query is ignored, and that revocation applies on the next request in the same session: losing one city returns 404 for it while the other keeps working, and losing the last staff role makes the admin app refuse the session (401). The attempt list is compared with the registered routes, so a new parameterised admin route fails the test until covered. Merchant scope is not covered (no merchant endpoints). `dev.ps1 check` passed with 288 backend tests; output in `docs/qa/evidence/sprint5-cross-tenant-check.txt`.
- Generated API types (C-11): `packages/api-client` holds `src/schema.d.ts`, generated from `backend/openapi/openapi.yaml` with `openapi-typescript` 7.13.0 (a pinned dev dependency; proposed to the product owner, who replied "proceed" on 2026-11-13). Regenerate with `pnpm --filter @food-delivery/api-client generate` after any change to the document. Its test regenerates in memory and fails if the committed file differs or an operation id is missing; it runs in `pnpm test`, so `dev.ps1 check` and the web workflow both catch a stale file. This is types only: there are no generated request functions, `packages/session` still calls the API by hand, and no app imports the types yet.
- Admin cities and zones screens (U-05, part): `/cities` lists the caller's cities and creates a draft city; `/cities/[id]` edits name and timezone, moves the city's status with a confirmation step, lists zones, adds a zone from pasted GeoJSON, and activates, pauses (with a reason) or archives a zone, sending the loaded `version` each time. Shared pieces: `components/console-shell.tsx` (the frame, now used by the overview too), `components/ui.tsx`, and `apiGet` / `apiSend` in `packages/session` typed with `@food-delivery/api-client`. Signed-out, forbidden, not-found, validation and conflict answers each have their own message. Run in a real browser against the API from sign-in to a public serviceability answer of true: `docs/qa/evidence/sprint6-admin-cities-browser.txt`. `dev.ps1 check` passed (288 backend tests): `docs/qa/evidence/sprint6-admin-cities-check.txt`.
- Limits of those screens: there is no map (no provider is chosen, ADR-007), so a zone's shape is pasted as GeoJSON and cannot be seen or edited afterwards; a zone's name, priority and shape cannot be edited in the UI; exclusions have no screen; lists stop at 100 with no paging; after a status change the confirmation message is lost because the panel reloads; there are no automated browser tests (Playwright is not set up), so the browser run above was manual and is not repeatable by CI. Keyboard, screen reader, phone width and dark theme were not checked.
- Customer delivery-area check (U-06, part): the home banner is rendered on the server from `GET /api/v1/public/config` on every request and has three states: areas open (with a link to the check), nothing open, and could not check. It never says open unless the API did. `/location` takes a latitude and longitude, validates them in the browser to the API's own rules, and shows the serviceability answer with the zone and city names. An optional button fills the numbers from the device; the permission is asked only when it is pressed. The position is not stored in the browser. Run in a real browser against the API: `docs/qa/evidence/sprint6-customer-location-browser.txt` and `customer-location-375.jpg`. `dev.ps1 check` passed: `docs/qa/evidence/sprint6-customer-location-check.txt`.
- Limits: the spec asks for an address with a landmark and a draggable map pin; there is neither, because no map or address-lookup provider is chosen and no API exists for saving addresses, so a customer must know their coordinates or share the device location. That makes this a working check but not yet a usable customer flow. The device-location button, the closed and unreachable banner states, and the nothing-open answer were not exercised in the browser.
- **Security fix (ADR-013):** the serviceability check was `GET` with the position in the query string, as the spec writes it. A review of commit `87669f7` flagged that URLs are written to access logs by servers and proxies. It is now `POST /api/v1/locations/serviceability` with the position in the JSON body; the GET form answers 405 and a POST with the position in its query string is refused with 422. The customer screen sends the body and rounds a device-supplied position to four decimals. The browser's request log showed the new call as a POST with no query string. No deployed environment existed, so no real customer position was exposed. Tests: ServiceabilityTest (two added).
- Route coverage (B-10): RouteCoverageTest requires every route to be either on an explicit public list or behind the session guard, every admin path to be behind the admin-surface guard in the right order, every public API route to be rate limited, and sends an anonymous request to every guarded route expecting 401. It found no gaps. It does not prove each action checks the right permission; that stays with the per-endpoint and cross-tenant tests.
- Admin audit log screen: `/audit` lists entries newest first with action and record-type filters, a "load older" button, and the redacted before/after values. Run in a browser: six real entries shown, the action filter narrowed to one, and a non-matching filter showed the empty state. Staff with city-only access and the load-older button were not exercised (the log has fewer than 25 entries). `dev.ps1 check` passed with 298 backend tests: `docs/qa/evidence/sprint6-audit-and-post-check.txt`.
- Not done: a parallel race test for zone edits; a map-based zone editor; screens for exclusions, users and settings.

## Fourth pass: Docker and front-end
- Stack validated, built and started. Two bugs found only in the container and fixed, each with a test: health routes ran through session middleware, so liveness needed the database; and `artisan serve` dropped the container environment, so the app silently used `backend/.env`.
- Host ports are configurable; a native PostgreSQL on this machine holds 5432.
- Front-end direction recorded in `docs/ux/design-direction.md`. Customer home built: warm palette, location and search bar, status banner, category rail, restaurant area with an empty state and a labelled layout preview, phone tab bar. Everything that is not built is disabled and says so. Checked at 375 and 1440 wide with no horizontal overflow; screenshots in `docs/qa/evidence/customer-home-*.jpg`.
- Admin console shell built (`apps/admin-web`): dark navigation rail with every section from spec 8.5 marked "Not built yet" or "Later phase", a city indicator with no default, a setup-readiness row, and a dashed three-panel layout preview of live dispatch with no orders, riders or figures. One value is live: the page asks the API's liveness route from the server on each request. Checked at 375 and 1440 wide with no horizontal overflow; screenshots in `docs/qa/evidence/admin-overview-*.jpg`. The "API is not reachable" branch was not exercised, and the navigation disclosure on phones was not opened in the check.
- Merchant portal shell built (`apps/merchant-web`): side navigation with the six sections from spec 8.3, branch indicator reading "None assigned", a disabled Pause store button with its reason, an empty order board with New, Preparing and Ready for pickup lanes, and stock and hours panels. No sales or order figures. Phones and tablets get a three-item bottom bar. Checked at 375, 768 and 1440 wide with no horizontal overflow; screenshots in `docs/qa/evidence/merchant-overview-*.jpg`. Keyboard, screen reader and dark theme not checked.
- Rider app home restyled (`apps/rider-flutter`): dark rounded header with the Go online toggle (disabled, with its reason), an earnings line reading "Nothing to show yet", an empty New jobs panel, and a four-item bottom bar with only Home enabled. Its colours are now generated from the shared tokens (`lib/core/tokens.g.dart`), and a token test fails if that file is stale. 7 widget tests pass, including no invented money or distance and no overflow at 320 wide. Rendered to `docs/qa/evidence/rider-home-390-*.png` by an opt-in test using Flutter's test renderer. **Never run on an Android device or emulator** (no Android SDK on this workstation), so real-device layout, fonts, safe areas and TalkBack are unverified.
- Not checked on the customer home: keyboard walkthrough, screen reader, automated accessibility scan, 768 width, dark theme beyond a glance.

## Findings during Sprint 2
- The spec's starting palette has three contrast gaps. `accent-500` is 2.80:1 on white, too low for text. `border` is 1.23:1, too low to outline a control. White text on the dark-theme brand colour is 1.86:1. Added `accent-text`, `border-strong`, `on-brand`, `on-danger`; `accent-500` and `border` are now decorative only. A test fails if any approved pair drops below its threshold.
- Laravel Boost registers `POST /_boost/browser-logs` in the local environment. Turned off with `BOOST_BROWSER_LOGS_WATCHER=false`; the route list is back to the two health routes.
- Laravel registers a file-serving route and a file **upload** route (`storage/{path}`) by default. Disabled in `config/filesystems.php`; the route inventory test now fails if any unlisted route appears.
- The MinIO image is no longer on Docker Hub. Object storage is left out of the stack; ADR-012 is open.
- `create-next-app` 16.4.6 selects TypeScript 5 and ESLint 9, so the TypeScript 7 question from Sprint 1 is deferred.
- Next.js 16 ships its own agent guidance per app (`apps/*/AGENTS.md`) saying its APIs differ from older versions.
- The Android application ID is the placeholder `com.example.rider_app`. It must change before any release and depends on the brand decision.

## Sprint 1 (unchanged)
Done and awaiting review: versions, ADR-000 to ADR-011, diagram, phase boundaries, threat model, permission matrix, backlog, acceptance tests. Open items: ADR-011 (session separation, MFA factor, email verification), ADR-002 caps, ADR-007 tile provider, city status values, permission matrix questions.

## Blocked on a human
| Item | Needed for |
|---|---|
| Install Docker Desktop | Finishing Sprint 2, all of Sprint 3 |
| City status values (ADR-000 item 8) | Confirming the provisional values used in Sprint 3 |
| ADR-011 open items, permission matrix | Sprint 4 |
| Object storage choice (ADR-012) | First file-storing feature |
| Android SDK and JDK | Rider device builds |

## Rollback
Commit `b38e8e3` holds Sprints 1 and 2 up to the first check run. Commit `28f6430` adds the value objects and Boost. The third pass (geometry validator, design tokens) is uncommitted.

## Next steps
1. Human: install Docker Desktop.
2. Agent, once Docker exists: validate and start the stack, fix what breaks, run backend tests in the container, record the boot logs, then close the Sprint 2 gate.
3. Agent: commit the third pass, on request.
