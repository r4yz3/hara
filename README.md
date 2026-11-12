# Food delivery platform

Philippines-first, multi-vendor food delivery marketplace. **Phase 1, Sprint 2: skeletons only.** There is no ordering, payment, dispatch or tracking, and no launch city is configured. Nothing here is deployable to production.

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
| `packages/*` | Shared web packages (placeholders) |
| `infra` | Compose stack, Dockerfile, scripts |

## Requirements

Git, Node 22 or later, pnpm 9.15.9, PHP 8.3 or later with Composer, Flutter stable. Docker Desktop is required for the database, Redis and mail capture, and for any test that touches the database.

## Commands (Windows PowerShell)

```powershell
.\infra\scripts\dev.ps1 setup      # install dependencies, create .env files
.\infra\scripts\dev.ps1 check      # lint, types, builds, analysis, tests
.\infra\scripts\dev.ps1 up         # start the stack (needs Docker)
.\infra\scripts\dev.ps1 migrate    # needs Docker
.\infra\scripts\dev.ps1 down       # stop the stack, keep data
.\infra\scripts\dev.ps1 logs
pnpm dev:customer                  # or dev:merchant, dev:admin
```

On Linux, macOS or WSL the same targets exist in the `Makefile`.

## What has been verified

| Command | State on 2026-11-13 |
|---|---|
| `dev.ps1 check` | Passes. Output in `docs/qa/evidence/sprint2-check.txt` |
| `dev.ps1 setup` | Not run end to end |
| `dev.ps1 up`, `migrate`, `down`, `logs` | Not run: Docker is not installed on the authoring workstation |
| `Makefile` targets | Not run: `make` is not installed |
| GitHub Actions workflows | Never run: no remote repository yet |
| `dev.ps1 seed-demo` | Not available until Sprint 3 |

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

## Health routes

`GET /health/live` returns `{"status":"ok"}`. `GET /health/ready` reports database and Redis state and answers only to loopback and private network addresses; everyone else gets 404.
