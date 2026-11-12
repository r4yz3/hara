# Threat model — Phase 1

- Date: 2026-11-12. Status: draft for human review. Method: STRIDE per trust boundary.
- Scope: what Phase 1 builds (identity, RBAC, cities, zones, serviceability, audit, four client shells). Ordering, payments, dispatch and tracking are listed at the end as deferred threats so they are not forgotten.

## Assets

| Asset | Why it matters |
|---|---|
| Credentials, sessions, mobile tokens | Account takeover |
| Role grants and scopes | Privilege escalation across cities or merchants |
| City and zone configuration | Decides where the service operates; a wrong zone takes orders it cannot serve or blocks a live area |
| Audit log | The only record of privileged changes |
| Personal data: name, email, phone, saved address and its point | RA 10173 obligations; a home location is sensitive |
| Secrets: `APP_KEY`, database, Redis and object-store credentials | Full compromise |

## Trust boundaries

1. Internet → reverse proxy / WAF → API.
2. Browser app origin (customer, merchant, admin) → API, by cookie.
3. Rider app → API, by bearer token.
4. API → PostgreSQL, Redis, object store (private network).
5. Browser → third-party tile and style host.
6. Developer workstation and CI → repository and registries.

## Threats and controls

| ID | Boundary | STRIDE | Threat | Control | Verified by |
|---|---|---|---|---|---|
| T01 | 2 | S | Credential stuffing or brute force on login | Per-account and per-IP throttling, generic errors, Argon2id | RateLimitTest, SessionLoginTest |
| T02 | 2 | I | Account enumeration through login, registration or recovery | Identical responses and timing-insensitive flow | SessionLoginTest, recovery tests |
| T03 | 2 | T | CSRF on cookie-authenticated mutations | CSRF token, SameSite, origin allowlist | CSRF and CORS negative tests |
| T04 | 2 | I/E | Cross-origin credentialed requests from an unlisted origin | Explicit CORS allowlist, never wildcard with credentials | CORS negative tests |
| T05 | 2 | E | Session shared between customer and admin surfaces | **Open** — ADR-011 item 1 | To be added in Sprint 4 |
| T06 | 2 | S | Session fixation or theft | Regenerate session on login, HttpOnly + Secure cookies, logout invalidates server side | SessionLogoutTest |
| T07 | 2/3 | E | Self-granted role at registration or profile update | Role and scope fields ignored on public endpoints; grants only through the audited service | RegisterCustomerTest, "customer cannot grant platform_owner" |
| T08 | 2/3 | E/I | IDOR: guessed or edited city, zone, merchant, branch or user ID | ADR-005 five checks, scoped queries, 404 for out-of-scope | CrossTenantIsolationTest, ScopePolicyTest |
| T09 | 2 | E | City manager edits a zone in another city by changing `city_id` in the body | Body IDs checked against actor scope; zone's stored city is authoritative on update | CrossTenantIsolationTest |
| T10 | 2 | E | Revoked grant still works (cache or race) | No stale grant cache; revoke race test | Role revoke race test |
| T11 | 2 | T/D | Malicious GeoJSON: huge vertex count, self-intersection, world-sized polygon, nested depth | Body size limit, vertex and extent caps, validation before SQL, DB `CHECK` | ZoneGeometryValidatorTest, GeoJSON fuzz test |
| T12 | 1 | T | SQL injection through coordinates, slugs, filters | Bound parameters only, numeric validation before query | Injection regression tests |
| T13 | 2 | T | Stored XSS through city, zone or profile names shown in admin and audit views | Output encoding by framework, CSP, no raw HTML rendering | XSS regression tests |
| T14 | 1 | D | Serviceability endpoint used for scraping zone shape or for load | Dedicated rate limit, response limited to whitelisted fields, no geometry in public responses | RateLimitTest, ServiceabilityContractTest |
| T15 | 2 | T | Concurrent zone edits overwrite each other | `version` check, 409 on stale write | Concurrent zone edit test |
| T16 | 2 | T | Zone activated in a draft city, or by someone without scope | Activation guard: city active, geometry valid, permission and scope | ZoneActivateTest |
| T17 | 4 | R | Privileged change without a record, or audit row without the change | Audit write in the same transaction | Audit atomicity test |
| T18 | 4 | T/R | Audit log edited or deleted | No update or delete path in code; DB role without UPDATE/DELETE on `audit_logs`; tamper detection **open** | Privilege test on the DB role |
| T19 | 4 | I | Secrets or personal data in audit values or logs | Redaction list (passwords, tokens, OTPs, exact coordinates), IP stored hashed | AuditRedactionTest |
| T20 | 3 | I/S | Stolen or long-lived rider token | Short expiry, restricted abilities, device binding, revocation | MobileTokenScopeTest |
| T21 | 3 | I | Background location collected before the feature exists | App does not request the permission in Phase 1; `FEATURE_RIDER_TRACKING=false` | Flutter permission test |
| T22 | 1 | I | Debug mode, stack traces or readiness detail exposed | `APP_DEBUG=false` outside local, single error envelope, `/health/ready` internal only | Production-config test |
| T23 | 4 | E | PostgreSQL, Redis or MinIO reachable from outside | Private network, no published ports outside local | Compose and deploy review |
| T24 | 5 | I | Tile host learns which areas users view; unlicensed tile use | Provider under contract, no OSM public tiles, placeholder when unset | ADR-007, manual check |
| T25 | 6 | T | Compromised dependency, action or agent skill | Lockfiles, audits, pinned action SHAs, skills only from the technology's publisher and pinned by hash | CI security workflow |
| T26 | 6 | I | Secret committed to the repository | `.env` ignored, example file has placeholders only, secret scan in CI | Gitleaks |
| T27 | 2 | S | Open redirect through the post-login return URL | Only same-origin relative paths accepted | Playwright scenario 7 |
| T28 | 1 | S | Development seeder or bootstrap command run in production | Environment gate in code, tested | Seeder gate test |

## Residual risks and open items

- T05 and the MFA factor (ADR-011) are unresolved. Privileged production login stays blocked until both are closed.
- Tamper detection for the audit log (hash chain or external sink) is not designed.
- Audit and personal-data retention periods are not defined; they depend on the privacy review.
- No penetration test is planned inside Phase 1; it belongs to Phase 6.

## Deferred threats (later phases, not addressed now)

Price and fee tampering at checkout; duplicate charges and replayed webhooks; double rider assignment; GPS spoofing and public location writes; cross-tenant channel subscriptions; proof-of-delivery image privacy; cash-on-delivery fraud; payout manipulation; unbalanced ledger postings.
