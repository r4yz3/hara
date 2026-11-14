# ADR-011: Authentication and sessions

- Status: accepted by the product owner on 2026-11-13, using the proposals below. Recovery-code review closed by the human operator on 2026-11-15. Email-verification timing remains open
- Date: 2026-11-12

## Context
The spec requires "an explicitly selected secure auth design" (1.3), cookie sessions for browsers, scoped short-lived tokens for the rider app, and no admin bearer token in a browser.

## Decision
- **Browsers:** Laravel Sanctum in SPA (stateful cookie) mode. HTTP-only session cookie, CSRF token from `/sanctum/csrf-cookie`, `Secure` and `SameSite=Lax` or stricter under TLS. CORS allows only the three configured app origins with credentials; no wildcard.
- This mode needs the API and the apps on the same registrable domain. Locally that is `localhost` on different ports. Deployed environments must use subdomains of one domain. If that cannot be guaranteed, the fallback is a backend-for-frontend session broker in each Next.js app, with its own threat analysis, before any deployment.
- **Rider app:** Sanctum personal access tokens with a restricted ability list, a short expiry, storage in the platform keystore, rotation on refresh and revocation per device. `POST /api/v1/auth/mobile/token` issues tokens only to approved riders on a registered device.
- Passwords use the framework hasher configured for Argon2id. Login and recovery are throttled. Login errors and recovery responses are identical whether or not the account exists.
  Per-account limiting (email plus address hash) lives in the controllers; the route-level limiters are per address only and one per route, see ADR-010.
- Registration is open only when `FEATURE_PUBLIC_REGISTRATION` is on, always creates a `customer`, and ignores any role or scope in the request.
- The first owner account is created by a console command that runs only in `local` and `testing`. Production owner provisioning is a separate audited procedure, not yet defined.

## Decided on 2026-11-13
1. **Session separation:** each web app gets its own session cookie name and its own guard, so a customer session is never valid on the admin or merchant surface.
2. **MFA factor:** TOTP from an authenticator app, secrets encrypted at rest. Built 2026-11-14 (see "MFA as built"); the recovery-code design was reviewed and accepted on 2026-11-15 (see "Human approval, 2026-11-15").

## As built (2026-11-13)
One session guard is used, not three. Separation comes from a per-app cookie name chosen from the request's Origin, plus the app's name stored in the session and compared on every authenticated request; a mismatch ends the session. The CSRF token is served by `GET /api/v1/auth/csrf` and sent in `X-CSRF-TOKEN`; Sanctum's shared-cookie route is disabled.

Superseded by "MFA as built" below: on 2026-11-14 the temporary MFA gate was aligned with the production configuration guard. Admin sign-in is available only under `local` and `testing`. Other environments refuse password-only admin sign-in and invalidate existing admin sessions when they reach a protected route. Hosted admin testing stays blocked pending reviewed enrollment, challenge and recovery flows.

Browser sign-in stores a Laravel-generated HMAC of the current password hash in the session. Protected requests check it against the current account hash, so password resets invalidate existing browser sessions on their next protected request without scanning Redis. Sessions issued before this change require sign-in again.

## MFA as built (2026-11-14, backlog B-09): built, awaiting product owner review
Replaces the temporary gate that refused every deployed admin sign-in.

- **Factor.** TOTP per RFC 6238 (HMAC-SHA1, 30-second step, 6 digits, 20-byte base32 secret), implemented in `AppDomainIdentityTotp` and tested against the RFC vectors. The previous, current and next step are accepted; the newest accepted step is stored (`last_used_step`) so a code works once. The secret is encrypted at rest (Laravel `encrypted` cast, table `user_mfa_factors`) and is returned only by the enrolment start response.
- **Enrolment.** `POST /api/v1/me/mfa/totp` (secret and otpauth URI; restarting replaces an unconfirmed secret; 409 once confirmed) and `POST /api/v1/me/mfa/totp/confirm` (a valid code; returns ten recovery codes once). A person cannot disable their own factor. Only the operator reset below removes one.
- **Sign-in.** On the admin surface, a correct password for staff with a confirmed factor answers `{"data":{"mfa_required":true}}` and stores only a five-minute pending marker in the session; nobody is signed in. `POST /api/v1/auth/mfa/verify` takes `code` or `recovery_code`, regenerates the session id and answers like login. A wrong code, an expired marker and a missing marker give the same 401 as a wrong password; five failures per account and address per minute give 429. Every admin session for such staff must carry the verified flag, in every environment.
- **Deployed environments** (anything but `local` and `testing`). Staff without a confirmed factor get an enrolment-only session: only the two enrolment routes and logout work; every other route answers 403 telling them to set up two-step sign-in. Confirming ends that session, and they sign in again with the second step. Under `local` and `testing` such staff keep password-only sessions.
- **Recovery codes.** Ten per enrolment, 64 random bits each, single use, stored only as HMAC-SHA-256 hashes keyed with the application key, so rotating `APP_KEY` invalidates unused recovery codes (as it already invalidates the secrets). Reviewed and accepted 2026-11-15 (ten codes).
- **Audit.** `auth.mfa_enrolled`, `auth.mfa_verified` (method `totp` or `recovery_code`) and `auth.mfa_failed` (stage `sign_in`, `enrolment` or `recovery_codes`), `auth.mfa_recovery_codes_regenerated` and `auth.mfa_reset`; ids, method, count and the operator's reason only.
- **Regeneration.** `POST /api/v1/me/mfa/recovery-codes` with a current code (a step not used before, so the code that just signed in cannot be replayed) returns ten new codes once and deletes all old ones. Same limit (5 failures per minute per account and address), 422 for a wrong code, 409 when no factor exists, admin surface only. Audit `auth.mfa_recovery_codes_regenerated`.
- **Operator reset.** `POST /api/v1/admin/users/{user}/mfa/reset` with a `reason` (1 to 255 characters) for staff who lost both device and codes. Permission `users.mfa.reset`, held by `platform_owner` only and checked with global scope, so a city grant never reaches it. Not allowed on oneself (403, use a recovery code); a platform owner can be reset only by a platform owner (explicit check, in case the permission is ever widened); an unknown id or a user without a live staff grant is 404. One transaction deletes the factor and all recovery codes, deletes the person's rows in the database session store (all surfaces, when `SESSION_DRIVER=database`) and writes `auth.mfa_reset` (reason and target id, never a code). The person signs in with the password, and in deployed environments must enrol again before anything else works; under `local` and `testing` they are password-only again. Two or more people holding the permission is a product decision; today it is one role.
- **Sessions after a reset.** A session records the id of the factor it passed (`mfa_factor`). The gate ends a session whose factor is gone or replaced, and, in every environment, a session that claims a second step when no confirmed factor exists. So a stale session cannot resume after a reset, even if the person enrols a new factor first.
- `/api/v1/me` reports `mfa_enabled`.

## Original options and what is still open
1. **Session separation between surfaces.** With one API domain, a browser logged into the admin app and the customer app could share one session cookie. Options: separate cookie names and guards per origin, or separate API hostnames per surface. To be decided and threat-modelled before Sprint 4.
2. **MFA factor.** Decided: TOTP. Built; the recovery-code design was accepted by the human operator on 2026-11-15 (see "Human approval, 2026-11-15"). Tests no longer need a bypass: they use real codes.
3. **Email verification** before first login versus before sensitive actions.

## Consequences
Deployment domain layout is now a security requirement, recorded in the threat model.


## Staging domain layout and recovery-code review (2026-11-14)

### Staging domains (E-07)
Operator decision: Contabo Cloud VPS Plus 4 in Asia (Japan), bought 2026-11-14 (about €21.20 a month, from the order screen), behind Cloudflare (free plan). The domain `haradelivery.com` was bought 2026-11-14. Hostnames are flat, one label below the domain, so Cloudflare's free `*.haradelivery.com` certificate covers them: `staging.haradelivery.com` (customer web), `staging-merchant.haradelivery.com` (merchant web), `staging-admin.haradelivery.com` (admin web), `staging-api.haradelivery.com` (API). Server bought but not set up yet (no OS or IP recorded).

Checked against this ADR (cookie mode needs subdomains of one registrable domain; `CUSTOMER_APP_URL`, `MERCHANT_APP_URL` and `ADMIN_APP_URL` must be three distinct https origins; the session cookie name is chosen from the request Origin):
- **Same site: satisfied.** All names sit under `haradelivery.com`, so `SameSite=Lax` works. Leave `SESSION_DOMAIN` empty (host-only cookie on the API host).
- **Conflict 1: one web hostname is not enough.** The per-app cookie separation and the CORS allow-list need three origins. Resolved by the names above: customer, merchant and admin web apps each get their own host, and the API has its own host. Admin sign-in needs its own origin before staff testing; merchant can follow.
- **Conflict 2: Cloudflare's free universal certificate** covers `haradelivery.com` and `*.haradelivery.com` only, not names two levels down such as `api.staging.haradelivery.com`. Resolved by flattening the names as above, so staging needs neither Advanced Certificate Manager nor an origin certificate. Do not use two-level names for staging.
- **Cloudflare proxy:** the API must trust Cloudflare as the TLS proxy (`TRUSTED_PROXIES`, `X-Forwarded-Proto`), take client IPs from `CF-Connecting-IP`, and the origin should accept only Cloudflare ranges. Per-address throttles are wrong until that is set.

### Recovery-code review result (B-09)
Checked against the code (`StaffMfa`, `MfaController`):
- Count: ten per enrolment. Pass (8 to 10).
- Entropy: 64 random bits per code. Pass (at least 50).
- Stored only hashed: HMAC-SHA-256 keyed with the application key, not Argon2 or bcrypt. Acceptable for 64-bit random values (a slow hash adds little) but it differs from the "argon2/bcrypt" wording; accepted by the human operator on 2026-11-15 (see below).
- Shown once: returned only by the confirm and regenerate responses. Pass.
- Regeneration revokes all old codes: deletes them in the same transaction as issuing new ones. Pass.
- Rate limiting: five failures per minute per account and address, then 429. Pass.
- **Gap: no alert to the user when a recovery code is used.** Only the audit row `auth.mfa_verified` (method `recovery_code`) is written; no email or in-app notice is sent. Staging also needs a mail provider for it.
- Not implemented here, by instruction. Verdict at that time: **not passed until the alert exists**; every other point passed. Superseded: the alert was built and the review closed on 2026-11-15 (below).

### Recovery-code alert built (2026-11-14, follow-up to the review above)
- **Alert.** After a recovery code signs a person in, `StaffMfa::alertRecoveryCodeUsed` sends the plain-text mail `App\Mail\RecoveryCodeUsed` to the account address. It states the time (UTC), how many unused codes are left, and what to do if it was not them (change the password, ask a platform owner for a reset). When two or fewer codes are left it also tells the person to generate a new set. It holds no code, no token, no link and no IP address. TOTP sign-ins and failed attempts send nothing.
- **Delivery.** Sent synchronously like the other mail, because no queue workers exist yet (the mail moves to the queue with them). `MAIL_MAILER=log` on staging only writes the mail to the log; a real mail provider is still needed before the alert reaches a person. A mail failure is logged (user id and exception class only) and does not block the sign-in.
- **Record.** The existing audit row `auth.mfa_verified` (method `recovery_code`) remains the in-app record. There is no in-app notice mechanism yet, so none was added.
- **Tests.** `StaffMfaRecoveryTest`: mail on recovery-code use with the remaining count and no secret; low-count warning; none on TOTP or a failed attempt; a mail failure does not block sign-in.
- Hashing is unchanged (HMAC-SHA-256 keyed with the application key). The alert gap above is closed in code. The reviewer's verdict is recorded below.

## Human approval, 2026-11-15
Decision by the human operator, 2026-11-15: the keyed HMAC-SHA-256 storage of recovery codes is **accepted** in place of Argon2 or bcrypt (the codes are 64-bit random values). The ADR-011 recovery-code review (B-09) is **closed**: all other criteria passed, the use alert is built, and email now works on staging (Resend SMTP). Not covered by this decision: end-to-end checking of the invitation, reset and recovery-code mails on staging, and the email-verification timing, which both stay open.
