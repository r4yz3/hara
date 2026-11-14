# Food delivery platform

Philippines-first, multi-vendor food delivery marketplace. **Phase 1 foundation in progress.** The API supports authentication, role-scoped administration and delivery-area checks. The web and rider apps are at different stages of implementation. See the Phase 1 status for the remaining acceptance gates.

- Plan: `FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md`
- Current phase: `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md`
- Status: `docs/PHASE1_STATUS.md` · Work list: `docs/backlog.md` · Decisions: `docs/architecture/decisions/`

## Layout

| Path | What |
|---|---|
| `apps/customer-web` | Next.js customer app, port 3000 |
| `apps/merchant-web` | Next.js merchant portal, port 3001 |
| `apps/admin-web` | Next.js admin and dispatch console, port 3002 |
| `apps/rider-flutter` | Flutter rider app (Android) |
| `backend` | Laravel 13 API, port 8000 |
| `packages/*` | Shared design tokens, UI, session code, web config and generated API types |
| `infra` | Compose stack, Dockerfile, scripts |

## Requirements

Git, Node 22 or later, pnpm 9.15.9, PHP 8.3 or later with Composer, Flutter stable. Docker Desktop is required for the database, Redis and mail capture, and for any test that touches the database.

## Commands (Windows PowerShell)

```powershell
.\infra\scripts\dev.ps1 setup      # install dependencies, create .env files, generate APP_KEY
.\infra\scripts\dev.ps1 check      # lint, types, builds, analysis, tests
.\infra\scripts\dev.ps1 up         # start the stack (needs Docker)
.\infra\scripts\dev.ps1 migrate    # needs Docker
.\infra\scripts\dev.ps1 down       # stop the stack, keep data
.\infra\scripts\dev.ps1 logs
.\infra\scripts\dev.ps1 backup          # dump the dev database to infra/backups (git-ignored)
.\infra\scripts\dev.ps1 restore-drill   # restore the newest backup into a scratch database and verify it
pnpm dev:customer                  # or dev:merchant, dev:admin
```

On Linux, macOS or WSL the same targets exist in the `Makefile`.

`setup` writes one generated `APP_KEY` into both the root `.env` (read by the `api` container through compose `env_file`, and the one that wins) and `backend\.env` (read by native PHP). It leaves a non-empty key alone and never prints it. Without a key, backend tests fail with "Unsupported cipher or incorrect key length". To run a second checkout beside a running stack, uncomment and change `COMPOSE_PROJECT_NAME` in its `.env`.

For Chromium browser checks, run `pnpm exec playwright install chromium` once, then `pnpm test:browser --workers=2` from the repository root. Playwright starts the three web apps on ports 3000–3002. The tests cover selected UI states with synthetic API responses and run axe at three viewport widths.

The optional live coverage test requires the local Laravel/PostGIS stack and seeded demo accounts. Set `BROWSER_LIVE=1` and supply the same local `DEMO_PASSWORD` in the test process environment. Run `pnpm test:browser --project=desktop --workers=1 tests/browser/live-coverage.spec.ts`. It writes synthetic records to the development database, then archives them through the API and retains the audit entries.

The optional live merchant test checks merchant sign-in against the real API and merchant app. `dev.ps1 seed-demo` (with `DEMO_PASSWORD` set) creates the synthetic `demo-merchant@example.test`, an owner of a synthetic merchant with no branch. Run it with `BROWSER_LIVE=1` and the same `DEMO_PASSWORD` in the process environment: `pnpm exec playwright test --project=desktop --workers=1 tests/browser/live-merchant.spec.ts`. Without `BROWSER_LIVE` it is skipped.

## What has been verified

| Command | State on 2026-11-13 |
|---|---|
| `dev.ps1 check` | Passes with the backend in the Docker stack. Output in `docs/qa/evidence/sprint2-check-4.txt` |
| `dev.ps1 setup` | Not run end to end |
| Compose stack | Starts healthy; `/health/ready` returns 200. The `up`, `migrate`, `down`, `logs` script tasks themselves not run |
| `Makefile` targets | Not run: `make` is not installed |
| GitHub Actions workflows | Never run: no remote repository yet |
| `dev.ps1 seed-demo` | Works: creates the synthetic demo city and zone in the development database |

## Ports

| Service | Port | Exposure |
|---|---:|---|
| Customer | 3000 | localhost |
| Merchant | 3001 | localhost |
| Admin | 3002 | localhost |
| API | 8000 | localhost |
| PostgreSQL | 5432 | localhost only |
| Redis | 6379 | localhost only |
| Mailpit UI / SMTP | 8025 / 1025 | localhost only |

## API

The contract is `backend/openapi/openapi.yaml`. It covers health checks, browser authentication and recovery, rider tokens, public configuration and serviceability, and admin cities, zones, exclusions, audit and system status. Registration is off unless `FEATURE_PUBLIC_REGISTRATION=true`.

Admin sessions are available under `local` and `testing` while two-step verification is pending.

## Health routes

`GET /health/live` returns `{"status":"ok"}`. `GET /health/ready` reports database and Redis state and answers only to loopback and private network addresses; everyone else gets 404.
