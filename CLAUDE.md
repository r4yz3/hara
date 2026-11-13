# Food delivery platform (Philippines-first, multi-vendor)

@AGENTS.md

## Read first
- `FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md` — product scope, domain model, phases 0–7.
- `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md` — the current phase. Sections 1.2 (out of scope), 1.3 (definition of done) and 12 (sprints) decide what to build next.

## Current state
Sprint 1 documents await human review. Sprint 2 skeletons exist and pass `.\infra\scripts\dev.ps1 check` with the backend running in the Docker stack against PostGIS and Redis. Sprint 3 migrations are in and tested (migration inventory in `docs/PHASE1_STATUS.md`); Sprint 4 is in progress: the role catalogue (`backend/config/rbac.php`), `AccessChecker`, `RoleGrantService` and `AuditLogger` are built and tested; sign-in, registration, logout and `/me` work with one session cookie per web app (see the Sprint 4 sections of `docs/PHASE1_STATUS.md`); each web app has a `/login` page (shared code in `packages/session`); password recovery, MFA, mobile tokens and page protection are not started. CI workflows have never run (no remote repository). Front-end work follows `docs/ux/design-direction.md` (set by the product owner's reference images); the merchant portal overview and rider home are first screens; the customer app has a home banner driven by the API and a working delivery-area check at `/location`; the admin console also has working cities and zones screens (`apps/admin-web/app/cities`), with zone shapes entered as GeoJSON because no map provider is chosen. `docs/PHASE1_STATUS.md` is the detailed record, `docs/backlog.md` the work list, and `docs/architecture/decisions/` holds the ADRs. Update this section when a sprint gate passes.

## Fixed constraints
- Launch city is unselected. Never hardcode a city, coordinates or a map centre.
- Phase 1 only: no working ordering, payments, dispatch, GPS tracking or payouts.
- Backend tests run against PostGIS, never sqlite.
- Money is integer centavos; IDs are ULIDs; storage is UTC.

## Skills
Project skills live in `.claude/skills/` (pinned in `skills-lock.json`). Load the matching one before working in its area; the map is section 18 of the Phase 1 specification. The specification overrides a skill when they disagree.

## Environment
Windows 11, PowerShell primary. Docker Desktop is installed; start it before `dev.ps1 up`. A native PostgreSQL already uses port 5432 on this machine, so the local `.env` publishes the project's database on 54329. Provide PowerShell equivalents in `infra/scripts/*.ps1` for every Makefile target.

## Commands that work on this workstation
- `.\infra\scripts\dev.ps1 check` — lint, type check and build the web apps; format, analyse and test the backend; analyse and test the rider app. With the stack up it runs the backend checks inside the `api` container; without Docker it falls back to native PHP, which cannot run database tests.
- `.\infra\scripts\dev.ps1 up` / `down` — start and stop the stack (`down` keeps data).
- `pnpm dev:customer` (3000), `pnpm dev:merchant` (3001), `pnpm dev:admin` (3002).
- Backend, from `backend/`: `php artisan test`, `php vendor/bin/pint`, `php vendor/bin/phpstan analyse`.
- Each Next.js app has its own `AGENTS.md`: Next.js 16 differs from older versions, so read `node_modules/next/dist/docs/` before writing app code.
- New backend routes must be added to `backend/tests/Feature/RouteInventoryTest.php` in the same change.
- Tailwind is v4 here (CSS `@theme`, no `tailwind.config.js`); the `tailwindcss-development` skill describes v3 syntax, so follow the existing files.
- Use only colour pairs listed in `docs/ux/contrast-report.md`; add tokens in `packages/design-tokens/src/tokens.mjs` and run its build.
- Rider screen images: `RENDER_EVIDENCE=1 flutter test test/render_evidence_test.dart --update-goldens` from `apps/rider-flutter` writes PNGs to `docs/qa/evidence/` (no Android device needed).
- Backend tests need the Docker stack (PostgreSQL). `tests/bootstrap.php` forces the `food_delivery_test` database; never point tests at another one.
- `.\infra\scripts\dev.ps1 migrate` and `seed-demo` apply the schema and the synthetic demo city to the development database.
- New API routes also go in `backend/openapi/openapi.yaml`; `OpenApiDocumentTest` fails if the document and the routes differ. After changing that file run `pnpm --filter @food-delivery/api-client generate`; `pnpm test` fails if the generated types are stale.
- In feature tests, `withHeader('Origin', ...)` persists across requests in the same test; use `withoutHeader('Origin')` for a request that must have none. The test framework disables CSRF checks, so verify CSRF with real requests.
- Demo sign-in: set `DEMO_PASSWORD` in the local `.env`, restart the stack, run `dev.ps1 seed-demo`; accounts are `demo-owner@example.test` and `demo-customer@example.test`. Never put the value in a tracked file.
- Never put a position, address or other personal data in a URL. The serviceability check is a POST for this reason (ADR-013).
- A new public route must be added to the list in `backend/tests/Feature/RouteCoverageTest.php` on purpose; everything else must sit behind the session guard.
- `.\infra\scripts\dev.ps1 backup` and `restore-drill` back up the development database to the git-ignored `infra/backups/` and verify a restore into a scratch database. Backup files hold personal data: never commit or share them.
- With `APP_ENV=production` the API refuses to serve unless `App\Support\ProductionGuard` is satisfied; a new production-critical setting should get a rule and a test there.
