# Food delivery platform (Philippines-first, multi-vendor)

@AGENTS.md

## Read first
- `FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md` — product scope, domain model, phases 0–7.
- `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md` — the current phase. Sections 1.2 (out of scope), 1.3 (definition of done) and 12 (sprints) decide what to build next.

## Current state
Sprint 1 documents await human review. Sprint 2 skeletons exist (three Next.js apps, Laravel API with health routes only, Flutter rider app) and pass `.\infra\scripts\dev.ps1 check`. The Compose stack and CI workflows are written but have never been run: Docker Desktop is not installed and there is no remote repository. `docs/PHASE1_STATUS.md` is the detailed record, `docs/backlog.md` the work list, and `docs/architecture/decisions/` holds the ADRs (ADR-000 lists where the two plan documents disagree). Update this section when a sprint gate passes.

## Fixed constraints
- Launch city is unselected. Never hardcode a city, coordinates or a map centre.
- Phase 1 only: no working ordering, payments, dispatch, GPS tracking or payouts.
- Backend tests run against PostGIS, never sqlite.
- Money is integer centavos; IDs are ULIDs; storage is UTC.

## Skills
Project skills live in `.claude/skills/` (pinned in `skills-lock.json`). Load the matching one before working in its area; the map is section 18 of the Phase 1 specification. The specification overrides a skill when they disagree.

## Environment
Windows 11, PowerShell primary. Docker Desktop is not installed yet and is required from Sprint 2. Provide PowerShell equivalents in `infra/scripts/*.ps1` for every Makefile target.

## Commands that work on this workstation
- `.\infra\scripts\dev.ps1 check` — lint, type check and build the web apps; format, analyse and test the backend; analyse and test the rider app. Without Docker it uses native PHP, which cannot run database tests.
- `pnpm dev:customer` (3000), `pnpm dev:merchant` (3001), `pnpm dev:admin` (3002).
- Backend, from `backend/`: `php artisan test`, `php vendor/bin/pint`, `php vendor/bin/phpstan analyse`.
- Each Next.js app has its own `AGENTS.md`: Next.js 16 differs from older versions, so read `node_modules/next/dist/docs/` before writing app code.
- New backend routes must be added to `backend/tests/Feature/RouteInventoryTest.php` in the same change.
