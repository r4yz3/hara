# ADR-021: One synthetic test rider by console command

- Status: proposed (operator decision 2026-11-15 to have one test rider on staging; this ADR awaits review)
- Date: 2026-11-15
- Relates to: ADR-005, ADR-011 (rider token), ADR-016 and ADR-018 (the pattern), backlog B-08 and U-08

## Context
- The rider app can reach staging but cannot complete a sign-in: `POST /auth/mobile/token` needs an active user with an `approved` row in `riders`, and staging has none. There is no rider onboarding or approval flow in Phase 1.
- `DEMO_PASSWORD` is forbidden outside local (ProductionGuard), so no seeded account is possible. No real rider data may be held (no documents, location, payouts).

## Decision
1. **Command.** `php artisan rider:invite-test {email} [--name=Test rider]`. Console only; no route. It creates a user with a random, discarded password, one `riders` row with status `approved`, and nothing else (no role grant, no customer profile, no vehicle, no city access).
2. **Set-password by the existing reset flow.** It stores a hashed token in `password_reset_tokens` (the B-03 mechanism, 60 minutes, once) and mails `{CUSTOMER_APP_URL}/reset-password#token=...` via `TestRiderInvitation`. The token is in the URL fragment. The customer-web page and `POST /auth/password/reset` work for any active user, so riders need no web flow of their own. The reset also revokes rider devices and tokens, which is right for a re-issued link. The link is never printed or stored; the console shows only that a mail was sent.
3. **Synthetic marker.** The schema has no test flag, so the account is identified by audit: `rider_test.created` (subject = the rider id, `after.synthetic = true`, `actor = system/console`, null actor user). Neither address nor token is recorded.
4. **Safety.** It refuses an address that holds any active role grant, has a customer profile or merchant staff row, or is a rider this command did not create, or is suspended. Re-running for its own rider reuses it and issues a fresh link (`rider_test.link_issued`). Rate limit: 3 per 15 minutes per address. A refusal never prints the address.
5. **ProductionGuard.** Not in `REPAIR_COMMANDS`: it sends a credential-bearing link, so it refuses to run in an environment the guard rejects.

## Consequences
- The account is a real approved rider as far as the API can tell. Remove it by suspending the rider or user; there is no delete command.
- Mail must be a real transport on staging (`MAIL_REQUIRE_REAL_TRANSPORT`), else the link lands in the log.
- Tests: `InviteTestRiderCommandTest`.
