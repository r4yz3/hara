# Phase 1 status

Last updated: 2026-11-14. Phase 1 has working browser authentication, rider token endpoints, and city, zone and serviceability APIs. Sprint 8 acceptance remains open.

## November 14 customer account page and Playwright scenarios (U-06)

- Built (apps/customer-web only): `/account` (`app/account/page.tsx`, `account-screen.tsx`). It reads `GET /api/v1/me` through the session client and shows the customer's own name and email, a sign-out, and loading, error (with retry) and empty ("No name on file") states. A signed-out visitor is sent to `/login?next=%2Faccount`. Saved addresses, orders and payment methods appear only under "Not available yet". The mobile Account tab on the home page now links to `/account`. No backend route, no address, order or payment feature.
- New spec `tests/browser/customer.spec.ts` (API mocked as in `state-matrix.spec.ts`, synthetic values only): scenario 1 (landing, no invented restaurants), 2 (outside zone), 5 customer half (serviceable answer shown; request is a POST and no position reaches a URL), 7 (signed-out redirect, return to a local path, three hostile return paths ignored), 8 (no overflow and main control in view on home, login, location, account), and the account states and sign-out. Axe (WCAG 2.2 AA) runs on the account and location pages.
- `test.fixme`: scenario 5 admin half (activate a city and zone, then the customer check), which needs the live API and is covered by `live-coverage.spec.ts` with `BROWSER_LIVE=1`.
- Results: `pnpm exec playwright test tests/browser/customer.spec.ts`: 57 passed, 3 skipped (the fixme at three viewports). `pnpm lint` (no warnings), `pnpm typecheck`, `pnpm test` and `pnpm --filter customer-web build` passed. The full browser suite and screenshots were not re-run by me; `/account` is not yet in `screenshots.spec.ts`.
- Rollback: delete `apps/customer-web/app/account/` and `tests/browser/customer.spec.ts`, and revert the Account tab in `apps/customer-web/app/page.tsx`.

## November 14 roles and access screen (U-05, users)

- **Found:** `RoleGrantService` existed but nothing exposed it: no route listed staff or granted or revoked a role. Built the server feature first (AGENTS.md rule 6), then the screen.
- **API** (all behind `EnsureSurfaceSession` and `EnsureAdminSurface`; controller `StaffAccessController`; OpenAPI entries and generated types updated):
  - `GET /api/v1/admin/users` needs `users.read`. Lists only people holding a live staff role, by id with a cursor. Global reach sees every grant; city reach sees only grants anchored to its cities, so global grants and other cities are absent. Support (the only role that sees addresses masked) gets `j***@domain`. Each grant carries `can_revoke`, computed by the same rules as a revoke but without writing `rbac.denied` log lines.
  - `GET /api/v1/admin/roles` needs `users.read`. The roles one of the caller's own grants may hand out, with `global`, `all_cities` or `city_ids`. Empty for a caller who may assign nothing. `platform_owner` and onboarding roles never appear.
  - `POST /api/v1/admin/users/{user}/roles` and `DELETE /api/v1/admin/users/{user}/roles/{grant}` call `RoleGrantService::grant` and `revoke` (transaction plus audit rows `role.granted` and `role.revoked`). The person and the grant must be inside the caller's `users.read` view, else 404 (also when the caller lacks `users.read`, matching ADR-005). Refusals from the service map to 403 (not permitted, not assignable, own account), 404 (no such city or grant), 409 (already granted, inactive account) and 422 (unknown role); the reason stays in the log. `AccessChecker::allows` and `RoleGrantService::assertMayAssign` gained an optional `$log` flag; behaviour is unchanged when it is omitted.
  - Not built: bringing a new person in (needs an invitation by email; an email-lookup endpoint would be an account-existence oracle), suspending a user, and global roles on the `merchant_*`, `rider` and `customer` catalogue entries (never grantable here).
- **Screen** `/access` (`apps/admin-web/app/access/`): the sidebar item is live. Shows people and roles from the API only; loading, empty, error with retry, forbidden with a way back, masked addresses, "You can view access but not change it" for roles that may not assign, load more by cursor. Removal opens a modal confirmation that names the role, scope and person, warns when it is their last staff role, shows an API refusal or an offline error inside the dialog and can be repeated. The grant form lists only the people other than the signed-in person, only roles and places the API offers, validates before sending, focuses the first invalid field, and reports 403, 409 and offline answers.
- **Tests:** `backend/tests/Feature/Admin/StaffAccessTest.php` (27 tests with data-provider cases): sessions and admin-app only; dispatcher, finance and support refused; list shows staff only, city isolation, masked support, paging, `can_revoke`; offered roles; grants by platform admin and city manager; a city manager cannot climb (8 roles, in scope and globally); even a platform owner cannot grant `platform_owner`, onboarding roles, `rider` or `customer`; a platform admin cannot create another platform admin; no self grant or revoke; people outside the caller's view, malformed and upper-case ids are 404; input validation; duplicates and suspended accounts are 409; revoke refused for a peer, for global grants outside view, and for `platform_owner` even by an owner; a grant must belong to the person in the path; re-grant after revoke; audit rows written. `CrossTenantIsolationTest` now also attacks both new mutating routes with another city's staff member and grant and compares a snapshot, and its route-coverage check would have failed until they were added. `RouteInventoryTest` lists the four routes; `RouteCoverageTest` needed no change (none is public). Browser: eight `access:` tests in `tests/browser/state-matrix.spec.ts` (slow load, empty, 500 then retry, 403, masked viewer, confirm and cancel and single DELETE, refused and offline removal repeated, grant form validation, scope and 409, roles failing to load), 24 runs across three viewports, each with an axe check where marked.
- **Commands and results:** `docker compose -f infra/compose.yaml --env-file .env exec -T api php artisan test --filter='StaffAccessTest|CrossTenantIsolationTest|RouteInventoryTest|RouteCoverageTest|OpenApiDocumentTest'` passed; `pnpm --filter @food-delivery/api-client generate`; `pnpm exec playwright test state-matrix` (desktop project 41 passed; the access tests passed on all three projects); `.\infra\scripts\dev.ps1 check` exited 0 (523 backend tests, 34445 assertions, Pint, PHPStan, web lint, types and builds, 47 rider tests). Two first-run failures were mine: a missing `use` import in a route file and a strict-mode text locator in a test; both fixed.
- **Evidence:** `docs/qa/evidence/screens/admin/access-{360,375,768,1440}.png` (API mocked, synthetic names, from `tests/browser/screenshots.spec.ts` with `SCREENSHOTS=1`). The screen was not run against the live API in a real session.
- **Security notes:** the screen adds no way around the staff sign-in steps (it only calls the guarded admin routes). Grants take effect on the very next request (AccessChecker reads storage), and a person whose last staff role is revoked loses admin access then. There is no rate limit on the two write routes beyond the session and CSRF guards; they are staff-only and audited. A suspended person can still be listed (status shown) but cannot be given roles.
- **Files:** `backend/app/Http/Controllers/Api/V1/Admin/StaffAccessController.php`, `backend/app/Application/Commands/RoleGrantService.php`, `backend/app/Application/Queries/AccessChecker.php`, `backend/routes/api.php`, `backend/openapi/openapi.yaml`, `packages/api-client/src/{index.ts,schema.d.ts}`, `apps/admin-web/app/access/{page.tsx,access-screen.tsx}`, `apps/admin-web/components/console-shell.tsx`, backend tests above, `tests/browser/{state-matrix,screenshots}.spec.ts`, evidence images, `docs/backlog.md`, this file.
- **Rollback:** revert those files; no migration was added, so nothing to roll back in the database. Grants made while testing live only in the test database.

## November 14 screenshot evidence (U-09)

- Command (PowerShell, repository root; the spec starts the three dev servers itself, so ports 3000-3002 must be free):
  `$env:SCREENSHOTS=1; pnpm exec playwright test tests/browser/screenshots.spec.ts --workers=1`
  Without `SCREENSHOTS=1` the spec skips and writes nothing. Result: 57 passed (19 screens x 3 viewport projects).
- Output: 76 PNGs, 2.7 MB, in `docs/qa/evidence/screens/<app>/<screen>-<width>.png`. Widths are 1440, 768, 375 and 360 (the phone project writes both 375 and 360). The Next.js dev-server badge is hidden before capture.
  - customer: home, login, forgot-password, reset-password, location.
  - merchant: home, login, forgot-password, reset-password.
  - admin: overview, login, forgot-password, reset-password, security, cities-empty, cities-list, city-detail, audit, settings.
- Data: the apps are real running dev builds, but the API is replaced by route interception, as in `state-matrix.spec.ts`. Names on screen are labelled "Synthetic"; the signed-in screens (security, cities, city, audit, settings) use a mocked session and mocked answers, not a real account or password. The customer home banner comes from the existing public-config stub (service closed).
- The spec logs `OVERFLOW <screen> @<width>` if a page scrolls sideways; none was logged.
- Rider: `RENDER_EVIDENCE=1 flutter test test/render_evidence_test.dart --update-goldens` in `apps/rider-flutter` passed and rewrote `docs/qa/evidence/rider-home-390-dark.png` and `-light.png`.
- Not covered: screens that only appear after real interaction (city and zone forms with errors, exclusions, MFA setup steps); those are exercised by the browser tests but not captured. Rollback: delete `docs/qa/evidence/screens/` and `tests/browser/screenshots.spec.ts`.
- `pnpm lint` and `pnpm typecheck` pass.

## November 14 staff two-step sign-in: screens and recovery (B-09)

Evidence: `docs/qa/evidence/phase1-2026-11-14-mfa-check.txt` (`dev.ps1 check`, exit 0; 485 backend tests) and `phase1-2026-11-14-mfa-browser.txt` (`pnpm test:browser --workers=2`, exit 0; 171 passed, 3 opt-in skips). Both were run after all three agents had finished.

- **Admin screens (Sonnet subagent, reviewed):**
  - After the password, the admin sign-in shows a code step, with a recovery-code option, inline 401/422/429 messages and no double submit.
  - `/security` sets up two-step sign-in: the key shown in groups, a setup link, a confirm code, then 10 recovery codes behind an "I have saved these codes" checkbox.
  - Secrets and codes stay in component state only: never in a URL, storage or the console. A test checks this.
  - Customer and merchant sign-in are unchanged. 15 browser tests at 3 viewports.
- **Recovery (Sonnet subagent, reviewed):**
  - `POST /api/v1/admin/users/{user}/mfa/reset` (new permission `users.mfa.reset`, platform owner only) clears a staff member's factor and codes. It needs a reason, is audited, and refuses resetting yourself.
  - `POST /api/v1/me/mfa/recovery-codes` replaces all recovery codes after a current code.
  - Gate fix found by the agent: after a reset, an old session survived in local/testing. A session now records which factor it passed and ends if that factor is gone or replaced, in every environment.
  - StaffMfaRecoveryTest (31).
- **Fixed by me:** the API did not expose `Retry-After` across origins, so the sign-in screens could not show how long to wait after a 429. CORS now exposes it (BrowserBoundaryTest).
- **Not done:**
  - no QR code (no QR library added);
  - no way to turn two-step sign-in off;
  - only the sign-in flow and `/security` send an enrolment-only session to setup; other screens show the generic forbidden message;
  - city-scoped staff get 403, not 404, on the reset route, which is excluded from the cross-tenant 404 list as deployment-wide;
  - the recovery-code design still awaits product owner review.

## November 14 UI state matrix (U-03)

- Built by a Sonnet subagent and reviewed before commit. `docs/qa/ui-state-matrix.md` maps 9 views against the 9 states in spec 8.7: 48 covered by a named test, 28 not applicable with a reason, 5 gaps.
- `tests/browser/state-matrix.spec.ts` adds 33 tests at phone, tablet and desktop size, forcing 403, 409, 422, 500, slow and offline responses. The agent's final run passed 171 browser tests with 3 opt-in skips.
- Changes users see:
  - focus moves to the first field the server refused, and that field is marked invalid;
  - after a 409 on a city or zone change, a "Reload latest" button appears instead of a silent reload;
  - loading lines on admin screens are announced to screen readers;
  - the audit Apply button ignores a second press while loading.
- Gaps:
  - loading and error states of server-rendered fetches (customer home banner, admin overview API row) cannot be forced from the browser;
  - offline cached reads with a stale time need a service worker or client cache (a design decision);
  - success is an inline message, not a toast;
  - no `error.tsx` or `not-found.tsx` in any app.

## November 14 staff two-step sign-in (B-09, backend)

- Built by a Sonnet subagent and reviewed before commit: the TOTP code, the session gate and the verify step were read line by line, and pint, phpstan, the full backend suite (453 passed) and the generated-types test were re-run.
- `POST /api/v1/auth/login` for staff with a confirmed factor now answers `mfa_required` and grants nothing. `POST /api/v1/auth/mfa/verify` takes a 6-digit code or a recovery code and completes sign-in with a new session id. It uses the same generic 401 and the same 5-per-minute limit as a password.
- `POST /api/v1/me/mfa/totp` starts enrolment and shows the secret once. `.../confirm` confirms it and shows 10 recovery codes once; they are stored as keyed hashes and work once each.
- The secret is encrypted at rest. Codes cannot be replayed within a step. A clock drift of one step either way is accepted.
- Gate (`EnsureSurfaceSession`):
  - Once a factor is confirmed, every admin session must have passed it, in every environment.
  - Outside `local` and `testing`, staff without a factor may only enrol or sign out; everything else answers 403.
  - Local development keeps password-only sign-in for staff who have not enrolled.
- Audit: `auth.mfa_enrolled`, `auth.mfa_verified` (method), `auth.mfa_failed` (stage); ids only.
- Not done:
  - the admin web screens (enrolment with a QR code, and the code step at sign-in), so deployed admin sign-in works only through the API;
  - no way to disable, reset or regenerate codes, so a staff member who loses the device and the codes has no recovery path yet;
  - rotating `APP_KEY` invalidates unused recovery codes;
  - the recovery-code design awaits product owner review (ADR-011).

## November 14 rider sign-in, password reset fix and Phase 2 draft

Evidence: `docs/qa/evidence/phase1-2026-11-14-rider-signin-check.txt` (`dev.ps1 check`, exit 0; 410 backend tests, 47 rider tests).

- **Security fix (automated review of the pushed code):** a password reset ended browser sessions but not rider app tokens, so a stolen rider token outlived the reset. `PasswordRecovery::reset` now deletes the user's API tokens and marks their devices revoked in the same transaction. RiderTokenTest has a new case.
- **Second finding, fixed after the product owner chose 30 days:** a rider token could be refreshed indefinitely. One sign-in now lasts at most 30 days from the device's sign-in (`devices.created_at`). A refreshed token's `expires_at` stops at that limit; after it, refresh answers 401 `AUTH_REQUIRED` and the device is revoked. Two new RiderTokenTest cases; OpenAPI describes the limit.
- **Rider sign-in (U-08):**
  - `lib/core/api/rider_api.dart` is a `dart:io` client for sign-in, `/rider/me` and sign-out, with typed failures for 401, 422 field errors, 429, offline and not configured. It logs nothing, and the token is hidden from `toString`.
  - `lib/features/sign_in/sign_in_screen.dart` is the sign-in screen.
  - The app starts on sign-in and shows the rider's name on home, with a sign-out button.
  - Tests went from 7 to 47.
  - Not done:
    - never run on a device or against the real API;
    - the token is in memory only (persistent secure storage is a later decision);
    - sign-out is best effort, so if the DELETE fails the server token lives until it expires;
    - the home screen still lists "Sign-in and device registration" as not built;
    - an http (not https) URL on Android needs a manifest change.
- **Phase 2 backlog (X-03):** drafted in `docs/phase2-backlog.md` for product owner review.
- Work split: the rider sign-in was written by a Sonnet subagent and the Phase 2 draft by a Haiku subagent; both were reviewed and the full check run before commit.

## November 14 admin system settings

- Added a read-only settings page in the admin navigation. It reads the existing system status endpoint and shows service checks, deployment settings, feature flags and active coverage counts.
- `pnpm test:browser --workers=2` passed 27 cases with three opt-in skips. The settings cases verify 503 retry and a 403 without status data. The live desktop test loaded the page through a seeded owner session. Admin lint, typecheck and production build passed.
- Changed the console navigation, settings page and component, browser suites and documentation. Evidence is in `docs/qa/evidence/phase1-2026-11-14-admin-settings.txt` and `admin-settings-375.png`. Revert these files to remove the page. There are no API or schema changes.

## November 14 zone exclusions

- Admin zone cards now list coverage exclusions with cursor paging, add GeoJSON exclusions with optional UTC start/end, and confirm removal. Errors remain visible and failed adds keep the form values.
- `pnpm test:browser --workers=2` passed 21 default Chromium cases, with three opt-in skips. The live desktop test passed and showed that adding an exclusion removes the edited zone from the customer's result and removing the exclusion restores it. The optional timestamp fields were checked for rendering and accessibility in Chromium. Backend feature tests cover time-window behavior.
- Admin lint, typecheck and production build passed. Evidence is in `docs/qa/evidence/phase1-2026-11-14-zone-exclusions.txt` and `admin-zone-exclusions-375.png`. Changed the admin city screen, the zone exclusions component, browser suites and acceptance docs. Revert those changes to remove the UI. The existing API and schema are unchanged.

## November 14 GeoJSON zone editing

- Admin staff can open a zone and edit its name, priority and GeoJSON with the existing version precondition. On conflict, the draft stays visible and the stale save is disabled. Cancel and reopen loads the latest server version.
- `pnpm test:browser --workers=2` passed 21 cases, including axe checks for the conflict state at all three viewports. Three live cases were skipped by default. The separate desktop live coverage run saved the edit, activated version 3, and showed the updated zone name to the customer.
- Changed the admin city screen, browser suites and acceptance documentation. Revert these changes to remove the editor. No API or schema change was needed. Map drawing and exclusion management remain open.

## November 14 city status confirmation

- City activate, pause and archive use a native dialog. Focus opens on Cancel, Escape returns it to the invoking control, and an API failure appears inside the dialog for retry.
- `pnpm test:browser --workers=2` passed 18 cases, including keyboard and axe checks at all three viewports. Three opt-in cases were skipped. The separate live desktop test passed with the new confirmation. Admin lint and typecheck also passed.
- Changed the admin city screen, browser tests and acceptance documentation. Evidence is in `docs/qa/evidence/phase1-2026-11-14-city-dialog.txt` and `admin-city-confirmation-375.png`. Revert those changes to restore inline confirmation. No API or schema changes were needed.

## November 14 closed coverage browser check

- The customer server-rendered banner now has a deterministic test for closed coverage at all three viewports. The Playwright config starts a loopback fixture for the server-side request in the default suite. Live mode still points to Laravel. No database records were changed.
- `pnpm test:browser --workers=2` exited 0 with 15 passing cases and three skips for the opt-in live test. See `docs/qa/evidence/phase1-2026-11-14-closed-coverage.txt`.
- Revert the Playwright config, fixture, customer test assertions and documentation to roll back. No application or schema change was needed.

## November 14 live coverage browser check

- Added `tests/browser/live-coverage.spec.ts` and documented the opt-in command in README. It uses the existing local demo owner and customer accounts. Traces and screenshots are disabled for this credentialed case.
- `pnpm test:browser --project=desktop --workers=1 tests/browser/live-coverage.spec.ts` passed against local Laravel and PostGIS with `BROWSER_LIVE=1` and the demo password supplied in the process environment. The run covered a 422 for a self-intersecting polygon, exact MultiPolygon persistence, activation, a positive customer check, and a 404 for the customer on an admin endpoint.
- The original run used default priority and did not select the intended zone in the seeded environment. The test now uses explicit priority and checks the selected zone ID. Cleanup archives the created zone and city through the API. A database query confirmed zero active browser test cities after the run.
- Rollback removes the test and documentation. Archived synthetic records and append-only audit entries remain as evidence of the local run. Map drawing, no-city browser state and hosted verification remain open.

## November 14 browser and accessibility checks

- `pnpm exec playwright install chromium` and `pnpm test:browser --workers=2` ran successfully. Five cases passed at each of three viewports, for 15 total. Four cases check horizontal overflow and run axe against WCAG A/AA tags. The fifth verifies that sign-in rejects an external return URL. The suite uses synthetic responses for the browser API calls under test, and the customer server-rendered banner reflects the local stack when available.
- Axe identified a keyboard-focus gap on the customer category list at 375 pixels. The list now has a visible focus outline and supports arrow-key scrolling. The browser regression checks movement and records `docs/qa/evidence/customer-category-keyboard-375.png`.
- Added the Playwright config, browser cases, pinned development dependencies and a web workflow step. Updated the customer home, README and acceptance checklist. Hosted workflow execution and live browser city and zone scenarios remain unverified.
- `.\infra\scripts\dev.ps1 check` exited 0 with 409 backend tests and 7 Flutter widget tests passing. Two opt-in render tests were skipped. Web lint, types, tests and builds passed. `pnpm audit` reported no known vulnerabilities.
- Revert the browser configuration, dependencies, workflow and customer home markup together to roll back. No schema or API changes were needed.

## November 14 role concurrency and API contract

- Added `ConcurrentRoleGrantTest` for B-06. A separate PostgreSQL session waits for a revoke transaction and creates one active replacement with the expected audit entries. It shares the process and lock-wait setup with the zone race test. Both passed with `docker compose -f infra/compose.yaml --env-file .env exec -T api php artisan test --no-ansi --filter=Concurrent` (2 tests, 11 assertions).
- Connected the browser session client's sign-in, CSRF, current-user and error types to the generated OpenAPI contract. The request helpers and runtime behavior are unchanged.
- Enabled Redocly structure, reference and unique operation ID validation during type generation. Redocly 1.34.24 was already in the lockfile through the generator. A negative test reproduces a malformed response that the default generator accepted. `pnpm install --frozen-lockfile --offline` and `pnpm --filter @food-delivery/api-client test` passed.
- Rollback is a revert of the test files, API generator and test, session package changes, lockfile entries and documentation. This batch has no database changes.

## November 14 password reset session invalidation

- A browser session previously remained authenticated after a password reset. The regression test first reproduced a 200, then passed with a 401 on the old session and a successful sign-in using the new password. The focused command `docker compose -f infra/compose.yaml --env-file .env exec -T api php artisan test --no-ansi --filter='PasswordRecoveryTest|SessionLoginTest|SessionAndMeTest'` passed 37 tests and 176 assertions.
- Browser sign-in now records a Laravel-generated password fingerprint in the session, and `EnsureSurfaceSession` checks it on protected requests. This applies to all three browser surfaces. Previously issued sessions require sign-in again. The rider token contract is unchanged.
- Changed `AuthController.php`, `EnsureSurfaceSession.php`, auth feature tests, OpenAPI, generated API types and documentation. Reverting these files restores the prior session behavior and requires no data migration.
- The full local check exited 0 with 409 backend tests (33,550 assertions) and 7 Flutter widget tests. Two opt-in render tests were skipped. See `docs/qa/evidence/phase1-2026-11-14-batch-check.txt`.

## November 14 web dependency audit

- `pnpm audit` found one moderate advisory, [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249), in the lint toolchain's `minimatch` dependency. The root `package.json` override and lockfile pin the affected range to the patched 11.0.1 release. `pnpm install --frozen-lockfile` succeeded and the full audit reported no known vulnerabilities after the pin. Revert both override and lockfile changes together if rolling back.
- After the override, `pnpm lint`, `pnpm typecheck`, `pnpm test` and `pnpm build` all passed.

## November 14 admin MFA gate

- Closed a gap in B-09. Staging could previously create password-only admin sessions, and production and staging could accept existing admin sessions. `SurfaceAccess` now applies the same local and testing allowlist to sign-in and protected requests. The tests exercise valid CSRF for the deployed login path.
- `docker compose -f infra/compose.yaml --env-file .env exec -T api php artisan test --no-ansi --filter=SessionLoginTest` reproduced three failures before the fix and passed all 15 tests (72 assertions) afterward.
- `.\infra\scripts\dev.ps1 check` exited 0 with 407 backend tests and 7 Flutter widget tests passing. Two opt-in render tests were skipped. Web lint, types, tests and builds, backend formatting and analysis, and Flutter analysis passed. Evidence is in `docs/qa/evidence/phase1-2026-11-14-admin-gate.txt`. Composer and pnpm audits reported no known advisories.
- Changes are in `AuthController.php`, `SurfaceAccess.php`, `SessionLoginTest.php`, the OpenAPI contract, generated API types and the Phase 1 documentation. Regenerated types with `pnpm --filter @food-delivery/api-client generate`.
- Rollback is a code and documentation revert with no database changes. A revert restores the password-only staging path, so keep hosted admin access closed if reverting. TOTP enrollment, challenge and recovery remain open.

## November 14 checkpoint

- Verified all 25 project skills listed in `skills-lock.json` are present under both `.claude/skills/` and `.agents/skills/`. The directory comparison with `git -c core.safecrlf=false diff --no-index --quiet -- .claude/skills .agents/skills` exited 0. `CLAUDE.md` now records how to resume a slice and keep both skill copies aligned.
- Added `backend/tests/Integration/ConcurrentZoneUpdateTest.php` for C-04. PostgreSQL reports the second session waiting on the first transaction. After commit the second update raises a 409 and leaves one audited edit at version 2. The existing feature test separately checks the HTTP error response.
- Verified with `docker compose -f infra/compose.yaml --env-file .env exec -T api php artisan test --filter=ConcurrentZoneUpdateTest` (1 test, 6 assertions), `docker compose -f infra/compose.yaml --env-file .env exec -T api composer audit` and `pnpm audit --prod` (no known advisories reported). Docker Desktop disconnected during the first full check and was restarted before the focused test.
- The rerun of `.\infra\scripts\dev.ps1 check` exited 0. Web lint, type checks, tests and builds passed. Backend formatting and static analysis passed, with 404 tests and 33,527 assertions against PostGIS. Flutter analysis passed, and 7 widget tests passed with 2 opt-in render tests skipped. Output is in `docs/qa/evidence/phase1-2026-11-14-check.txt`, with local workspace paths removed.
- Updated `README.md`, `docs/backlog.md` and ADR-000 to reflect the current implementation. Rollback is a revert of these documentation changes, the `CLAUDE.md` update and the added test. There are no application, schema or dependency changes in this slice.
- Open gates include privileged TOTP and recovery, Playwright and accessibility coverage, rider sign-in and Android verification, a hosted CI run and staging acceptance. See the backlog for the remaining implementation and operator decisions.

## Summary
Sprint 1 documents await human review. The Docker stack now starts healthy and the backend checks run inside it against PostGIS and Redis. The Sprint 2 gate ("clean clone boot smoke with logs") is **still not met**: the boot was done in the working tree, not from a clean clone, and CI has never run. The product owner supplied front-end reference images; the customer home screen is built in that direction.

## Sprint 7: trusted proxies (follow-up to E-02 and E-05, 2026-11-13)
Evidence: `docs/qa/evidence/sprint7-trusted-proxy-check.txt` (behind nginx in the rehearsal stack) and `sprint7-trusted-proxy-full-check.txt` (`dev.ps1 check`).

- Gap closed: behind the host's TLS proxy, Laravel trusted no forwarded headers, so it saw every request as plain HTTP and never sent HSTS.
- `TRUSTED_PROXIES` (new, `config/trustedproxy.php`) is a comma-separated list of IP addresses or CIDR ranges. It is empty by default. Laravel's own middleware believes only `X-Forwarded-For`, `-Proto` and `-Port`, and only from those addresses; `X-Forwarded-Host` is never believed, because URLs come from `APP_URL`.
- `ProductionGuard` gained a rule: refuse `*`, `**`, a `/0` range, host names and malformed masks. Trusting everyone would let any client fake its address and slip past per-address sign-in limits.
- Tests: TrustedProxyTest (8) and seven new ProductionGuardTest cases.
- Checked behind nginx (rehearsal stack, Docker gateway playing the proxy):
  - unset: a claimed https was ignored;
  - set to the gateway: HSTS was sent, while the same claim from another container changed nothing;
  - `*`: the API refused to start.
- Learned: the guard runs when the container starts, because the entry point's `route:cache` boots the application. An unsafe setting makes the container exit and restart, and nginx answers 502. Earlier text said the check happened on the first request; the entry point comment, ADR-014 and the runbook are corrected, and the runbook now says how to recognise a refused start.

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
  - no TLS in the rehearsal (trusted proxies were added afterwards, see above);
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
- A map-based zone editor and screens for exclusions, users and settings remain open. The November 14 checkpoint records the parallel zone-edit test.

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

## November 14 map component (U-04)

- **Built:** `MapView` in `packages/ui` (`@food-delivery/ui/map`), used on the admin zone create form and the zone editor (`apps/admin-web/app/cities/[id]/zone-map.tsx`) beside the GeoJSON field, which stays the source of truth and fully usable.
  - The style URL is read at run time from `GET /api/v1/public/config` (`map_style_url`). Only an https URL counts; empty shows a labelled "Map not configured" placeholder.
  - There is no default centre or zoom. The view is fitted only to the bounds of the shape in the text field; with no valid shape it says it is not centred anywhere. Bounds are also written as text.
  - Attribution is always rendered. With the library in use it adds MapLibre's `AttributionControl`.
  - Map code loads only on the client, through a dynamic `load()` that is called only when a style URL is set.
- **Not done:** `maplibre-gl` is not in `pnpm-lock.yaml`, and no package was added. `loadMapLibrary` returns null, so with a style set the area says "Map library not installed" (TODO in `packages/ui/src/map-view.tsx`: replace with `import("maplibre-gl")`). When it is added, the CSP in `packages/config` (img-src, connect-src, worker-src) needs the chosen style host; that file was not touched. No drawing, no tiles ever rendered, no retry path exercised in a browser (the failed state is code only), and no zone-status pattern layer or mirror table on the map. The tile provider is still a human decision (ADR-007).
- **Tests:** `packages/ui/test/map-state.test.mjs` (6: placeholder for empty/invalid style, https only, no centre, bounds, no centre/zoom/coordinate literals in sources, attribution present) and `tests/browser/map.spec.ts` (5 at 3 viewports: placeholder with empty config, bounds from the shape, editor bounds, library-missing state with attribution, axe on the map area).
- **Commands run:** `pnpm install --offline` (workspace link and lockfile entry only); `pnpm lint` exit 0; `pnpm typecheck` exit 0; `pnpm test` exit 0 (ui 6 pass); `pnpm --filter admin-web build` exit 0; `npx playwright test tests/browser/map.spec.ts tests/browser/state-matrix.spec.ts` 114 passed. The first state-matrix run failed because the placeholder had `role="status"` and clashed with existing `getByRole("status")` checks; the role was removed.
- **Files:** `packages/ui/{package.json,src/map-state.ts,src/map-view.tsx,test/map-state.test.mjs}`, `apps/admin-web/{package.json,next.config.ts,app/cities/[id]/city-screen.tsx,app/cities/[id]/zone-map.tsx}`, `tests/browser/map.spec.ts`, `pnpm-lock.yaml`, `docs/backlog.md`, this file.
- **Rollback:** revert those files and run `pnpm install`; no backend or API change.

## November 14 X-01 acceptance run (local)

Every result below is from real output saved in `docs/qa/evidence/x01-2026-11-14-*.txt`. Nothing was committed or pushed.

| Acceptance item | Result | Evidence |
|---|---|---|
| Default Playwright suite (`pnpm exec playwright test`, Chromium, 375/768/1440, synthetic API) | pass: 267 passed, 66 skipped (live and screenshot specs are opt-in), exit 0, 2.7 min | `x01-2026-11-14-playwright-default.txt` |
| Live Playwright (`BROWSER_LIVE=1`, desktop, Docker API; password read from the untracked `.env` into the process environment only) | pass: 1 passed (city and GeoJSON zone control live serviceability) | `x01-2026-11-14-playwright-live.txt` |
| Accessibility: axe at three viewports (customer banner and empty state, outside coverage, map area, staff MFA, state matrix, admin dialogs) | pass: runs inside the default suite above with no violations. Keyboard and dialog focus cases are in the same suite. Screen reader, dark theme and Android TalkBack not checked | same file |
| DoD 1, clean clone | partial: `git clone` of the local repo, then `dev.ps1 setup` exit 0; in the clone `pnpm lint`, `typecheck`, `test`, `build`, `pint --test`, `phpstan` (with `--memory-limit=1G`), `flutter analyze` and `flutter test` (47 passed, 2 skipped) all exit 0 | `x01-2026-11-14-clean-clone.txt` |
| Clean clone `up`, `migrate`, `seed-demo`, `check` with the Docker stack | not run: a second stack would collide with the running one, and backend database tests would hit the shared test database. Makefile not run (`make` not installed) | n/a |
| DoD 8, hosted CI | pass: web, backend and security succeeded for `099a3db` (the first query showed them in progress; re-queried to completion); the previous push and dependabot PR runs are green | `x01-2026-11-14-hosted-ci.txt` |
| Staging | blocked on the human (E-07) | n/a |
| Phase boundary: rider app requests no location permission | grep found no permission entries in `AndroidManifest.xml` or `pubspec.yaml`; not a full review | not saved |
| Hardcoded city search; invented-data text search | not run | n/a |

- Findings: no product bug. `phpstan analyse` without `--memory-limit` crashes at PHP's 128M default on this workstation; `dev.ps1 check` already passes the flag.
- Process note: the scratch directory is shared with other agents. A first clone under the generic name `clone` was deleted by someone else, so the run was repeated in a uniquely named directory, which was then removed.
- Rollback: delete the four evidence files and revert the X-01 row and three acceptance rows; no code changed.

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
