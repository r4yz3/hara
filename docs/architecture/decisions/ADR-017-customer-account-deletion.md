# ADR-017: Customers delete their own account

- Status: accepted (product owner, 2026-11-14: erasure rule for saved addresses, threat T31)
- Date: 2026-11-14
- Relates to: ADR-005 (authorization), ADR-011 (authentication), threat model T31

## Context
- A customer's saved addresses hold exact home positions. Until now there was no way to remove them short of deleting each one, and the person's account stayed.
- Audit rows are append-only and have no foreign key to `users` (they hold ids, never addresses), so they can outlive the person.
- Four foreign keys to `users` restrict deletion: `user_role_scopes`, `merchant_staff`, `riders` and `staff_invitations.invited_by`. All others cascade (`devices`, `customer_profiles`, `saved_addresses`, `privacy_consents`, MFA tables) or are not keys at all (`sessions.user_id`, `audit_logs.actor_user_id`, `personal_access_tokens`).
- Registration gives every customer a `customer` role grant in `user_role_scopes`, so that restrict applies to every customer and must be cleared first.

## Decision
1. **Route.** `DELETE /api/v1/me` on the customer app only (`EnsureSurfaceSession` + `EnsureCustomerSurface`; 404 elsewhere), CSRF-protected, in the `account-writes` throttle group. Body: the current `password`.
2. **Re-authentication.** The password is checked inside the transaction after the user row is locked. A wrong one answers 422 on `password` and counts against `account-delete:<hash of user id>`: 5 a minute (the sign-in numbers), then 429. The limit is per account, not per network.
3. **Who may delete.** Only purely-customer accounts. A live role grant other than `customer`, an active `merchant_staff` row, any `riders` row or a confirmed MFA factor answers 409 `CONFLICT` ("This account is also used for work. Ask an administrator to close it.") and changes nothing. Closing a work account is an administrator's decision. The password is checked first, so the answer reveals nothing to someone without it.
4. **What goes, in one transaction.** Saved addresses, customer profile, password reset rows, personal access tokens, devices, privacy consents, unconfirmed MFA enrolment rows, the customer role grants (live or revoked) and database-backed sessions.
5. **Hard delete, with an anonymising fallback.** The users row is deleted. `user_role_scopes`, `merchant_staff`, `riders` and `staff_invitations.invited_by` restrict that, and the rule above leaves a customer with none of them, except history: a person who once worked (a revoked grant, revoked merchant staff, invitations they sent) still has rows that other records depend on. For them the row is anonymised instead: name "Deleted account", email `deleted-<user id>@invalid.example` (lowercase; `.example` is reserved), a random password hash, no phone, no verified timestamps, status `suspended`, a rotated remember token. The 409 rule means this only ever applies to ended work history. Sign-in is impossible in both cases.
6. **Audit.** `account.deleted`, actor and subject the user id, no before or after values, no IP hash, in the same transaction. Earlier audit rows stay.
7. **Sessions.** The current session is logged out, invalidated and its CSRF token regenerated. Every other session dies on its next request: the user row is gone (hard delete) or the password hash changed and the status is suspended (anonymised), which `EnsureSurfaceSession` and `SurfaceAccess` already treat as 401. Rider tokens are deleted with the rows.
8. **Registering again.** After a hard delete the address is free and registration works as for a new person (a new id, nothing carried over). After anonymising, the old address is also free, because the stored email was replaced.

## Consequences
- Erasure is immediate and not reversible. There is no grace period or undo; a screen must say so before asking for the password.
- Privacy consents go with the account, which loses the proof that a notice was accepted. The audit row proves only that an account was closed. Retention of consent proof is a privacy-review question (RA 10173) not decided here.
- Orders, payments and payouts do not exist in Phase 1. When they do, a customer with order history will need this rule revisited: those rows will reference the user and may have to be kept (financial records), which makes the anonymising path the normal one. Migrations must stay expand-only.
- Backups taken before deletion still hold the data until they expire (runbook).
- The two-step cases (work accounts) stay with administrators; there is no self-service route for them.
- Implementation: `DeleteCustomerAccount` command, `DeleteAccountRequest`, `AuthController::deleteMe`, OpenAPI `meDelete`. Tests: `AccountDeletionTest`. CSRF on the route has not been verified with real requests (the test framework disables CSRF checks; open under E-02). The customer screen is not built.
