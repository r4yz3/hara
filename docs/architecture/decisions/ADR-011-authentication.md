# ADR-011: Authentication and sessions

- Status: accepted by the product owner on 2026-11-13, using the proposals below. Email-verification timing remains open
- Date: 2026-11-12

## Context
The spec requires "an explicitly selected secure auth design" (1.3), cookie sessions for browsers, scoped short-lived tokens for the rider app, and no admin bearer token in a browser.

## Decision
- **Browsers:** Laravel Sanctum in SPA (stateful cookie) mode. HTTP-only session cookie, CSRF token from `/sanctum/csrf-cookie`, `Secure` and `SameSite=Lax` or stricter under TLS. CORS allows only the three configured app origins with credentials; no wildcard.
- This mode needs the API and the apps on the same registrable domain. Locally that is `localhost` on different ports. Deployed environments must use subdomains of one domain. If that cannot be guaranteed, the fallback is a backend-for-frontend session broker in each Next.js app, with its own threat analysis, before any deployment.
- **Rider app:** Sanctum personal access tokens with a restricted ability list, a short expiry, storage in the platform keystore, rotation on refresh and revocation per device. `POST /api/v1/auth/mobile/token` issues tokens only to approved riders on a registered device.
- Passwords use the framework hasher configured for Argon2id. Login and recovery are throttled. Login errors and recovery responses are identical whether or not the account exists.
- Registration is open only when `FEATURE_PUBLIC_REGISTRATION` is on, always creates a `customer`, and ignores any role or scope in the request.
- The first owner account is created by a console command that runs only in `local` and `testing`. Production owner provisioning is a separate audited procedure, not yet defined.

## Decided on 2026-11-13
1. **Session separation:** each web app gets its own session cookie name and its own guard, so a customer session is never valid on the admin or merchant surface.
2. **MFA factor:** TOTP from an authenticator app, secrets encrypted at rest. Built 2026-11-14 (see "MFA as built"); the recovery-code design awaits product owner review.

## As built (2026-11-13)
One session guard is used, not three. Separation comes from a per-app cookie name chosen from the request's Origin, plus the app's name stored in the session and compared on every authenticated request; a mismatch ends the session. The CSRF token is served by `GET /api/v1/auth/csrf` and sent in `X-CSRF-TOKEN`; Sanctum's shared-cookie route is disabled.

Superseded by "MFA as built" below: on 2026-11-14 the temporary MFA gate was aligned with the production configuration guard. Admin sign-in is available only under `local` and `testing`. Other environments refuse password-only admin sign-in and invalidate existing admin sessions when they reach a protected route. Hosted admin testing stays blocked pending reviewed enrollment, challenge and recovery flows.

Browser sign-in stores a Laravel-generated HMAC of the current password hash in the session. Protected requests check it against the current account hash, so password resets invalidate existing browser sessions on their next protected request without scanning Redis. Sessions issued before this change require sign-in again.

## MFA as built (2026-11-14, backlog B-09): built, awaiting product owner review
Replaces the temporary gate that refused every deployed admin sign-in.

- **Factor.** TOTP per RFC 6238 (HMAC-SHA1, 30-second step, 6 digits, 20-byte base32 secret), implemented in `AppDomainIdentityTotp` and tested against the RFC vectors. The previous, current and next step are accepted; the newest accepted step is stored (`last_used_step`) so a code works once. The secret is encrypted at rest (Laravel `encrypted` cast, table `user_mfa_factors`) and is returned only by the enrolment start response.
- **Enrolment.** `POST /api/v1/me/mfa/totp` (secret and otpauth URI; restarting replaces an unconfirmed secret; 409 once confirmed) and `POST /api/v1/me/mfa/totp/confirm` (a valid code; returns ten recovery codes once). There is no route to disable or reset a factor: that needs a reviewed, audited operator procedure.
- **Sign-in.** On the admin surface, a correct password for staff with a confirmed factor answers `{"data":{"mfa_required":true}}` and stores only a five-minute pending marker in the session; nobody is signed in. `POST /api/v1/auth/mfa/verify` takes `code` or `recovery_code`, regenerates the session id and answers like login. A wrong code, an expired marker and a missing marker give the same 401 as a wrong password; five failures per account and address per minute give 429. Every admin session for such staff must carry the verified flag, in every environment.
- **Deployed environments** (anything but `local` and `testing`). Staff without a confirmed factor get an enrolment-only session: only the two enrolment routes and logout work; every other route answers 403 telling them to set up two-step sign-in. Confirming ends that session, and they sign in again with the second step. Under `local` and `testing` such staff keep password-only sessions.
- **Recovery codes.** Ten per enrolment, 64 random bits each, single use, stored only as HMAC-SHA-256 hashes keyed with the application key, so rotating `APP_KEY` invalidates unused recovery codes (as it already invalidates the secrets). Built, awaiting product owner review: the number of codes, the lack of regeneration, and the lack of an operator reset path for a lost device and lost codes.
- **Audit.** `auth.mfa_enrolled`, `auth.mfa_verified` (method `totp` or `recovery_code`) and `auth.mfa_failed` (stage `sign_in` or `enrolment`); ids and method only.
- `/api/v1/me` reports `mfa_enabled`.

## Original options and what is still open
1. **Session separation between surfaces.** With one API domain, a browser logged into the admin app and the customer app could share one session cookie. Options: separate cookie names and guards per origin, or separate API hostnames per surface. To be decided and threat-modelled before Sprint 4.
2. **MFA factor.** Decided: TOTP. Built and awaiting product owner review of the recovery-code design (see "MFA as built"). Tests no longer need a bypass: they use real codes.
3. **Email verification** before first login versus before sensitive actions.

## Consequences
Deployment domain layout is now a security requirement, recorded in the threat model.
