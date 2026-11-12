# AGENTS.md — engineering policy for coding agents

Canonical rules for any coding agent working in this repository. Source: section 13 of `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md`; keep the two in sync.

1. Read `FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md` and `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md` before editing anything; report discrepancies in `docs/architecture/decisions/`.
2. Never assume which Philippine city, postal polygon, launch date, payment processor, rider compensation or brand is selected.
3. Implement **only** the current Phase 1 slice unless specifically asked to advance. Scope-creep features appear behind flags and should not masquerade as operational.
4. Never invent green test output, valid route ETA, active rider, restaurant data, financial balances or payment callbacks.
5. Work test-first for geospatial logic, authorization, monetary helpers and concurrency. Do not use float money.
6. Include migrations + policies + requests + API resource + OpenAPI + tests in each server feature.
7. No self-granted roles; no client-trusted tenant IDs; no skipped permission checks on admin APIs or socket channels.
8. Keep secrets, real coordinates, customer contacts and rider sensitive documents out of logs, fixtures and repository.
9. Use standard Laravel and Next conventions; avoid custom abstractions without an ADR and proof of need.
10. Pin dependencies and avoid `latest` floating releases in deployable config. Verify compatibility from installed lockfiles.
11. Add accessibility, error/empty/loading/offline state for each user-facing view, plus screenshot evidence from actual run.
12. After each slice: run formatter, types, unit tests, integration tests, browser tests as relevant, security checks and record results, changed files, open issues, rollback notes.
13. Fix bugs before continuing. Treat failing security or financial tests as stop conditions, not low-priority TODOs.
14. No fabricated production-readiness claim. Clearly distinguish scaffold, mock, sandbox and tested live integration.
15. Keep `README.md`, OpenAPI, migrations and test fixtures synchronized with behavior.
16. Prefer minimal, audited dependencies. Never install untrusted packages solely because an AI agent suggested them.
17. Before editing an existing file, inspect its current state; preserve user changes and show diff.
18. Use conventional commits or equivalent descriptive commit history. Do not push/deploy without user authorization.
19. Document exact commands that worked in the current environment; never assume npm scripts exist until created.
20. The human operator approves credentials, provider registrations, real coverage polygon, payouts, labor/insurance and production release.
