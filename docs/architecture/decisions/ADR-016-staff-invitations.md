# ADR-016: Staff invitations by email link

- Status: accepted (product owner, 2026-11-14: email link with a set password, 72-hour expiry, and the same grant rules for inviters as for granting)
- Date: 2026-11-14
- Relates to: ADR-005 (authorization and role grants), ADR-011 (authentication, no account-existence leaks), backlog U-05

## Context
- Backlog U-05 needs a way to bring a new person into the admin team. Until now the only path was granting a role to an existing account (`/access`), which needs a person who already has an account.
- Granting a role is governed by `RoleGrantService` (ADR-005). An invitation names a role and scope, so the same rules must apply, both when the invitation is created and when it is accepted.
- An "is there an account for this address?" lookup would tell anyone with admin reach which people have accounts (ADR-011). The invitation flow must not reveal that.

## Decision
1. **Who may invite.** An inviter with `users.roles.assign` names the person, a staff role and a scope (global or one city). `RoleGrantService` decides at invite time, and again at acceptance with the inviter as the grantor. No self-invitation, and `platform_owner` can never be invited.
2. **Invitation record and token.** A row in `staff_invitations`. Single use and valid for 72 hours. At most one live row per email, role and scope (partial unique index). The token is 256 random bits; only its SHA-256 is stored. The email carries a link to the admin app at `/invite#token=...`, so the token sits in the URL fragment and never reaches a server log or referrer. Both public endpoints, `POST /api/v1/auth/invitations/inspect` and `POST /api/v1/auth/invitations/accept`, take the token in the request body.
3. **No account lookup.** Inviting never checks whether the address has an account. On accept:
   - no account: the invitee sets a password (minimum 10 characters) and the account is created with the invited role;
   - an account exists: the invitee must enter that account's current password, and the role is attached without changing the password.
   Every accept failure (unknown, expired, used, revoked, wrong password) returns the same generic 404. Accept attempts are rate limited per token (5 failures per 15 minutes). Mail to one address is capped at 5 per day, and the create route is throttled.
4. **Accept signs nobody in.** The invitee signs in afterwards as usual. TOTP enrolment is enforced by the existing admin gate (ADR-011), not by the invitation.
5. **Audit and logs.** Audit rows and log lines carry ids and the role and scope names only, never the email address or the token. Listing and revoking pending invitations follow the `users.read` reach. Revoking follows the grant-revocation rule in ADR-005.

## Consequences
- An invitee who already has an account is told, in the email and on the page, to enter their current password. The flow does not say whether an account exists before that password is entered.
- Mail is sent synchronously in the request today. A production mail provider and a queue are needed before release (operator decision; not chosen here).
- There is no resend endpoint. To send a new link, revoke the pending invitation and invite again.
- CSRF protection on the two public routes has not yet been verified with real requests (the test framework disables CSRF checks). This is open under E-02 and must be checked before release.
- Implementation: migration `2026_11_14_000002_create_staff_invitations_table`, `StaffInvitations` command, the two public endpoints and the admin list, create and revoke endpoints, OpenAPI and the generated client. Tests: `StaffInvitationTest` and `InvitationTokenTest`. The admin screens are not yet built at the time of writing.
