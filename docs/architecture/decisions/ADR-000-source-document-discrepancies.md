# ADR-000: Discrepancies between the source documents

- Status: accepted for Phase 1; items marked **open** need a human decision
- Date: 2026-11-12
- Sources: `FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md` (master), `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md` (spec)

Rule 1 of `AGENTS.md` requires discrepancies to be reported here rather than resolved silently. Unless stated otherwise, the Phase 1 spec is followed because it is the later and more specific document.

| # | Topic | Master | Spec | Resolution |
|---|---|---|---|---|
| 1 | Merchant and admin frontends | React + TypeScript + shadcn/ui | Next.js App Router apps on separate origins | Follow the spec. shadcn/ui components live in `packages/ui`. See ADR-009 |
| 2 | Spatial type | "geography/geometry" | `geometry(...,4326)`, cast to `geography` for metres | Follow the spec. See ADR-002 |
| 3 | Primary keys | "UUID/ULID" | ULID, `char(26)` | Follow the spec. See ADR-003 |
| 4 | Backend modules | 13 domains incl. Checkout, Tracking, Settlements, Promos, Analytics | 9 domains incl. Geo; adds Application and Infrastructure layers | Phase 1 creates the spec's 9. The master's extra domains are added when their phase starts. See ADR-001 |
| 5 | Password column | not specified | `users.password_hash` | Laravel's default column is `password`. Spec 5.2 says to merge with framework defaults, so keep `password` and record the mapping in the migration inventory |
| 6 | ULID letter case | not specified | Examples are upper case; 5.1 says strings are case-normalized | Laravel's `HasUlids` is believed to emit lower case. Confirm against the installed framework in Sprint 3 and normalize to one case at the API boundary. See ADR-003 |
| 7 | Health routes | not specified | `/health/live`, `/health/ready` outside `/api/v1` | Follow the spec. Laravel's built-in `/up` route is disabled or mapped to `/health/live` so there is one liveness route |
| 8 | City status values | not specified | "draft/active chips", "archived" mentioned in admin list | **Open.** Proposed: `draft`, `active`, `paused`, `archived`, mirroring zone statuses. To confirm before Sprint 3 |
| 9 | Zone lifecycle | not specified | Statuses include `archived`; endpoints only cover activate and pause | As built, `POST /api/v1/admin/zones/{zone}/archive` handles the explicit transition from draft or paused. The version precondition and audit rules also apply. See backlog C-06 and OpenAPI |
| 10 | MFA | Required for privileged staff | "Scaffolding" in goals; `mfa_factors` only with a reviewed implementation; production admin blocked until MFA is done | Phase 1 delivers the challenge step in the login contract and the gate that blocks production admin login without MFA. The factor implementation is **open**. See ADR-011 |
| 11 | Reference URLs | `laravel.com/framework/docs/releases` | `laravel.com/framework/docs` | These paths were not checked in Sprint 1. Use `laravel.com/docs/13.x` and verify when first needed |
| 12 | Native PHP | PHP 8.3+ | PHP optional natively, Docker preferred | The workstation's native PHP 8.5 lacks PostgreSQL extensions. All backend commands run in the `api` container |
| 13 | Brand | "Brand and preferred design identity?" is an open decision | Wireframes use `BRAND` | No brand is assumed. The working folder name is not treated as the brand. UI uses a neutral placeholder name from configuration |
| 14 | Spec file name | — | Section 14 prompt referenced the old file name | The spec was renamed on 2026-11-12; references were updated |
| 15 | Serviceability method | `GET` with query parameters | `GET` with query parameters | Built as `POST` with a JSON body so the customer's position never appears in a URL. See ADR-013 |
