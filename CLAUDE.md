# Food delivery platform (Philippines-first, multi-vendor)

@AGENTS.md

## Read first
- `FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md` — product scope, domain model, phases 0–7.
- `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md` — the current phase. Sections 1.2 (out of scope), 1.3 (definition of done) and 12 (sprints) decide what to build next.
- `docs/PHASE1_STATUS.md` and `docs/backlog.md` — start with the latest verification and pick the next open, unblocked Phase 1 item. Use `docs/qa/phase1-acceptance.md` for the acceptance gate.

## Continue a Phase 1 slice
- Inspect the working tree and the files for that slice. Read the closest `AGENTS.md` for app-specific conventions.
- Load the matching repository skill from the table in section 18 of the Phase 1 spec. Read the relevant ADR before changing a settled design.
- Run focused checks first, then the relevant project checks. Record the commands, results, evidence and rollback in the status and backlog so the next session can resume from a verified checkpoint.
- When asked to continue Phase 1, work through successive unblocked items without waiting for a separate confirmation after each item. Stop for a decision only when it is required for the remaining work, and continue other independent items where possible.

## Current state
Phase 1 gate: **not passed** (2026-11-14). Sprints 2 to 7 are built and checked locally; Sprint 8 acceptance is partial. Open: staging (E-07, no host), human reviews (Sprint 1 documents, ADR-011 recovery codes) and decisions (C-13 caps, tile hosting for the self-hosted PMTiles choice (ADR-015), CSP nonce trade-off, offline and toast design, support name visibility), map drawing, and the rider app, which has never run on a device. Hosted CI was green for `61ccc84` (web, backend, security with image scans); later commits are unverified. Staff TOTP, roles and access, error screens and the state matrix are built. docs/PHASE1_STATUS.md (top section) lists done, partial, blocked and future with evidence and the definition-of-done verdicts; docs/backlog.md is the work list. Front-end work follows docs/ux/design-direction.md. Update this section when a gate changes.

## Fixed constraints
- Launch city is unselected. Never hardcode a city, coordinates or a map centre.
- Phase 1 only: no working ordering, payments, dispatch, GPS tracking or payouts.
- Backend tests run against PostGIS, never sqlite.
- Money is integer centavos; IDs are ULIDs; storage is UTC.

## Skills
Project skills live in `.claude/skills/` (pinned in `skills-lock.json`). Codex discovers the local copies under `.agents/skills/`. When updating a project skill, keep its full directory in sync, including references, and review the lockfile change. The specification overrides a skill when they disagree.

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
- `.\infra\scripts\rehearsal.ps1` runs the production images (`infra/docker/prod/api.Dockerfile`, ADR-014) under `APP_ENV=staging` on `127.0.0.1:8088`, with its own database; `docs/operations/deploy.md` and `runbook.md` describe deploy and rollback. Its `restore` replaces the rehearsal database only. Migrations must be expand-only.
- `.\infra\scripts\dev.ps1 backup` and `restore-drill` back up the development database to the git-ignored `infra/backups/` and verify a restore into a scratch database. Backup files hold personal data: never commit or share them.
- With `APP_ENV=production` the API refuses to serve unless `App\Support\ProductionGuard` is satisfied; a new production-critical setting should get a rule and a test there.
- A reset or sign-in token must never be put in a query string; use the request body or, for emailed links, the URL fragment.
- `ProductionGuard` applies to every environment except `local` and `testing`, and to console commands except its repair list. It must never hold a secret value: reduce secrets to booleans in `read()`.
- Rider routes use `EnsureRiderToken` (bearer token); browser routes use `EnsureSurfaceSession` (cookie). A route gets exactly one of them, and `RouteCoverageTest` enforces that.
