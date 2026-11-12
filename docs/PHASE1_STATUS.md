# Phase 1 status

Last updated: 2026-11-13, Sprint 2 in progress.

## Summary
Sprint 1 documents are written and await human review. Sprint 2 has produced working skeletons for all four apps and the API, verified by a real check run. The Sprint 2 gate ("clean clone boot smoke with logs") is **not met**: the container stack has never been started because Docker Desktop is not installed, and there are no commits, so no clean clone exists.

## Sprint 2: verified by running it
Evidence: `docs/qa/evidence/sprint2-check.txt` (`.\infra\scripts\dev.ps1 check`, exit code 0).

| Check | Result |
|---|---|
| Web lint, type check, production build (3 apps) | Pass |
| Backend format (Pint) | Pass |
| Backend static analysis (Larastan level 6) | Pass, no errors |
| Backend tests | 9 passed, 23 assertions (native PHP, no database) |
| Flutter analyze | No issues |
| Flutter tests | 4 passed |
| `composer audit`, `pnpm audit --prod` | No known vulnerabilities |
| Customer app served by `next start` | HTTP 200 with the scaffold page |

What the tests cover: liveness body; readiness hidden from public addresses and limited to up/down flags; framework default `/up` removed; no ordering or restaurant routes; the route list equals exactly the two health routes; domain modules exist and do not import the HTTP layer or each other's internals; rider toggle disabled; rider manifest requests no location permission.

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
- A-12 Laravel Boost not installed.
- `infra/compose.test.yaml`, `infra/terraform`, monitoring config, Storybook, Playwright: not created; they belong to later sprints or were not reached.
- `packages/*` are empty placeholders.
- Android device build not attempted (no Android SDK or JDK).
- No commits. Git is initialized on `main` with everything untracked.

## Findings during Sprint 2
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
Nothing is committed. Sprint 2 added `apps/`, `backend/`, `packages/`, `infra/`, `.github/`, the root workspace files and `README.md`; removing them restores the Sprint 1 state.

## Next steps
1. Human: install Docker Desktop.
2. Agent, once Docker exists: validate and start the stack, fix what breaks, run backend tests in the container, record the boot logs, then close the Sprint 2 gate.
3. Agent: first commit, on request.
