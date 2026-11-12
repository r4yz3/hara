# Food Delivery Platform — Production-Ready Phase 1 Implementation Specification

**Version:** 1.0  
**Date:** 2026-11-12  
**Base document:** `FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md`  
**Launch market:** Philippines-first; city to be selected  
**Fleet:** platform-managed riders; merchant-owned dispatch not supported in Phase 1  
**Deployment status:** engineering specification, not an implemented or certified production deployment

> **Read this first:** Phase 1 builds a secure, reproducible foundation and working role-based shells. It does **not** constitute launch-ready food ordering or operational rider dispatch. Subsequent phases implement merchant catalog, checkout, financial ledger, GPS, dispatch, and real payment processing. Do not fake these integrations or claim they exist.

---

## 1. Executive build contract

### 1.1 Goals

Deliver a mono-repository containing:
1. Laravel 13 API backend with modular domain boundaries and documented versioned REST interface.
2. PostgreSQL + PostGIS persistence, Redis, development object store, local mail capture and test databases via Docker Compose.
3. Customer Next.js PWA shell, merchant Next.js/React portal shell, admin/dispatcher portal shell, Flutter rider Android skeleton with shared branding.
4. RBAC with resource ownership and city/branch scope enforcement on the server, admin MFA scaffolding, audit events.
5. Configurable cities, polygon delivery zones, geospatial validation, and map rendering using MapLibre with a licensed tile style URL.
6. Auth, city/zone admin, serviceability, current-user and health API contracts with automated tests and OpenAPI.
7. Reproducible GitHub Actions CI, security lint, structured logs, standardized exceptions, preview/staging provision instructions.
8. Accessibility and design-system components, representative role-based screens, wireframes and screenshots from running apps.
9. Test dataset and fixture builders, including a clearly labeled demo city/zone only in development and testing.
10. Detailed future-stage API contracts and migrations as proposed designs, not falsely presented as implemented in Phase 1.

### 1.2 Out of scope in Phase 1

Live merchant onboarding approvals, menu CRUD beyond interface skeletons, cart/checkout, payment processor integration, financial ledger posting, dispatch scoring and offer acceptance, live rider location recording, push delivery notifications, public restaurant ratings, production geocoding, route-time guarantees, automated payouts. Their APIs and schema dependencies appear in appendices for forward compatibility, but must not be exposed as working routes until implemented and tested.

### 1.3 Definition of done (required)

- `make setup`, `make up`, `make migrate`, `make seed-demo`, `make check` documented and functioning, with Windows PowerShell equivalents using Docker Compose and scripts.
- API boots with isolated databases, Redis and PostGIS; DB migration fresh and rollback verified.
- User registration/login/logout/current user works under an explicitly selected secure auth design.
- Global admin can create/disable city and draw/create/disable a polygon zone; city selection never assumes coordinates or timezone without configuration.
- Serviceability endpoint returns reliable inside/outside decisions and returns a useful not-configured response when no launch zone exists.
- Server denies all cross-tenant access even if IDs are guessed or modified in browser requests.
- Customer/merchant/admin/rider navigation shells work at narrow and wide viewports; placeholder actions are disabled/labeled rather than pretending to operate.
- CI runs lint, types, test, schema validation, OpenAPI diff and security scans.
- There is an operational README, architecture decision records (ADRs), threat model and test evidence.

### 1.4 Working assumptions to keep configurable

- One currency (`PHP`) per deployment for the MVP; all amounts integer centavos, no floating-point currency math.
- Multi-city data model and APIs exist; selected city remains undefined until admin activation.
- `Asia/Manila` presentation default; UTC for storage and event timestamps.
- Public customer registration is allowed only when enabled by a feature flag. Merchant and rider access requires invitations or approved onboarding in later phases.
- Rider employment classification, pay rates, insurance, document compliance, cash remittance limits, operating hours and exact delivery zone are **business/legal decisions**, not hardcoded defaults.
- One branch per checkout, one order per delivery, at most one active accepted rider assignment; no pooling/batching in MVP.
- Web frontends use BFF-friendly authenticated cookies and CSRF; mobile uses scoped short-lived bearer tokens and revocation. Do not share an admin bearer token with a browser.

---

## 2. Design architecture

```mermaid
flowchart TD
  C[Customer Next.js PWA] --> EDGE[Reverse proxy / WAF / TLS]
  M[Merchant React portal] --> EDGE
  A[Admin and dispatch React portal] --> EDGE
  R[Flutter rider Android] --> EDGE
  EDGE --> API[Laravel 13 JSON API]
  API --> ID[Identity and scoped authorization]
  API --> GEO[City + Zone / Serviceability]
  API --> MER[Merchant and catalog module boundary]
  API --> ORD[Orders and payments boundary - future]
  API --> DIS[Dispatch and tracking boundary - future]
  API --> DB[(PostgreSQL + PostGIS)]
  API --> REDIS[(Redis cache + queue)]
  API --> OBJ[(S3-compatible storage)]
  API --> OUT[Transactional outbox table]
  DIS -.future-> ROUTE[Valhalla routing]
  C --> MAP[MapLibre rendered map]
  A --> MAP
  MAP --> TILE[Licensed vector tile style endpoint]
  OUT -.future-> EVT[Worker + WebSocket / Notifications]
```

### 2.1 Decisions

- **ADR-001 modular monolith:** one deployable Laravel service with isolated Domain/application/infrastructure layers; no premature microservice distributed transactions.
- **ADR-002 location:** PostGIS `geometry(Point,4326)` and `geometry(MultiPolygon,4326)` with GiST; use `ST_Covers` rather than `ST_Contains` when boundary points should be included. For distance, cast to `geography` in meters. Latitude and longitude are always validated in their respective bounds and coordinate ordering is consistent.
- **ADR-003 ID:** UUIDv7 or ULID chosen once and documented; use the same ID type throughout. Recommended ULID for Laravel and Postgres `char(26)` with model `HasUlids`, but UUIDv7 is equally valid. This blueprint uses ULIDs.
- **ADR-004 time:** UTC in database/API, locale time rendering in UI; include ISO8601 with offset in responses; timestamps as `timestamptz`.
- **ADR-005 authorization:** policy and query scope based on access subject, assigned city and merchant branch; deny by default.
- **ADR-006 payments:** future processor adapter; gateway choice does not leak into Orders domain.
- **ADR-007 maps:** MapLibre GL JS on web, OpenStreetMap data, tile style from a license-compatible hosted provider or self-host; no unrestricted use of OSM public tile servers. Local placeholder maps are allowed with prominent label and never claimed live.
- **ADR-008 routing:** self-hosted Valhalla future integration; test motorcycle legality and local turn restrictions before activation.
- **ADR-009 frontends:** pnpm workspace for web packages; Dart/Flutter has its own lockfile and workflow.
- **ADR-010 API:** `/api/v1`, JSON schema, cursor pagination, problem-style error envelope and explicit idempotency for mutating business endpoints.

### 2.2 Security boundaries

Trust nothing from client-supplied `user_id`, `merchant_id`, `branch_id`, fee, route length, wallet amount, or role. Derive actor/tenant from authenticated context; verify all referenced objects against their tenant ownership and scope. Public read APIs expose only published + serviceable data. No maps or proofs expose customer home addresses unless a job is active and assigned.

---

## 3. Repository structure

```text
food-delivery/
├── AGENTS.md                         # Canonical agent rules (CLAUDE.md imports it)
├── CLAUDE.md                         # Claude Code entry point: imports AGENTS.md, maps skills
├── skills-lock.json                  # Pinned agent skills (.claude/skills/)
├── README.md
├── .env.example                     # Placeholder values only
├── .editorconfig
├── .gitignore
├── Makefile
├── package.json                     # Workspace scripts
├── pnpm-workspace.yaml
├── pnpm-lock.yaml
├── apps/
│   ├── customer-web/                # Next.js App Router
│   │   ├── app/(public)/, app/(account)/, app/(order)/
│   │   ├── components/{layout,restaurant,map,forms,ui}/
│   │   ├── lib/{api,auth,schemas,formatting}/
│   │   ├── public/, tests/, e2e/
│   │   └── next.config.ts
│   ├── merchant-web/                # Next.js App Router, separate origin
│   │   ├── app/(auth)/, app/(merchant)/
│   │   ├── components/{orders,catalog,staff,shared}/
│   │   └── tests/, e2e/
│   ├── admin-web/                   # Next.js App Router, separate origin
│   │   ├── app/(auth)/, app/(admin)/, app/(dispatch)/
│   │   ├── components/{maps,metrics,tables,forms}/
│   │   └── tests/, e2e/
│   └── rider-flutter/
│       ├── lib/{app,core,features,widgets}/
│       ├── test/, integration_test/, android/
│       └── pubspec.yaml
├── packages/
│   ├── design-tokens/               # Colors, typography, spacing, semantics
│   ├── ui/                          # React components, Storybook
│   ├── api-client/                  # Generated TS from OpenAPI; never hand-edit
│   ├── schemas/                     # Zod validation contracts
│   └── config/                      # ESLint, TS config and tooling
├── backend/
│   ├── app/
│   │   ├── Domain/{Identity,Geo,Merchants,Catalog,Orders,Dispatch,Payments,Ledger,Support}/
│   │   ├── Application/{Commands,Queries,DTOs}/
│   │   ├── Infrastructure/{Persistence,Maps,Payments,Events}/
│   │   ├── Http/{Controllers/Api/V1,Requests,Resources,Middleware}/
│   │   ├── Policies/, Providers/, Jobs/, Events/
│   │   └── Support/{Money,Idempotency,Auth,Errors}/
│   ├── routes/{api.php,web.php,channels.php,console.php}
│   ├── database/{migrations,seeders,factories}/
│   ├── tests/{Unit,Feature,Integration,Contract}/
│   ├── bootstrap/, config/, composer.json, phpunit.xml
│   └── openapi/openapi.yaml
├── infra/
│   ├── docker/{php,Dockerfile,nginx,postgres}/
│   ├── compose.yaml
│   ├── compose.test.yaml
│   ├── terraform/                   # Future staging/prod infrastructure
│   ├── monitoring/{prometheus,grafana,alerts}/
│   └── scripts/{bootstrap.sh,bootstrap.ps1,wait-for-db.sh,backup-restore.sh}
├── docs/
│   ├── architecture/{diagram.md,decisions/}
│   ├── ux/{flows,screen-specs,design-system.md,accessibility.md}
│   ├── api/{conventions.md,examples,security.md}
│   ├── security/{threat-model.md,privacy.md,incident-response.md}
│   ├── operations/{runbook.md,backups.md,deploy.md}
│   ├── qa/{test-matrix.md,evidence/}
│   ├── backlog.md
│   └── PHASE1_STATUS.md
└── .github/workflows/{backend.yml,web.yml,flutter.yml,security.yml}
```

No top-level package may import directly from another app's internal directory. Shared code belongs in `packages/`. Laravel domain modules may call another domain only through an application interface; avoid circular dependency.

---

## 4. Workstation requirements and reproducible setup

**Recommended for Windows 11:** Git, Docker Desktop with WSL2, Claude Code (desktop app, CLI or VS Code extension), Node LTS supported by Next.js, pnpm via Corepack, Flutter stable Android SDK/emulator or physical Android device. Use Docker for PHP, PostgreSQL, Redis, MinIO so native PHP is optional. A GTX 1660 is not needed; this is standard software development, not GPU model training.

### 4.1 Version policy

- PHP 8.3+; Laravel 13 stable, pin exact resolved versions in `composer.lock`.
- Next.js stable release compatible with supported Node LTS; pin in `pnpm-lock.yaml` and record exact versions in `docs/architecture/versions.md`.
- PostgreSQL major version selected from a supported release (recommend 17 or 18 after image and PostGIS compatibility check); **pin image digest or patch version**, not `latest`.
- PostGIS extension package/image matched to selected PostgreSQL; Redis supported stable; Flutter stable and Dart SDK pinned/documented.
- MapLibre GL JS installed from a pinned major/minor compatible with Next.js; do not copy an unversioned CDN script into production.

### 4.2 Bootstrapping commands (examples, Claude Code verifies before running)

```bash
mkdir food-delivery && cd food-delivery
git init
corepack enable
# Claude Code scaffolds the workspace structure and package manifests first
pnpm install
# Bring up postgres, redis, minio, mailpit, PHP services from infra/compose.yaml
docker compose -f infra/compose.yaml up -d --build
# Laravel backend must exist before these commands:
docker compose -f infra/compose.yaml exec api php artisan migrate --force
docker compose -f infra/compose.yaml exec api php artisan db:seed --class=DevelopmentSeeder
pnpm --filter customer-web dev
```

Use Docker Compose service `api` consistently. A root Makefile must expose `make setup`, `up`, `down`, `migrate`, `seed-demo`, `check`, `logs`; include PowerShell alternatives in `infra/scripts/*.ps1`, because `make` is not standard on Windows. Do not use `migrate --force` against production without an approved release process. Development seeds are explicitly gated by `APP_ENV in [local,testing]`.

### 4.3 Required environment variables

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_KEY=                           # generated locally, never committed
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=food_delivery
DB_USERNAME=food_delivery
DB_PASSWORD=change_me_local_only
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CUSTOMER_APP_URL=http://localhost:3000
MERCHANT_APP_URL=http://localhost:3001
ADMIN_APP_URL=http://localhost:3002
MAP_STYLE_URL=                    # properly licensed style URL; empty = placeholder
ROUTING_BASE_URL=                 # Valhalla in later phase; empty = disabled
GEOCODING_BASE_URL=               # later phase; empty = manual pin only
DEFAULT_CURRENCY=PHP
DEFAULT_DISPLAY_TIMEZONE=Asia/Manila
FEATURE_PUBLIC_REGISTRATION=false
FEATURE_LIVE_DISPATCH=false
FEATURE_ONLINE_PAYMENTS=false
FEATURE_RIDER_TRACKING=false
```

`.env.example` contains **no real credentials**. Production uses a managed secret store or environment secret injection. CORS allowlist enumerates known origins; wildcard + cookies is forbidden. Cookies use `Secure`/`HttpOnly`/appropriate `SameSite` settings in TLS environments.

### 4.4 Local endpoints

| Service | Default local port | Production exposure |
|---|---:|---|
| Customer | 3000 | Via HTTPS reverse proxy |
| Merchant | 3001 | Via HTTPS reverse proxy |
| Admin | 3002 | Via HTTPS reverse proxy + MFA |
| API | 8000 | Via HTTPS reverse proxy |
| PostgreSQL | 5432 | Private only |
| Redis | 6379 | Private only |
| MinIO | config-dependent | Private, signed URL only |
| Mailpit | config-dependent | Local dev only |

Document ports centrally and prevent collisions in Docker Compose.

---

## 5. Database migration blueprint

### 5.1 Rules

- Separate migration per domain aggregate or related schema group; timestamped, deterministic ordering; reversible `down()` where safe.
- IDs as ULIDs (`char(26)`) and references with explicit FK constraints. For PostgreSQL, strings are case-normalized before insert.
- Required `created_at`, `updated_at`; nullable `deleted_at` only where appropriate. Never soft-delete a record that must remain in immutable financial or audit history.
- `numeric` for coordinates only in imported raw CSV; database canonical coordinates use PostGIS.
- Monetary values as `bigint` integer centavos, currency `char(3)`; no PHP float intermediate. Financial postings in later phases are append-only.
- Fields used by public discovery and lookup must be indexed with validated query plans; foreign keys indexed explicitly where necessary.
- Unique constraints scoped to tenant (e.g. `unique(merchant_id, slug)`).
- Use enum-like text with DB check constraints and PHP backed enums; avoid PostgreSQL enum migration friction unless a deliberate ADR.

### 5.2 Exact Phase 1 migration order and tables

| Seq | Migration | Tables / important columns |
|---|---|---|
| 001 | Enable extensions | `CREATE EXTENSION IF NOT EXISTS postgis` (required); optional `pgcrypto` only if needed |
| 002 | Identity base | `users(id, name, email, password_hash, phone_e164, phone_verified_at, email_verified_at, status, last_login_at, timestamps)` |
| 003 | RBAC | `roles(id, slug, label)`, `permissions(id, slug)`, `role_permissions(role_id,permission_id)` |
| 004 | Membership and scope | `user_role_scopes(id,user_id,role_id,scope_type,scope_id,created_at,revoked_at)`; scope assignment controlled via service/policy and valid scoped FK records checked at application boundary |
| 005 | Sessions and devices | `personal_access_tokens` for mobile if Sanctum is used; `devices(id,user_id,platform,push_token_ciphertext,last_seen_at,revoked_at)`; Laravel session table if needed |
| 006 | City settings | `cities(id,slug,name,country_code,currency_code,timezone,status,created_at,updated_at)`, `city_settings(id,city_id,key,value_json)` |
| 007 | Coverage geometry | `delivery_zones(id,city_id,name,slug,status,coverage geometry(MultiPolygon,4326),priority,version,created_at,updated_at)` |
| 008 | Coverage exclusions | `coverage_exclusions(id,delivery_zone_id,reason,geometry geometry(MultiPolygon,4326),active_from,active_until)` |
| 009 | Merchant identity shell | `merchants(id,legal_name,display_name,slug,status,created_at,updated_at)`, `branches(id,merchant_id,city_id,display_name,status,location geometry(Point,4326),address_text,created_at,updated_at)` |
| 010 | Membership shell | `merchant_staff(id,merchant_id,user_id,role,status)`, unique merchant-user; `merchant_branch_access(id,merchant_staff_id,branch_id)` scoped by service |
| 011 | Rider identity shell | `riders(id,user_id,status,approved_at,created_at,updated_at)`, `rider_city_access(id,rider_id,city_id,status)`, `rider_vehicles(id,rider_id,vehicle_type,plate_number_ciphertext,status)` |
| 012 | Customer and addresses | `customer_profiles(id,user_id,created_at,updated_at)`, `saved_addresses(id,user_id,city_id,tag,address_line,landmark,point geometry(Point,4326),is_default)` |
| 013 | Policy/consent | `privacy_consents(id,user_id,consent_type,notice_version,granted_at,revoked_at,source)` |
| 014 | Audit | `audit_logs(id,actor_user_id,action,subject_type,subject_id,scope_city_id,before_json,after_json,ip_hash,correlation_id,created_at)` with write-only service |
| 015 | Integration outbox | `outbox_events(id,event_type,aggregate_type,aggregate_id,payload_json,occurred_at,available_at,published_at,attempt_count)` |
| 016 | Flags | `feature_flags(id,key,description,enabled,scope_type,scope_id,updated_at)` |
| 017 | Login protection | `login_attempts` or rate-limiter adapter if needed; `mfa_factors` only with reviewed encrypted-at-rest implementation |

Create standard Laravel framework tables (migrations, jobs, failed jobs, cache, sessions, password-reset tokens as selected) via officially generated migrations. Avoid redefining tables supplied by installed first-party packages.

**Careful:** Some columns above are conceptual; inspect Laravel-generated defaults and merge rather than producing duplicated `users`/`sessions` tables. Never store raw push tokens, rider document values, session secrets, or verification codes in plaintext.

### 5.3 Sample PostGIS migration implementation

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        // Cities must already exist; choose an audited name and constraint convention.
        DB::statement(<<<'SQL'
            CREATE TABLE delivery_zones (
                id CHAR(26) PRIMARY KEY,
                city_id CHAR(26) NOT NULL REFERENCES cities(id) ON DELETE RESTRICT,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft','active','paused','archived')),
                coverage geometry(MultiPolygon,4326) NOT NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(city_id, slug),
                CHECK (ST_IsValid(coverage)),
                CHECK (NOT ST_IsEmpty(coverage))
            )
        SQL);
        DB::statement('CREATE INDEX delivery_zones_coverage_gix ON delivery_zones USING GIST (coverage)');
        DB::statement('CREATE INDEX delivery_zones_city_status_idx ON delivery_zones (city_id,status)');
    }
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS delivery_zones');
    }
};
```

This is a **reference migration**, not a full migration set. Claude Code must generate all migration files, schema consistency tests, a spatial round-trip test, and rollback tests. Always create `cities` first. An administrator-provided polygon must be sanitized into MultiPolygon geometry, bounds checked, vertex/area-limited, validated for self-intersection, and constrained to authorized scope before becoming active.

### 5.4 Serviceability SQL concept

```sql
SELECT z.id, z.city_id, z.name
FROM delivery_zones z
JOIN cities c ON c.id = z.city_id
WHERE z.status = 'active'
  AND c.status = 'active'
  AND ST_Covers(z.coverage, ST_SetSRID(ST_MakePoint(:longitude, :latitude),4326))
  AND NOT EXISTS (
      SELECT 1 FROM coverage_exclusions e
      WHERE e.delivery_zone_id = z.id
        AND (e.active_from IS NULL OR e.active_from <= NOW())
        AND (e.active_until IS NULL OR e.active_until > NOW())
        AND ST_Covers(e.geometry, ST_SetSRID(ST_MakePoint(:longitude, :latitude),4326))
  )
ORDER BY z.priority DESC, z.id ASC;
```

Apply server-side rate limiting, bounds validation, and return only a whitelisted public zone response. Multi-zone overlap resolution must be deterministic; selection of active launch zone requires admin explicit confirmation.

### 5.5 Future-phase migrations: DO NOT implement as working workflows in Phase 1

**Catalog:** categories, menus, menu_sections, products, variants, option_groups, options, availability, price histories, asset metadata. **Ordering:** carts, cart_items, checkout_quotes (versioned), orders, order_items, order_modifiers, order_amount_components, order_status_events, fulfillments, cancellations. **Delivery:** rider_shifts, rider_last_locations, rider_location_events, dispatch_runs, delivery_assignments, assignment_offers, delivery_events, delivery_proofs, ETA snapshots. **Financial:** payment_intents, payment_transactions, webhook_events, refunds, ledger_accounts, ledger_entries, ledger_postings, merchant_settlement_batches, rider_earnings, rider_cash_collections, rider_remittances, reconciliation_runs. **Operations:** merchant_documents, rider_documents, support_tickets, promos, fee_rules and commission_rules.

Before enabling future workflows, add database-level constraints preventing multiple active accepted rider assignments, duplicate gateway event IDs, negative line totals where forbidden, and unbalanced ledger postings. These require explicit concurrency tests.

---

## 6. Identity and authorization details

### 6.1 Roles

| Role key | Scope | Typical access |
|---|---|---|
| `platform_owner` | global | Configuration, security, audit and owner-level finance |
| `platform_admin` | global/city | Admin workflows excluding owner-only actions |
| `city_manager` | city | City zones, operations, merchant/rider records within city |
| `dispatcher` | city | Assignment console (future), view city rider operational availability |
| `finance` | global/city | Future settlements and reconciliation, no unrestricted role changes |
| `support` | city/global limited | Customer cases with redacted personal data |
| `merchant_owner` | merchant | Own merchant and branches |
| `merchant_manager` | merchant/branch | Own staff, menus, orders in authorized branches (future) |
| `merchant_staff` | branch | Order board in authorized branch (future) |
| `rider` | rider account/cities | Own profile and future shift/jobs |
| `customer` | self | Own addresses and orders |

`role_permissions` contains fine-grained verbs: `cities.read`, `cities.write`, `zones.read`, `zones.write`, `merchants.read`, `merchants.approve`, `riders.read`, `users.roles.assign`, `audit.read`, `dispatch.read`, `dispatch.override`, etc. A role grant is never applied from a client request without permission checks and audit logs.

### 6.2 Auth approach

- Browser origins: HTTP-only secure session cookie, CSRF protection, strict allowed origin and credential policy; secure proxy session design documented if using separate domains. Use Sanctum SPA mode only if domains/cookies are configured as supported, otherwise implement a BFF session broker with explicit threat analysis.
- Rider Flutter: Sanctum personal access tokens with restrictive abilities, short lifetimes and secure device storage. Define token rotation/revocation logic and avoid long-lived unrestricted keys.
- Password hashing via Laravel framework defaults (Argon2id when configured), verification email, login throttling, credential reset, device revocation, standardized generic login errors.
- MFA required for admin-owner and finance at go-live; do not deploy unrestricted privileged login while MFA incomplete. CI uses deterministic test-only bypass, never production.
- Avoid leaking whether an email exists in public recovery responses.
- All server policies verify actor, role permission, scope, object relationship, and status (active, approved, nonrevoked).

### 6.3 Audit records

Record timestamp, actor ID, action, target, scope, correlation ID, and minimized old/new values for sensitive configuration changes. Exclude passwords, auth tokens, card data, verification OTPs, exact GPS history and excess personal data. Audit writes must be atomic with privileged configuration changes. Restrict log viewer, set retention policy and detect tampering.

---

## 7. API contract (implemented in Phase 1)

### 7.1 Conventions

Base `/api/v1`. HTTPS in deployed environments. JSON `snake_case` consistently. ISO8601 timestamps including UTC indicator. ULID IDs as strings. All lists paginated (`limit` max 100, opaque `cursor`), always explicitly sorted. `Authorization` based on session or scoped mobile token. Return `request_id` in headers. For mutation requests support `Idempotency-Key` where needed; future financial and ordering APIs mandate it.

**Success example**
```json
{"data":{"id":"01JEXAMPLE0000000000000000","name":"Sample City"},"meta":{"request_id":"trace-id"}}
```
**Validation failure `422`**
```json
{"error":{"code":"VALIDATION_ERROR","message":"Please check the highlighted fields.","fields":{"latitude":["Must be between -90 and 90."]},"request_id":"trace-id"}}
```
Other codes: `AUTH_REQUIRED` 401, `FORBIDDEN` 403, `NOT_FOUND` 404 (prefer 404 for unauthorized object disclosure prevention), `CONFLICT` 409, `RATE_LIMITED` 429, `PROVIDER_UNAVAILABLE` 503. Do not leak DB SQL, internal keys or environment secrets in responses.

### 7.2 Public endpoints

| Method | Path | Authorization | Request | Response / behavior |
|---|---|---|---|---|
| GET | `/health/live` | public | none | Minimal liveness with no secrets |
| GET | `/health/ready` | infra/internal only | none | Dependency readiness; avoid sensitive public exposure |
| GET | `/api/v1/public/config` | public | none | supported currency, enablement, asset URLs, feature flags safe for public |
| GET | `/api/v1/public/cities` | public | cursor, limit | only `active` cities; empty until chosen |
| GET | `/api/v1/locations/serviceability` | public, limited | `latitude`,`longitude` | `serviceable`, chosen `city`/`zone` if any; `reason` when not available |

Serviceability examples:
```json
{"data":{"serviceable":false,"reason":"NO_ACTIVE_ZONE","city":null,"zone":null}}
```
```json
{"data":{"serviceable":true,"reason":null,"city":{"id":"01...","name":"Example"},"zone":{"id":"01...","name":"Core"}}}
```
Never treat `NO_ACTIVE_ZONE` as serviceable; public UX shows “Launching soon in your area.”

### 7.3 Authentication endpoints

| Method | Path | Role | Behavior |
|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | web | CSRF bootstrap if supported design selected |
| POST | `/api/v1/auth/register` | anonymous, feature gated | public customer registration; validation; no role escalation |
| POST | `/api/v1/auth/login` | anonymous | rate-limited session login; MFA challenge when required |
| POST | `/api/v1/auth/logout` | authenticated | invalidate relevant session/token |
| GET | `/api/v1/me` | authenticated | user and permitted UI modules; no secret fields |
| PATCH | `/api/v1/me` | authenticated | safe profile fields only |
| POST | `/api/v1/auth/password/forgot` | anonymous | generic success regardless of user existence |
| POST | `/api/v1/auth/password/reset` | anonymous + valid token | single-use/expiry enforced |
| POST | `/api/v1/auth/mobile/token` | approved rider, restricted | dedicated verified device/token exchange, *not* arbitrary unrestricted login |

Document web-vs-rider auth flow separately; never accept client-supplied role list on registration. Use a one-time admin bootstrap CLI command for local seed only; production owner provisioning needs a controlled, audited operation.

### 7.4 Admin endpoints

| Method | Path | Permissions | Notes |
|---|---|---|---|
| GET | `/api/v1/admin/cities` | `cities.read` | active, draft and archived with filters |
| POST | `/api/v1/admin/cities` | `cities.write` | create city, defaults `status=draft`; unique slug |
| GET | `/api/v1/admin/cities/{city}` | `cities.read` + scope | city details |
| PATCH | `/api/v1/admin/cities/{city}` | `cities.write` + scope | safe fields and explicit status transitions |
| GET | `/api/v1/admin/zones` | `zones.read` + city scope | GeoJSON geometry with client bounds |
| POST | `/api/v1/admin/zones` | `zones.write` + city scope | validated MultiPolygon GeoJSON, save draft |
| GET | `/api/v1/admin/zones/{zone}` | `zones.read` + city scope | zone GeoJSON and version |
| PATCH | `/api/v1/admin/zones/{zone}` | `zones.write` + scope | optimistic `version` check, 409 on stale edit |
| POST | `/api/v1/admin/zones/{zone}/activate` | `zones.write` + city scope | requires city active and polygon verified |
| POST | `/api/v1/admin/zones/{zone}/pause` | `zones.write` + city scope | audited pause with reason |
| GET | `/api/v1/admin/audit-logs` | `audit.read` | filter/cursor, sensitive field redaction |
| GET | `/api/v1/admin/system/status` | privileged read | health/config summary, no secrets |

**City create example**
```json
{"name":"Example City","slug":"example-city","country_code":"PH","currency_code":"PHP","timezone":"Asia/Manila"}
```
**Zone create example**
```json
{"city_id":"01JEXAMPLE0000000000000000","name":"Central Zone","slug":"central","geometry":{"type":"MultiPolygon","coordinates":[[[[125.0,7.0],[125.02,7.0],[125.02,7.02],[125.0,7.02],[125.0,7.0]]]]}}
```
These coordinates are **synthetic data for tests only**, not a recommended real service area. Verify GeoJSON long/lat order and reject polygons with fewer than four closed positions per ring, self-intersections or huge extents. Add size/vertex caps and deterministic conflicting-zone behavior.

### 7.5 Frontend API SDK

Maintain `backend/openapi/openapi.yaml` as source of truth or generate it from verified Laravel routes + schemas; one process only. Use OpenAPI 3.1 and a generated, typed TypeScript client in `packages/api-client`; Flutter can initially use a handwritten contract wrapper with serialization tests or generated Dart client if stable. All API changes update examples, codegen, tests, and changelog. Do not edit generated clients manually.

### 7.6 Planned endpoints for future phases (CONTRACT ONLY)

- Merchants: `/api/v1/admin/merchants`, `/api/v1/merchant/branches`, `/api/v1/merchant/catalog`, `/api/v1/merchant/orders/{id}/accept|reject|ready`.
- Customer: `/api/v1/restaurants`, `/api/v1/restaurants/{id}/menu`, `/api/v1/carts`, `/api/v1/checkout/quote`, `/api/v1/orders`, `/api/v1/orders/{id}`, `/api/v1/orders/{id}/cancel`.
- Rider: `/api/v1/rider/shifts`, `/api/v1/rider/offers`, `/api/v1/rider/offers/{id}/accept|decline`, `/api/v1/rider/jobs/{id}/picked-up|delivered`, `/api/v1/rider/location`.
- Dispatch: `/api/v1/admin/dispatch/queue`, `/api/v1/admin/dispatch/map`, `/api/v1/admin/assignments/{id}/reassign`.
- Payments: `/api/v1/payments/intents`, `/api/v1/webhooks/payments/{provider}`, `/api/v1/admin/payments/reconciliation`.
- Realtime channels: private `order.{id}`, `merchant.branch.{id}`, `rider.{id}`, `ops.city.{id}` with permission checked **at subscription**, not only when API data is fetched.

Do not return 200/empty mock arrays for supposedly live future endpoints; return `404` or `501` in local development where appropriate, and leave production routes unregistered until the feature exists.

---

## 8. Detailed UX/UI specification

### 8.1 Product design values

Original, modern, calm, practical, high-trust. Food-forward customer experience; high-density but usable admin operations; rider-first safety and touch accessibility. Do not copy existing marketplace logos, trademarked phrases, UI screenshots, or source code.

**Design tokens (initial proposal; refine in Figma/Storybook):**

| Token | Light | Dark | Meaning |
|---|---|---|---|
| `--brand-600` | `#0F766E` | `#2DD4BF` | Primary CTA and active states |
| `--brand-700` | `#115E59` | `#5EEAD4` | Hover/focus emphasis |
| `--accent-500` | `#F97316` | `#FB923C` | Food/category highlights only |
| `--surface` | `#FFFFFF` | `#111827` | Canvas |
| `--surface-2` | `#F8FAFC` | `#1F2937` | Cards/panels |
| `--text-primary` | `#0F172A` | `#F9FAFB` | Primary content |
| `--text-secondary` | `#475569` | `#CBD5E1` | Secondary text |
| `--border` | `#E2E8F0` | `#374151` | Separators |
| `--danger` | `#B91C1C` | `#F87171` | Destructive/error |
| `--success` | `#15803D` | `#4ADE80` | Positive/completed |

Colors are a starting palette, **not** proof of WCAG compliance. Verify all actual foreground/background combinations in design tokens, icons, maps and states; adjust as necessary. Typography: `Inter`/system sans or properly licensed substitute, 14–16px body, 1.4–1.6 line height; monospace for IDs/logs. Spacing `4/8/12/16/24/32/48`, rounded 12–16px cards, 8px controls, subtle shadows. Minimum 44x44px primary touch targets. Focus ring >=2px and visible; reduced-motion support. Screen reader labels, language attributes and keyboard focus management are mandatory.

**Breakpoints:** `xs 360px`, `sm 640px`, `md 768px`, `lg 1024px`, `xl 1280px`, `2xl 1536px`. Do not force horizontal scrolling on main transactional flows. Charts and wide tables get alternative compact card/scroll patterns.

### 8.2 Customer navigation and screen specs

| Route | Screen | Critical elements | Empty/loading/error behavior |
|---|---|---|---|
| `/` | Discovery home | header/logo, address field, sign-in, cuisine chips, search bar, featured/restaurants grid, footer | skeleton, service-unavailable message, retry |
| `/location` | Set delivery location | manual text, GPS permission optional, MapLibre draggable pin, landmark/notes, confirm | denied location → manual; not-in-zone → waitlist teaser |
| `/restaurants` | Listings | city/zone, category, search/filter, restaurant cards, open status, fee/ETA placeholders until verified | no city → clear launch city disabled state |
| `/restaurants/[slug]` | Restaurant detail (future) | hero, cuisine chips, menu sections, modifier drawer, sticky cart | unavailable → no order actions |
| `/cart` | Cart (future) | item recap, modifiers, promo, fees/total, checkout CTA | empty cart illustrated state |
| `/checkout` | Checkout (future) | address, summary, COD/online payment, final consent, idempotent submit | retry without duplicate order |
| `/orders/[id]` | Tracking (future) | timeline, live MapLibre map, route, ETA, help | disconnected → last seen and reconnection banner |
| `/account` | Profile | personal details, addresses, preferences, privacy | separate verification states |
| `/login` and `/register` | Authentication | accessible inputs, validation, recovery, secure session | no account-existence leakage |

**Phase 1 functional subset:** `/`, `/location`, `/login`, `/account`, city/serviceability discovery; restaurant/list/cart/checkout/tracking render high-quality **clearly labeled nonfunctional previews** or remain behind feature flags. Do not show fake open restaurants or live delivery ETAs.

**Customer desktop wireframe:**
```text
┌───────────────────────────────────────────────────────────────────────┐
│ BRAND   Deliver to [Choose address      ▼]   [Search dishes    ]  Me │
├───────────────────────────────────────────────────────────────────────┤
│ Taste your city               [City not configured — admin setup]     │
│ [Cuisine chips] [More filters]                                        │
│                                                                       │
│ Restaurants near you                           [Sort]                │
│ [Card/image, details] [Card/image, details] [Card/image, details]     │
│                                                                       │
│ Help · About · Privacy · Terms                                        │
└───────────────────────────────────────────────────────────────────────┘
```

**Mobile:** 360–430px viewport; sticky location selector, search beneath, 2-row horizontally scrollable cuisine chips, one-column restaurant cards, bottom safe-area nav on signed-in screens. Never obstruct form submit with the mobile keyboard.

### 8.3 Merchant portal screen specs

| Route | Surface | Phase 1 behavior |
|---|---|---|
| `/merchant/login` | Secure sign-in | functional where invited user exists |
| `/merchant` | Overview | scoped shell, no fabricated sales/finance data |
| `/merchant/branches` | Branch selector | read-only own branch shell; empty message when no branch assigned |
| `/merchant/orders` | Order management | future skeleton with disabled action until phase 4 |
| `/merchant/menu` | Menus and stock | future skeleton |
| `/merchant/staff` | Staff access | role and scope read-only or gated |
| `/merchant/settings` | Branch profile | safe read-only context initially |

Layout: top bar contains branch selector, system status, notifications, profile; desktop 256px navigation; tablet collapsible navigation; mobile bottom nav for Orders/Menu/Overview. Kitchen/order card targets 48px+, distinct New/Preparing/Ready columns on tablets and horizontal tabbed lanes on mobile. Order countdown must use server time in later phase; never use local clock as authoritative.

### 8.4 Rider Flutter screen specs

| Screen | Key elements | Phase 1 behavior |
|---|---|---|
| Splash / version check | Branding, network check | working shell |
| Login | verified account, device registration, recovery | auth flow if implemented safely |
| Home/Shift | large Online/Offline toggle, status, city scope | disabled until onboarding and shift backend implemented |
| Offer sheet | payout, pickup ETA, accept/decline, countdown | mock-only in widget tests; no live offer |
| Pickup route | MapLibre-based map integration, stop details, proof | design/system contract only |
| Delivery route | customer pin, safety, COD, proof | design/system contract only |
| Earnings | daily balance, remittances, history | future shell |
| Account | rider profile, vehicle, docs and privacy | own-profile read |

Mobile controls must work one-handed. Rider background location permissions must not be requested until the location feature is implemented, policy-approved, and explicitly enabled with a visible status indicator. Never require interaction while the vehicle is moving. Provide explicit offline mode and reconnection states.

### 8.5 Super admin and dispatch navigation

**Admin side navigation:** Overview, Cities, Delivery Zones, Merchants, Riders, Customers, Orders (future), Live Dispatch (future), Pricing (future), Finance (future), Promotions (future), Support (future), Reports (future), Roles & Access, Audit Log, Integrations, Settings. Show locked/coming-soon labels and do not render deceptive KPIs.

**Phase 1 primary screens:**

1. `/admin/login`: staff MFA gate, minimal distraction, protected routes.
2. `/admin`: configuration readiness checklist, city activation status, zone count, health statuses, actionable setup tasks.
3. `/admin/cities`: searchable list, draft/active chips, create/edit form, time zone and currency; sensitive status changes confirm.
4. `/admin/cities/[id]`: city summary, zone list, activation rules, audit history; clear not-configured state.
5. `/admin/zones`: map left 65%, editor right 35% at >=1280px; city selector, draw polygon/multipolygon, undo, save draft, validate, activate/pause with reason; at small screens map above details with fixed action bar.
6. `/admin/users`: permission-aware lookup, detail drawer, scoped role visibility; role assignment only if safe service and audit implemented.
7. `/admin/audit`: read-only audit log with timestamp, actor, action, city, subject; filter + cursor; no raw secrets.
8. `/admin/settings`: public UI flags and integration health summary, never expose secret values.

**Dispatch view specification for later milestone:** persistent map with rider status markers, unassigned order queue and SLA alerts; inspect rider/stop and manual reassignment; GPS freshness labels, multi-filter, accessibility list equivalent. In Phase 1 show explicitly disabled preview.

**Admin wide-screen wireframe:**
```text
┌──────────────────────────────────────────────────────────────────────────┐
│ BRAND · OPERATIONS     [City: Not selected ▼]   Search  Alerts  Profile │
├──────────────────┬───────────────────────────────────────────────────────┤
│ Overview         │  Setup readiness                         [Checklist] │
│ Cities           │  [City configured?] [Zones active?] [API healthy?]    │
│ Delivery Zones   │                                                       │
│ Merchants        │  City and zone configuration                        │
│ Riders           │  ┌───────────────────────┬────────────────────────┐ │
│ Users & Roles    │  │ MapLibre map         │ Zone editor            │ │
│ Audit Logs       │  │                       │ Name / GeoJSON         │ │
│ Settings         │  │ draw and select       │ validation / version   │ │
│                  │  └───────────────────────┴────────────────────────┘ │
└──────────────────┴───────────────────────────────────────────────────────┘
```

### 8.6 Map widget acceptance

- MapLibre loaded client-side to avoid SSR `window` issues.
- Map style provided by `MAP_STYLE_URL`, with attribution displayed and provider licensing verified.
- No active city → show an empty-state panel **without arbitrary Manila/Davao centering**.
- If city exists but no valid geometry, show city detail and clear “Define coverage zone” prompt, not a fake coverage area.
- Polygon edit includes center/zoom, vertex handles, undo, redo, invalid ring warnings, altitude ignored, accessible GeoJSON text input alternative.
- API geometry is authoritative after save; client re-fetch and show stored normalized geometry/version.
- Color is never sole carrier of zone status; textual legend and accessible table mirror map.
- A failed style load produces retry and manual coordinate entry; no blocker to saving a valid zone as GeoJSON when map tiles unavailable.

### 8.7 UI state matrix (every view)

`loading`: skeleton, no flashing bad content; `empty`: clear instruction; `error`: contextual inline message with retry; `forbidden`: not authorized + back navigation; `offline`: cached read-only where safe with stale timestamp; `success`: toast + persisted state verification; `validation`: per field message and focus first invalid; `conflict`: 409 stale version prompts reload; `slow`: indeterminate progress + prevent duplicate submits.

### 8.8 Localization and accessibility

English as initial interface; content architecture permits Filipino/localization keys later. PHP display uses `Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'})`; never put currency symbols in database. Dates shown using city timezone and explicit time zone for operational screens. WCAG 2.2 AA target; keyboard nav, forms with proper labels, screen reader announcements, color contrast checks, large tap targets, no motion-required actions, localized validation. Verify on Chrome, Firefox, Safari mobile where feasible, and Android WebView.

---

## 9. Phase 1 implementable vertical slices

### Slice A: Repo and CI scaffold

**Tasks:** repo structure, Docker, versions file, `.env.example`, health routes, lint/static analysis, pnpm workspace, Flutter skeleton, root task aliases. **Tests:** `docker compose config`, service readiness, `php artisan about`, TypeScript build, Flutter analyze. **Exit:** clean clone boots without manual undocumented modifications.

### Slice B: Identity and secure permissions

**Tasks:** migrations, auth, passwords, email verification hooks, scoped role tables and policies, audit service, test seeder. **Tests:** login/logout/current-user, invalid password, rate limit, customer cannot grant `platform_owner`, revoked role denies, cross-city/cross-merchant access denied. **Exit:** all policies enforced on API routes and queries.

### Slice C: City/zone spatial MVP

**Tasks:** city lifecycle, PostGIS polygon validation and storage, `serviceability` lookup, exclusions, optimistic versioning and audit. **Tests:** inside/outside/on-boundary, exclusion, overlapping zones, invalid self-intersecting polygon, no active city, activation permission, stale update, invalid coordinate. **Exit:** admin can configure city and zone without touching code, frontends know serviceability.

### Slice D: Design system and role-specific shells

**Tasks:** tokens, forms, tables, error handling, auth guard, loading/empty states, customer map pin + zone checker, admin city/zone editor, merchant shell, rider shell. **Tests:** Playwright UI smoke across 375px/768px/1440px, axe accessibility checks, keyboard flows, Flutter widget tests. **Exit:** screenshots and signed-off visual checklist generated from actual running screens.

### Slice E: Security and operations baseline

**Tasks:** structured logging with request IDs, CSP/CORS/cookie review, secrets policy, deploy docs, monitoring targets, database backup script, restore test, incident runbook, permission matrix review, dependence audit and SBOM when tools support it. **Tests:** no exposed debug routes in production config; secret scan; backup restore to new DB; error boundaries; production build. **Exit:** staging deploy is repeatable and auditable, not yet customer-launch approved.

---

## 10. Test plan and exact verification commands

### 10.1 Unit and backend integration (minimum)

**Domain tests:** CitySlugTest, CityStatusTransitionTest, ZoneGeometryValidatorTest, ServiceabilityQueryTest, PermissionResolverTest, ScopePolicyTest, AuditRedactionTest, CoordinateBoundsTest, CurrencyAmountTest, FeatureFlagsTest.

**Feature/contract tests:** RegisterCustomerTest, SessionLoginTest, SessionLogoutTest, MeEndpointTest, AdminCityCreateTest, ZoneCreateTest, ZoneActivateTest, PublicCitiesTest, ServiceabilityContractTest, RateLimitTest, CrossTenantIsolationTest, MobileTokenScopeTest, ApiErrorEnvelopeTest.

**Property and race tests:** random valid polygons + point queries, malformed GeoJSON fuzzing within caps, concurrent zone edits detect optimistic version conflict, simultaneous privileged role updates and revoke (deny after revoke), repeated POST with idempotency where enabled.

**SQL checks:** `ST_IsValid`, `ST_Covers`, exclusion boundaries, GiST index exists, EXPLAIN plan of serviceability at seeded representative scale, FK cascade/restrict semantics.

### 10.2 Frontend E2E

Playwright scenarios:
1. Landing loads with no configured city and does not show invented restaurants.
2. Enter coordinates outside zone → unavailable state.
3. Admin logs in and creates draft city; unauthorized customer cannot reach admin API.
4. Admin draws valid polygon and saves; refresh retains exact normalized bounds.
5. Admin activates city/zone; customer entered point inside is eligible.
6. Bad polygon shows actionable error without storing invalid geometry.
7. Bad session displays login, preserves safe intended return URL, does not redirect externally.
8. Browser viewport 375×812 and 1440×900 has no horizontal overflow, controls visible.
9. Keyboard-only navigation can open/close modal and restore focus.
10. Forced API error displays retry without duplicate submission.

### 10.3 Flutter tests

Splash, sign-in form validation, no-token guarded route, online toggle disabled until enabled, device permission explanations without actually requesting background location, offline banner, semantic labels, mock API contract parsing.

### 10.4 Security gates

- Static analysis (PHPStan/Larastan at enforced level, PHP Pint, ESLint, TypeScript strict, `flutter analyze`).
- Dependency vulnerabilities reviewed (`composer audit`, `pnpm audit`, Flutter pub audit if compatible tool available); failures require classification, not blind ignore.
- Secret scan (Gitleaks or equivalent), container scan (Trivy or equivalent), Renovate/Dependabot PR workflow.
- Missing auth route assertions, IDOR tests, CSRF/cookie checks, origin allowlist, CORS negative tests, rate limit tests.
- SQL injection and XSS regression cases for coordinates, names, audit views and user profile fields.
- No public write access to PostGIS, Redis, MinIO, or private admin health diagnostics.

### 10.5 Target scripts

```bash
# Once generated in repository:
make check
# Must run these (or documented equivalents):
cd backend && php artisan test
cd backend && vendor/bin/pint --test
cd backend && vendor/bin/phpstan analyse
pnpm lint && pnpm typecheck && pnpm test && pnpm build
pnpm --filter admin-web exec playwright test
pnpm --filter customer-web exec playwright test
cd apps/rider-flutter && flutter analyze && flutter test
```

Run backend tests in an isolated PostGIS test database, not sqlite (spatial behavior matters). For each failed command Claude Code fixes it, reruns the test, and records actual output in `docs/qa/evidence/` or a summary with attached CI link; never invent passing results.

---

## 11. Environment and deployment

### 11.1 Environments

- **local:** Docker Compose, fake/test accounts, Mailpit, no external money or GPS.
- **CI:** ephemeral PostGIS/Redis, disposable data, isolated secrets, tests + scans.
- **staging:** production-like TLS, backups, non-production API secrets, deploy/rollback test; synthetic merchants/riders labeled demo.
- **production:** create only after full operational and legal readiness including Phase 2–6 gates; Phase 1 alone is not public launch-ready.

### 11.2 Infrastructure baseline

Single region near target market where possible, two-tier app/backend, private DB and Redis, network egress policies, HTTPS proxy/WAF, separate worker process, persistent object storage, automatic backups and restore drills, metrics on errors/latency/queue/cache/DB. Immutable deployment artifacts, zero secrets in Git, database migration-before-rollout plan tested in staging, rollback of application separate from irreversible DB schema changes.

### 11.3 Monitoring

Structured events `http.request`, `auth.login`, `rbac.denied`, `city.updated`, `zone.activated`, `zone.validation_failed`, `outbox.failure`; request correlation ID; trace unexpected 500s; alert for 5xx rate, DB unavailable, error spikes, migration mismatch, job queue depth, backup failure, disk exhaustion. Avoid logging exact lat/lng for customer addresses unless policy-authorized and necessary; minimize PII.

### 11.4 Backups and recovery

Document daily full backup and point-in-time recovery if supported; keep backup copies protected, encrypted, retention defined and restore tested. Phase 1 gate: simulate lost development/staging DB, restore into a new DB, verify migrations and spatial data and log evidence. Production RPO/RTO are targets subject to infrastructure and cost validation, not guaranteed by presence of a backup script.

---

## 12. Sprint-by-sprint Claude Code execution plan

**Sprint 1 — discovery / ADRs (no feature code):** inspect master plan; write `docs/backlog.md`, `docs/architecture/versions.md`, ADR-001..010, threat model, permission matrix, phase boundaries and acceptance tests. Gate: documented assumptions and issues, no silent city guess.

**Sprint 2 — repo and infra:** initialize workspace, backend and Next.js projects, Flutter skeleton, Docker Compose, local readiness. Gate: clean clone boot smoke with logs.

**Sprint 3 — database:** create spatial/identity/merchant/rider shell migrations, seeds and model factories. Gate: migrate fresh/rollback and PostGIS tests green.

**Sprint 4 — auth and RBAC:** framework auth, scopes, policies, session/mobile contract, audit, tests. Gate: IDOR and escalations denied, no privileged routes without auth.

**Sprint 5 — city and serviceability API:** city CRUD, zone CRUD/drawing backend, geo containment, exclusions and OpenAPI codegen. Gate: GeoJSON boundary tests and API contract tests green.

**Sprint 6 — UI design system:** tokens, Storybook, reusable nav/form/table/map, customer home/location, merchant shell, admin cities/zones, Flutter login shell. Gate: responsive screenshots, UI states and accessibility checks.

**Sprint 7 — observability and staging:** logs, CI hardening, backups/restore, security scans, staging deployment guide. Gate: production-like staging with documented rollback and no critical security findings.

**Sprint 8 — Phase 1 acceptance:** run complete checks, fix findings, assess acceptance criteria, generate `docs/PHASE1_STATUS.md` and next-phase backlog with specific open decisions. Gate: all critical tests pass; any open critical failure blocks phase completion.

Each sprint should be divided into reviewable commits by vertical slice. Do not continue when a blocking security, schema, migration, or test failure remains.

---

## 13. Claude Code engineering policy (`AGENTS.md` content to create)

1. Read the master plan and this Phase 1 document before editing anything; report discrepancies in `docs/architecture/decisions/`.
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

---

## 14. Prompt to paste into Claude Code (step-by-step executor)

```text
You are the lead engineer and QA reviewer for my Philippines-first multi-vendor
food delivery application. Read these files in the repository first:
1. FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md
2. FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md

Deliver Phase 1 only. Launch city is UNSELECTED: never hardcode Manila,
Davao or any other operating city. Fleet is PLATFORM-MANAGED RIDERS.
Use Laravel 13 modular monolith, PHP >= 8.3, PostgreSQL/PostGIS, Redis,
Next.js TypeScript apps for customer/merchant/admin, Flutter Android rider
skeleton, MapLibre GL JS, licensed OSM-data tiles, and future Valhalla adapter.

FIRST: inspect local tools and existing files; check requirements/versions,
write ADRs, backlog, acceptance checklist and AGENTS.md. Do not overwrite
work that already exists. Next execute sprints 2-8 one vertical slice at a time.

For each slice:
A. List exact files to change and explicit acceptance tests.
B. Implement schema, policies, controller/service, OpenAPI, typed client,
   frontend, and tests where applicable.
C. Execute real format/lint/type/unit/integration/E2E/security commands.
D. Fix failures, rerun, and document exact observed results and screenshots.
E. Update PHASE1_STATUS.md with changes, known limitations and next steps.
F. Stop on unresolved critical failures; do not report success falsely.

Priorities: city-agnostic configuration, secure scoped RBAC, correct PostGIS
geometry, cookie/CSRF safety, admin city + zone editor, serviceability API,
accessible original UI, reproducible Docker, and green CI.

Do not implement real ordering, payment, dispatch, GPS tracking, restaurant
onboarding, or payouts until the later-phase requirements and policies are
approved. Place future links behind clearly labeled feature flags.

At the end deliver:
- runnable repository and setup commands,
- exact API spec and migration inventory,
- role/permission matrix and security threat model,
- screenshots of customer/merchant/admin/rider shells,
- automated test report and failures if any,
- staging deployment + rollback plan,
- Phase 1 acceptance checklist and Phase 2 backlog.

Begin by reading the documents and reporting the first small implementation
slice, then implement it. Do not ask me to choose the launch city yet.
```

---

## 15. Checklist for human acceptance

### Build and architecture
- [ ] Clean-clone setup works on documented Windows/WSL2 or Linux environment.
- [ ] All service containers start; PostGIS and Redis reachable only as intended.
- [ ] Exact lockfiles, license inventory, version manifest committed.
- [ ] `AGENTS.md`, ADRs, architecture diagram, threat model reviewed.

### API / database
- [ ] Fresh migration, rollback, remigration, seeder and spatial tests pass.
- [ ] API contracts align with server responses and generated client.
- [ ] No-active-city response does not invent a default location.
- [ ] Valid MultiPolygon zones saved, indexed and correctly queried.
- [ ] Overlapping/invalid/boundary/exclusion geometry tests pass.
- [ ] Tenant/city access and privileged action tests pass.

### UI
- [ ] Customer pages support location selection and clear unavailable states.
- [ ] Admin can create/activate/pause a configured city and draw/save zone.
- [ ] Merchant and rider apps have authorized shells, not fake live order data.
- [ ] Keyboard, screen reader semantics and color contrast checked.
- [ ] 360px / 768px / 1440px layouts reviewed with real screenshots.
- [ ] Proper map attribution, valid tile access terms, error fallback.

### Security / operations
- [ ] MFA for admin production access is integrated or production admin deployment remains blocked.
- [ ] Cookies, CSRF, CORS and rate limiting verified.
- [ ] No privilege escalation, IDOR or cross-city leaks in tests.
- [ ] No secrets in repo/logs; dependencies and containers scanned.
- [ ] Staging database backup restored successfully.
- [ ] CI passes and test artifacts are recorded.

### Phase boundary
- [ ] No real payment, dispatch, tracking or settlement falsely exposed.
- [ ] Business approvals and external provider dependencies listed.
- [ ] Scope and data model compatible with platform-managed riders.
- [ ] `PHASE1_STATUS.md` distinguishes done/partial/blocked/future accurately.

---

## 16. Future critical decisions before launching operations

1. Launch city and polygon boundaries, postal normalization and precise motorcycle road constraints.
2. Original brand/name, domain, support contact, brand assets and language policy.
3. Legal entity and merchant-of-record, collection and settlement contracts, taxes and invoicing obligations.
4. Rider engagement arrangement, licensing, training, safety, insurance, device policy and cash holding limits.
5. Payment gateway and supported GCash/Maya/card flows, webhook and refund terms.
6. Customer cancellation windows, merchant SLA, offline prep rules and incident escalation.
7. Rider pay vs delivery fee economics, merchant commission and subsidy ownership.
8. Hosting budget, concurrency targets, SMS/notification provider and production tile/data hosting.
9. Data privacy officer responsibilities, privacy notice, retention windows, lawful basis and breach response review.
10. Actual motorcycle-based route quality and local pilot results for ETA calibration.

These decisions are deliberately not invented by the implementation agent.

---

## 17. Verified public technical references (November 2026)

- Laravel 13 installation: https://laravel.com/framework/docs
- Laravel 13 deployment/PHP requirement: https://laravel.com/framework/docs/deployment
- Next.js App Router: https://nextjs.org/docs/app
- MapLibre GL JS: https://maplibre.org/maplibre-gl-js/docs/
- OpenStreetMap public tile policy: https://operations.osmfoundation.org/policies/tiles/
- PostGIS documentation: https://postgis.net/documentation/
- Valhalla: https://github.com/valhalla/valhalla
- Laravel Sanctum: https://laravel.com/docs/13.x/sanctum
- Laravel migrations: https://laravel.com/docs/13.x/migrations
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/

**License reminder:** MapLibre rendering is open-source; map data, tile delivery, hosting and geocoding each have separate operational and licensing requirements. All production services need valid terms and capacity planning.

---

## 18. Agent skills and tooling integration (added 2026-11-12)

The implementation agent is Claude Code. `CLAUDE.md` is its entry point and imports `AGENTS.md` (section 13). Skills are installed per project in `.claude/skills/` and pinned by content hash in `skills-lock.json`; both are committed. Only skills from the technology's own publisher were installed (rule 16: minimal, audited dependencies). Skills are guidance only: where a skill and this specification disagree, this specification wins and the disagreement is recorded as an ADR.

| Area | Skills | Source | First used |
|---|---|---|---|
| Laravel backend | `laravel-best-practices` | `laravel/boost` | Sprint 2–5 |
| PostGIS / SQL | `postgis`, `sql-programming` | `postgis/postgis` | Sprint 3, 5 |
| Docker / Compose | `docker-project-foundations`, `docker-compose-patterns`, `docker-build-strategies`, `docker-destructive-guardrails` | `docker/skills` | Sprint 2, 7 |
| CI | `github-actions-hardening` | `github/awesome-copilot` | Sprint 2, 7 |
| Web UI | `tailwindcss-development`, `shadcn` | `laravel/boost`, `shadcn-ui/ui` | Sprint 6 |
| Maps | `maplibre-tile-sources`, `maplibre-source-wiring`, `maplibre-pmtiles-patterns`, `maplibre-fonts-glyphs`, `maplibre-sprites-icons`, `maplibre-v6-migration`, `maplibre-native-gl-js-parity` | `maplibre/maplibre-agent-skills` | Sprint 6 |
| Rider app | `flutter-apply-architecture-best-practices`, `flutter-setup-declarative-routing`, `flutter-implement-json-serialization`, `flutter-build-responsive-layout`, `flutter-add-widget-test`, `flutter-add-integration-test`, `dart-add-unit-test`, `dart-run-static-analysis` | `flutter/agent-plugins` | Sprint 2, 6 |
| Already on the workstation (user level) | `vercel-react-best-practices`, `playwright-best-practices`, `web-design-guidelines`, `impeccable` | — | Sprint 6, 8 |

Update or re-verify with `npx skills check` / `npx skills update`; review the diff of `.claude/skills/` before committing an update.

**Not covered by a vetted skill:** Next.js App Router conventions, OpenAPI 3.1 authoring, Sanctum SPA/cookie auth, Valhalla. No publisher-owned skill was found for these; use the official documentation in section 17.

**Workstation gaps found on 2026-11-12 (block Sprint 2, not Sprint 1):**
- Docker Desktop is not installed; section 4 requires it for PostgreSQL/PostGIS, Redis, MinIO and Mailpit.
- The Laravel Boost MCP server cannot connect until `backend/` exists and `laravel/boost` is installed there as a dev dependency.
- Present: Git, Node 24, pnpm, PHP and Composer (Herd Lite), Flutter, GitHub CLI. Exact versions are recorded in `docs/architecture/versions.md` in Sprint 1.
