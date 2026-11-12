# Phase 1 status

Last updated: 2026-11-13, Sprint 2 in progress (second pass).

## Summary
Sprint 1 documents are written and await human review. Sprint 2 has produced working skeletons for all four apps and the API, verified by a real check run. The Sprint 2 gate ("clean clone boot smoke with logs") is **not met**: the container stack has never been started because Docker Desktop is not installed, and there are no commits, so no clean clone exists.

## Sprint 2: verified by running it
Evidence: `docs/qa/evidence/sprint2-check-2.txt` (`.\infra\scripts\dev.ps1 check`, exit code 0; the first run is `sprint2-check.txt`).

| Check | Result |
|---|---|
| Web lint, type check, production build (3 apps) | Pass |
| Backend format (Pint) | Pass |
| Backend static analysis (Larastan level 6) | Pass, no errors |
| Backend tests | 77 passed, 107 assertions (native PHP, no database) |
| Flutter analyze | No issues |
| Flutter tests | 4 passed |
| `composer audit`, `pnpm audit --prod` | No known vulnerabilities |
| Customer app served by `next start` | HTTP 200 with the scaffold page |

What the tests cover: liveness body; readiness hidden from public addresses and limited to up/down flags; framework default `/up` removed; no ordering or restaurant routes; the route list equals exactly the two health routes; domain modules exist and do not import the HTTP layer or each other's internals; rider toggle disabled; rider manifest requests no location permission; money arithmetic stays in integers, rejects mixed currencies and throws on overflow; coordinates are bounds-checked and GeoJSON positions are read longitude-first.

## Sprint 2: written but never run
| Item | Why unverified |
|---|---|
| `infra/compose.yaml`, `infra/docker/php/Dockerfile`, Postgres init script | Docker not installed. Image tags were confirmed to exist on Docker Hub but never pulled |
| `dev.ps1 up / down / migrate / logs`, `dev.ps1 setup` end to end | Same, or not exercised |
| `Makefile` | `make` not installed |
| `.github/workflows/*` and Dependabot config | No remote repository |
| Readiness returning 200 | Needs a reachable database and Redis; only the 503 path and the response shape were exercised |
| Backend tests against PostGIS | Native PHP has no PostgreSQL driver |

## Sprint 2: not done
- `infra/compose.test.yaml`, `infra/terraform`, monitoring config, Storybook, Playwright: not created; they belong to later sprints or were not reached.
- `packages/*` are empty placeholders.
- Android device build not attempted (no Android SDK or JDK).

## Done ahead of Docker (second pass)
- D-06 `Money` value object (`backend/app/Support/Money`), test-first.
- `Coordinates` value object (`backend/app/Domain/Geo`), the first part of C-03.
- A-12 Laravel Boost installed as a dev dependency. Its MCP server answers an initialize request; the root `.mcp.json` registers it for Claude Code and takes effect in a new session.

## Findings during Sprint 2
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
| City status values (ADR-000 item 8) | Sprint 3 migrations |
| ADR-011 open items, permission matrix | Sprint 4 |
| Object storage choice (ADR-012) | First file-storing feature |
| Android SDK and JDK | Rider device builds |

## Rollback
Commit `b38e8e3` holds Sprints 1 and 2 up to the first check run. The second pass (value objects, Boost) is uncommitted; `git checkout . && git clean -fd backend .mcp.json docs/qa/evidence` discards it.

## Next steps
1. Human: install Docker Desktop.
2. Agent, once Docker exists: validate and start the stack, fix what breaks, run backend tests in the container, record the boot logs, then close the Sprint 2 gate.
3. Agent: commit the second pass, on request.
