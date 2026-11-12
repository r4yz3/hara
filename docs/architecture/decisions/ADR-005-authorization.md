# ADR-005: Scoped, deny-by-default authorization

- Status: accepted
- Date: 2026-11-12

## Context
The platform is multi-tenant along two axes: city (operations staff) and merchant/branch (merchant staff). Guessed or edited IDs must never cross a tenant boundary.

## Decision
A request is allowed only if all five checks pass, in this order:

1. **Actor**: authenticated, user status active.
2. **Permission**: one of the actor's non-revoked role grants carries the required permission slug.
3. **Scope**: that grant's scope (`global`, `city`, `merchant`, `branch`) covers the target.
4. **Relationship**: the target object belongs to that scope (for example the zone's `city_id`).
5. **Status**: the grant and any membership (merchant staff, rider) are active and not revoked.

Rules:
- Anything not explicitly allowed is denied. Routes without a policy fail a test that enumerates all registered routes.
- Actor and tenant come from the authenticated context. Client-supplied `user_id`, `merchant_id`, `branch_id`, `role` or `scope` values are never trusted; where a request references one, it is checked against the actor's scope.
- Lists are filtered in the query by scope, not after loading.
- An object outside the actor's scope returns `404 NOT_FOUND`. `403 FORBIDDEN` is used only when the actor may know the resource exists but lacks the action.
- Role grants are created only by `users.roles.assign` holders, only within the grantor's own scope, never above the grantor's own role, and always with an audit row in the same transaction.
- Revocation takes effect on the next request; grants are not cached beyond the request unless the cache is invalidated on revoke. A race test covers revoke-during-use.
- The permission catalogue and role mapping are data, seeded from `docs/security/permission-matrix.md`.
- Channel subscriptions (later phases) apply the same five checks at subscription time.

## Consequences
- Each admin endpoint needs a policy, a scoped query and an isolation test before it is registered.
- Every denial emits an `rbac.denied` log event without personal data.
