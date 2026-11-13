# Phase 1 status

Last updated: 2026-11-13, Sprint 4 in progress (roles, permissions, audit and sign-in done; recovery, MFA and mobile tokens not started). Sprint 3 gate met; Sprint 2 gate still open.

## Summary
Sprint 1 documents await human review. The Docker stack now starts healthy and the backend checks run inside it against PostGIS and Redis. The Sprint 2 gate ("clean clone boot smoke with logs") is **still not met**: the boot was done in the working tree, not from a clean clone, and CI has never run. The product owner supplied front-end reference images; the customer home screen is built in that direction.

## Sprint 7: production images and rollback rehearsal (E-05, 2026-11-13)
Evidence: `docs/qa/evidence/e05-rollback-rehearsal.txt` (each step) and `e05-full-check.txt` (`dev.ps1 check`, exit 0; 387 backend tests).

- Production images (ADR-014, accepted by the product owner on 2026-11-13), both from `infra/docker/prod/api.Dockerfile`:
  - `api`: PHP-FPM as `www-data`, with code baked in, no development packages, production `php.ini` with OPcache, uploads off, and no Composer or tests in the image.
  - `web`: unprivileged nginx serving `public/` and passing everything else to `api:9000`; hidden files and other `.php` paths return 404.
  - Configuration comes from the environment only; the entry point caches config and routes. Checked inside the images: user, modules, ini values, and a package list without development packages.
- The probe found and fixed two logging problems before the rehearsal:
  - nginx logged every request as `GET /index.php`. It now logs the requested path with the query string cut off (ADR-013), plus the request id.
  - PHP-FPM wrote a second access log of every request. It is now off.
- Rehearsal stack: `infra/rehearsal/compose.yaml` and `infra/scripts/rehearsal.ps1`. It runs the images under `APP_ENV=staging`, with random secrets in a git-ignored `.env`, its own database and Redis, and only nginx published, on `127.0.0.1:8088`. `ProductionGuard` accepted the settings.
- Rehearsed, with a throwaway N+1 release that added a column and a table:
  - backup;
  - migrate with N+1 while N served (N kept working on the new schema);
  - switch to N+1;
  - roll the migration back with N+1;
  - switch back to N;
  - forward again, then recover by restoring the backup;
  - a restore with a wrong checksum was refused.
- Found and fixed: the restore used `pg_restore --clean`, which left behind a table created after the backup while the migration ledger said it did not exist, so the next deploy would have failed. The restore now drops and recreates the database. After the fix, N+1 deployed again cleanly and the audit log was still append-only.
- Also learned: undoing a migration deletes whatever the new release wrote into what it added. The runbook says so and prefers a forward fix.
- `ProductionGuard` does not block `migrate:rollback` when settings are safe; no change needed.
- CI: the `image` job now scans three images (development API, production API, production web) as a matrix, with matrix values passed through `env`. All three scan clean locally with the job's own scripts.
- Docs: `docs/operations/deploy.md`, `docs/operations/runbook.md`, `docs/security/incident-response.md`.
- Not done:
  - no real host (E-07);
  - no TLS in the rehearsal, and trusted proxies are not configured, so HSTS is never sent behind a proxy yet;
  - no read-only root filesystem;
  - no queue worker or scheduler (none needed in Phase 1);
  - no `APP_KEY` rotation procedure;
  - incident roles are unnamed;
  - the web apps and the rider app have no deployment procedure.

## Sprint 7: container image scan (E-04, 2026-11-13)
Evidence: `docs/qa/evidence/sprint7-container-scan-check.txt` (scan runs) and `sprint7-container-scan-full-check.txt` (`dev.ps1 check`, exit 0; 387 backend tests). Policy and findings: `docs/security/container-scan.md`.

- New `image` job in `.github/workflows/security.yml`: builds the API image and scans it with Trivy, failing on any HIGH or CRITICAL vulnerability that has a fix and on any secret in the image. Runs on push, pull request, weekly and on demand, with the workflow's existing read-only token; the scanner gets only read-only mounts of the image tarball and `.trivyignore`.
- Trivy runs from `aquasec/trivy@sha256:8f6bb947…` (0.76.2, an immutable release), not `aquasecurity/trivy-action`. The action's tags were rewritten to credential-stealing code in March 2026 (GHSA-69fq-xp46-6x23), and it fetches a second action and the Trivy binary at run time; the advisory lists images referenced by digest as unaffected. The product owner approved adding Trivy; this is the same scanner without the action.
- First scan: 38 HIGH findings, all in Alpine's `libpq` 18.6 (PostgreSQL client library), fixed in 18.7. The local image had 18.6 only because Docker reused a cached layer. Fixed by running `apk upgrade` first in the Dockerfile; the rebuilt image scans clean at every severity, with no secrets. The local stack now runs 18.7.
- Checked with the job's own script and GitHub's shell flags: the fixed image passes, the old image fails with the 38 findings, an image with a planted fake GitHub token fails with one CRITICAL secret, and a missing image fails before any scan.
- Not done: the job has not run on GitHub (not pushed). Dependabot cannot update the Trivy digest, so upgrades are manual (steps in the policy). Trivy's vulnerability database is fetched at run time and not pinned, by design. The web and rider apps are not container images and are not scanned; Flutter dependencies have no audit step; `pnpm audit --prod` skips development dependencies. A production image (E-05) will need the same scan.

## Workstation scripts verified (A-10, 2026-11-13)
Evidence: `docs/qa/evidence/a10-dev-tasks-check.txt` (each task) and `a10-full-check.txt` (`dev.ps1 check`, exit 0; 387 backend tests).

- Bug found and fixed: with output piped or redirected inside PowerShell (`.\infra\scripts\dev.ps1 up 2>&1 | Tee-Object log.txt`, or a CI runner), Windows PowerShell 5.1 turns each stderr line of a native command into an error record, and `$ErrorActionPreference = 'Stop'` made the first Docker progress line abort `up` mid-build with exit 1. `Invoke-Step` now judges a step only by the program's exit code and prints stderr lines as plain text. A failing step still fails, with and without redirection.
- Run for real with output piped: `setup`, `migrate`, `seed-demo`, `backup`, `restore-drill` (all checks PASS), `down`, `up` (all four containers healthy), `logs` (streamed, stopped by hand) and `check`. The demo rows survived `down` and `up`. `setup` changed no tracked file.
- Not done: the Makefile is unverified because `make` is not installed on this workstation; nothing in it changed.

## Sprint 5 follow-up: geometry fuzz tests (C-03, 2026-11-13)
Evidence: `docs/qa/evidence/sprint5-geometry-fuzz-check.txt` (`dev.ps1 check`, exit 0; 387 backend tests).

- ZoneGeometryFuzzTest (unit, no database): 3,000 inputs per run, a third fully random and the rest valid shapes with one to three random edits (deleted or replaced members, lists turned into maps, extra nesting, huge or negative numbers, numeric strings). Each must be rejected with `InvalidZoneGeometry` or come back as a MultiPolygon whose rings are closed, at least four positions long, inside the coordinate bounds and caps, with an exact vertex count, and unchanged when validated again. Nothing else may be thrown.
- ZoneGeometryPostgisFuzzTest (against PostGIS): 150 random polygons per run, often crossing themselves, some with a hole inside or outside and some as two overlapping polygons, posted to `POST /admin/cities/{city}/zones`. The status must be 201 exactly when `ST_IsValid` says the shape is valid and 422 otherwise; a 422 must not echo coordinates; a stored shape must be valid and `ST_Equals` what was sent.
- Both are seeded (fixed default; `FUZZ_SEED=<n>` replays a failure, and the seed is in every failure message). Besides the default, the unit test was run under seeds 1 to 30 (90,000 inputs) and the PostGIS test under seeds 1 to 15 (2,250 shapes). No defect found; no application code changed.
- Not covered: shapes near the antimeridian or the poles, and coordinates with more than six decimals in the PostGIS test.

## Sprint 7: browser boundary review (E-02, 2026-11-13)
Evidence: `docs/qa/evidence/sprint7-browser-boundary-full-check.txt` (`dev.ps1 check`, exit 0; 385 backend tests) and `sprint7-browser-boundary-check.txt` (requests against the running stack and the customer app).

- Found in the review: the API sent no browser security headers, advertised `X-Powered-By: PHP/8.4.27`, and marked personal responses such as `/me` only `no-cache`, which still lets a cache store them. CORS was already correct.
- API: `App\Http\Middleware\SecurityHeaders` runs first on every response, errors and CORS preflights included: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` (the API serves JSON, never a page), `Cache-Control: no-store, private` on `/api/*`, and `Strict-Transport-Security: max-age=31536000` only over HTTPS. `includeSubDomains` and preload are left out until the domains are chosen (ADR-011). The image sets `expose_php = Off`; confirmed gone on the live stack.
- Tests: BrowserBoundaryTest (11). Headers on 200, 401, 404, 422, health and preflight; `no-store` on `/me` and public routes; HSTS only over HTTPS; each listed origin allowed with credentials; nine lookalike or unlisted origins (`null`, a suffix trick, another port, another scheme, `127.0.0.1`, a trailing slash, upper case) get no `Access-Control-Allow-Origin` on preflight or GET; no wildcard ever; `x-evil` and `Authorization` not invited by a preflight; session cookies HttpOnly, SameSite lax, path `/`, no domain, one name per app; Secure on both cookies when HTTPS is configured.
- CSRF, checked live because the test framework disables it: sign-in from the admin origin with no token and with a forged token is refused (403, "The page expired"; the error renderer maps a CSRF mismatch to 403, not 419); with a fetched token the same request reaches the password check (401).
- Web apps: `packages/config/security-headers.mjs` gives all three apps `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, a Permissions-Policy that turns off camera, microphone, payment and USB, and turns geolocation off except on the customer app (its `/location` page uses it), and a baseline CSP: `frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'`. 4 node tests. Seen on the customer app in `next dev`, where a real delivery-area check still worked with no CSP violations. The merchant and admin apps were not started in this check.
- Not done: the web apps' CSP does not yet restrict scripts, styles or connections. Next.js needs a per-request nonce for that, which forces dynamic rendering and conflicts with `cacheComponents` in the current configs; it needs its own change. HSTS and the session cookie `Secure` flag in production are enforced by `ProductionGuard` and the proxy, but no HTTPS deployment exists to check them on (E-07). The merchant session cookie is untested because no merchant can sign in yet.

## Sprint 7: structured logs and named events (E-01, 2026-11-13)
Evidence: `docs/qa/evidence/sprint7-structured-logs-check.txt` (`dev.ps1 check`, exit 0; 374 backend tests).

- Log format: the `single` channel (the default) and a new `json` channel (stderr, for containers; select with `LOG_STACK=json`) write one JSON object per line through `App\Support\Logging\UseStructuredLogs`. A processor redacts credentials and exact-position keys (`latitude`, `lng`, `point`, ...) in every record, using the audit log's word list.
- Events from spec 11.3: `http.request` (method, route template, status, duration, user id; never the path or query string; level error for 5xx), `rbac.denied` (user, permission, scope; logged inside `AccessChecker`, so it also covers refusals the API answers with 404), `zone.validation_failed` (machine reason only, never coordinates), `zone.activated`, and every audited change (`city.updated`, `zone.status_changed`, ...) logged under its action name with ids only, after the transaction commits. `auth.login` already existed. Every record carries `request_id`.
- Tests: StructuredLogTest (9), including that a serviceability check leaves its position nowhere in the log and that a rolled-back audit entry is never logged.
- Not done: `outbox.failure` (no outbox worker exists in Phase 1, so nothing can fail yet); alerts for 5xx rate, DB down, queue depth, backup failure and disk (need a staging host and a monitoring service, E-07); tracing beyond the request id; log shipping and retention.

## Sprint 4, fifth slice: rider app sign-in (2026-11-13)
Evidence: `docs/qa/evidence/sprint4-rider-token-check.txt` (`dev.ps1 check`, exit 0; 365 backend tests) and `sprint4-rider-token-smoke.txt` (ten real requests against the running stack).

- `POST /api/v1/auth/mobile/token` issues a bearer token to an approved rider on a named Android device. The token carries one ability (`rider:profile`), expires after 60 minutes and is stored only as a SHA-256. A wrong password, unknown address, customer, unapproved rider and suspended account all get the same 401; five failures per account and address per minute give 429.
- `POST /api/v1/auth/mobile/token/refresh` swaps a valid token for a new one on the same device and deletes the old one in the same transaction. `DELETE /api/v1/auth/mobile/token` deletes the token and marks the device revoked. `GET /api/v1/rider/me` returns the rider's own profile.
- `EnsureRiderToken` re-reads the rider's approval, the account status and the device on every request, so suspension or device revocation applies immediately.
- Tokens and browser sessions are kept apart in both directions: the `sanctum` guard accepts bearer tokens only, so a browser session cannot reach a rider route, and a rider token is refused on every browser and admin route (tested with a rider who also holds `platform_owner`).
- `personal_access_tokens` migration added with ULID owner columns (the package's own assumes integer ids).
- RouteCoverageTest now requires each non-public route to sit behind exactly one guard: session or rider token.
- Tests: RiderTokenTest (13).

Not done: there is no way to become an approved rider except by editing the database (onboarding and approval are Phase 2), so no real rider can sign in yet. An expired token cannot be refreshed, so a rider idle for an hour signs in again; there is no separate long-lived refresh token. The Flutter app has no sign-in screen and stores no token. iOS is refused. Device attestation ("verified device" in the spec) is not implemented: a device is whatever the client names itself.

## Sprint 4, fourth slice: password recovery; security fixes to the guard and backups (2026-11-13)
Evidence: `docs/qa/evidence/sprint4-recovery-check.txt` (`dev.ps1 check`, exit 0; 352 backend tests), `sprint4-password-recovery-browser.txt`, and the re-run `restore-drill.txt`.

Password recovery (B-03):
- `POST /api/v1/auth/password/forgot` always answers 202 with the same body. For an active account it emails a link to the app that asked. The token is 256 random bits; only its SHA-256 is stored. One live link per account, valid 60 minutes, usable once; at most three emails per address in 15 minutes, counted for unknown addresses too.
- `POST /api/v1/auth/password/reset` gives one 422 message for an unknown, used or expired link and for a suspended account. Success writes an audit row and rotates the remember token.
- The token travels in the URL fragment (`/reset-password#token=...`), which browsers do not send to servers, and the page removes it from the address bar after reading it.
- Each web app has `/forgot-password` and `/reset-password`, and the sign-in page links to the first.
- Run end to end with a real email captured by Mailpit. That run found a bug: in development the page read the token twice and reported a missing link the second time. Fixed.
- Not done: existing sessions are not ended when a password is reset (sessions live in Redis and are not indexed by user); the email is plain text with no brand; mail is sent during the request, not queued; no real mail provider is configured or tested.

Security fixes from a review of commit `9525611`:
- The production guard no longer handles secret values at all: `read()` reduces the app key, demo password and database password to yes/no facts, so none can appear in an argument, exception or stack trace. It also switches debug off before failing.
- It is now deny-by-default: it applies to every environment except `local` and `testing` (so staging, or a mistyped name, is guarded) and to console processes such as queue workers, except a short list of build and repair commands. Checked for real: production and staging servers with debug on answered a plain 500 with no debug page and no secret; a production queue worker was refused; `migrate:status` was allowed.
- Every rule fails closed on a missing value (`APP_DEBUG` must be explicitly false).
- Backups: the dump is created with owner-only permissions and the copies inside the container are deleted after use. The backup task records a SHA-256 beside the file and the drill refuses to restore anything that does not match it (checked by tampering with the recorded value).
- Review suggestions not adopted, with reasons: backup files are still not encrypted (no key management exists yet; stated in `docs/operations/backups.md`); the drill still runs as the database's own superuser inside the same local server, not as a restricted role in a separate container (acceptable for a local drill of a self-made backup, not for a real environment).

## Sprint 7 items done early: backup drill and production guard (2026-11-13)
Evidence: `docs/qa/evidence/restore-drill.txt` and `docs/qa/evidence/sprint7-ops-check.txt` (`dev.ps1 check`, exit 0; 321 backend tests).

- Backup and restore drill (E-03): `dev.ps1 backup` writes a `pg_dump` of the application database to the git-ignored `infra/backups/`. `dev.ps1 restore-drill` restores the newest backup into a new scratch database and passes only if row counts match for every table, the migration ledger is identical, every stored shape is byte-identical and valid, the spatial indexes exist, the audit log still refuses UPDATE and DELETE, and a serviceability lookup returns the same zone from both databases; then it drops the scratch database. Run for real against the development database: passed, 31 tables, scratch database removed, source untouched. Details and limits in `docs/operations/backups.md`.
- What that does not cover: it has only been run on a local database of a few dozen rows; no fault was injected, so it is not shown to catch a bad restore; there is no schedule, off-machine copy, encryption or point-in-time recovery; Redis, uploaded files and `APP_KEY` are not backed up; the Make targets are unrun.
- Production guard (E-06): when `APP_ENV=production` the application refuses to serve requests if debug is on, the app key is missing, the API or any app origin is not https, session cookies are not Secure, HttpOnly and SameSite lax or strict, CORS has a wildcard, pattern or http origin, two apps share an origin, hashing is not Argon2id, demo accounts are enabled, or the database password is empty or a known placeholder. Every problem is listed in the log; the caller gets a plain 500. Console commands are exempt so an operator can fix the environment. Tests: ProductionGuardTest (21 cases), ProductionConfigTest (3). Checked for real by starting a production-mode server with the local settings: it answered 500 and logged six reasons, while the normal local server kept working.
- What the guard does not cover: it reads application settings only. It cannot see TLS termination, the proxy, firewall rules, whether the proxy logs request bodies (ADR-013), or backups. Queue workers and other console processes are not guarded. This closes the gap noted earlier that nothing enforced the Secure cookie flag.

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
