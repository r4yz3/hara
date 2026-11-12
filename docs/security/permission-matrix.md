# Role and permission matrix — Phase 1

- Date: 2026-11-12. Status: **proposed, needs human approval before it is seeded** (Sprint 4).
- Roles and their scopes come from spec section 6.1. Permission slugs come from 6.1 plus three added for Phase 1 endpoints: `users.read`, `system.status.read`, `flags.write`.
- A tick means the role carries the permission **within its scope only**. Enforcement follows ADR-005.

## Scope of each role

| Role | Scope type | Assigned by |
|---|---|---|
| `platform_owner` | global | Controlled bootstrap procedure only |
| `platform_admin` | global or city | `platform_owner` |
| `city_manager` | city | `platform_owner`, `platform_admin` covering that city |
| `dispatcher` | city | `platform_admin`, `city_manager` of that city |
| `finance` | global or city | `platform_owner` |
| `support` | global or city | `platform_admin` |
| `merchant_owner` | merchant | Onboarding approval (later phase) |
| `merchant_manager` | merchant or branch | `merchant_owner` (later phase) |
| `merchant_staff` | branch | `merchant_owner`, `merchant_manager` (later phase) |
| `rider` | own account, cities | Rider approval (later phase) |
| `customer` | self | Registration |

Nobody can assign a role equal to or above their own, or outside their own scope. `platform_owner` is never assignable through the API.

## Permissions

| Permission | owner | admin | city_mgr | dispatcher | finance | support | merchant_* | rider | customer |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `cities.read` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | | |
| `cities.write` | ✓ | ✓ | | | | | | | |
| `zones.read` | ✓ | ✓ | ✓ | ✓ | | ✓ | | | |
| `zones.write` (incl. activate, pause) | ✓ | ✓ | ✓ | | | | | | |
| `merchants.read` | ✓ | ✓ | ✓ | | ✓ | ✓ | | | |
| `merchants.approve` (unused in Phase 1) | ✓ | ✓ | | | | | | | |
| `riders.read` | ✓ | ✓ | ✓ | ✓ | | | | | |
| `users.read` | ✓ | ✓ | ✓ | | | ✓ (redacted) | | | |
| `users.roles.assign` | ✓ | ✓ | ✓ (dispatcher only) | | | | | | |
| `audit.read` | ✓ | ✓ | | | | | | | |
| `system.status.read` | ✓ | ✓ | | | | | | | |
| `flags.write` | ✓ | | | | | | | | |
| `dispatch.read` (unused in Phase 1) | ✓ | ✓ | ✓ | ✓ | | | | | |
| `dispatch.override` (unused in Phase 1) | ✓ | ✓ | ✓ | ✓ | | | | | |

Not permissions, enforced by ownership instead:
- Any authenticated user: read and update their own profile (`/me`), their own saved addresses, their own consents.
- Merchant roles: read their own merchant and assigned branches (shell only in Phase 1).
- Rider: read their own rider profile.

## Questions for the reviewer

1. Should `city_manager` create or pause a **city**, or only its zones? Proposed: zones only.
2. Should `city_manager` read the audit log for their own city? Proposed: no in Phase 1, because the viewer's redaction rules are not reviewed.
3. Should `finance` have any Phase 1 access beyond read-only cities and merchants?
4. Should `support` see zone geometry, or only zone names?
5. Is a city-scoped `platform_admin` allowed to assign `city_manager` in that city? Proposed: yes.
