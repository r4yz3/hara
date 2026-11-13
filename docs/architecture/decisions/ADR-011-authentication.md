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
2. **MFA factor:** TOTP from an authenticator app, secrets encrypted at rest. Until it is built and reviewed, privileged login is refused when `APP_ENV=production`.

## As built (2026-11-13)
One session guard is used, not three. Separation comes from a per-app cookie name chosen from the request's Origin, plus the app's name stored in the session and compared on every authenticated request; a mismatch ends the session. The CSRF token is served by `GET /api/v1/auth/csrf` and sent in `X-CSRF-TOKEN`; Sanctum's shared-cookie route is disabled.

On 2026-11-14 the temporary MFA gate was aligned with the production configuration guard. Admin sign-in is available only under `local` and `testing`. Other environments refuse password-only admin sign-in and invalidate existing admin sessions when they reach a protected route. Hosted admin testing stays blocked pending reviewed enrollment, challenge and recovery flows.

Browser sign-in stores a Laravel-generated HMAC of the current password hash in the session. Protected requests check it against the current account hash, so password resets invalidate existing browser sessions on their next protected request without scanning Redis. Sessions issued before this change require sign-in again.

## Original options and what is still open
1. **Session separation between surfaces.** With one API domain, a browser logged into the admin app and the customer app could share one session cookie. Options: separate cookie names and guards per origin, or separate API hostnames per surface. To be decided and threat-modelled before Sprint 4.
2. **MFA factor.** TOTP is the likely choice; secrets need encryption at rest and a reviewed recovery flow. Until MFA is implemented and reviewed, privileged login is refused when `APP_ENV=production`. Tests use a deterministic bypass that cannot be enabled in production.
3. **Email verification** before first login versus before sensitive actions.

## Consequences
Deployment domain layout is now a security requirement, recorded in the threat model.
