# ADR-018: First platform owner by console command

- Status: proposed (built and tested locally 2026-11-14; needs the operator's review before use on staging)
- Date: 2026-11-14
- Relates to: ADR-005 (`platform_owner` is never grantable through the application), ADR-011 (staff sign-in and TOTP), ADR-016 (invitations), backlog E-07

## Context
- On staging `DevelopmentSeeder` refuses to run and `DEMO_PASSWORD` must be unset, so no account can hold `platform_owner`. Further staff are invited by an existing owner (ADR-016), so nobody can sign in to the admin app.
- ADR-016 invitations cannot carry the role: `RoleGrantService` refuses `platform_owner`, and an invitation needs an inviter (`invited_by` was NOT NULL).
- No password may be generated, printed or stored by the operator tooling.

## Decision
1. **Command.** `php artisan staff:bootstrap-owner {email} [--name=] [--replace]`. Console only: no route, no controller. It prints an acceptance link and creates nothing else.
2. **Only while there is no owner.** It refuses (non-zero exit) when any active user holds a live `platform_owner` grant. The check runs again when the invitation is accepted, so an invitation issued earlier cannot create a second owner. Both checks run under one Postgres advisory lock (`bootstrap-owner`), so two runs, or a run and an acceptance, are serialised. A suspended user or a revoked grant does not count as an owner (this is the recovery path if the only owner is lost).
3. **Reuses the ADR-016 flow.** A row in `staff_invitations` with role `platform_owner`, global scope, 72 hours, single use, token = 256 random bits stored only as SHA-256. Migration `2026_11_14_000003` (expand-only) makes `invited_by` nullable and adds `bootstrap boolean`, with a CHECK that `bootstrap` is true exactly when there is no inviter. The existing public `inspect` and `accept` endpoints are used unchanged; for a bootstrap row `StaffInvitations::accept` calls `BootstrapOwner::grantOnAcceptance` instead of `RoleGrantService`, which still refuses `platform_owner` everywhere else. The invitee sets their own password (or proves the current one if the address already has an account) and enrols TOTP through the normal admin gate (ADR-011).
4. **The link.** `{ADMIN_APP_URL}/invite#token=...`: the token is in the URL fragment, never a query string. The origin comes from `config('surfaces.origins.admin')`. No mail is sent (so `MAIL_MAILER=log` never holds it). It is shown once on the console and not stored.
5. **At most one pending link.** A second run while a bootstrap link is live refuses. `--replace` revokes it and prints a new one (lost link). An expired link is simply superseded.
6. **Audit.** `staff_bootstrap_owner.issued` and `role.granted` rows with a null actor user and `actor = system/console`; neither the token nor the address is recorded. The audit logger already redacts token-like keys.
7. **ProductionGuard.** The command is not in `ProductionGuard::REPAIR_COMMANDS`. It prints a credential-bearing link, so it should refuse to run in an environment whose configuration the guard rejects; on a correctly configured staging or production the guard passes and the command runs normally.

## Consequences
- The listing of pending invitations shows the bootstrap row (no inviter, `can_revoke` false for everyone, since no one may revoke a `platform_owner` invitation through the application). It expires after 72 hours or is replaced from the console.
- Anyone with shell access to the server can create the first owner. That is the same trust as holding the database credentials, and it works only while no owner exists.
- Known limit: a deadlock between `--replace` and a simultaneous acceptance is possible in principle (row lock vs advisory lock order). Postgres aborts one of them safely; nothing is half-written.
- The link is printed to the terminal. Docker or shell session recording captures it; do not run it through a logging pipeline.
- Tests: `BootstrapOwnerCommandTest`.
