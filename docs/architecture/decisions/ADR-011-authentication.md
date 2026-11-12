# ADR-011: Authentication and sessions

- Status: proposed; three items **open** and must be closed before Sprint 4 starts
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

## Open
1. **Session separation between surfaces.** With one API domain, a browser logged into the admin app and the customer app could share one session cookie. Options: separate cookie names and guards per origin, or separate API hostnames per surface. To be decided and threat-modelled before Sprint 4.
2. **MFA factor.** TOTP is the likely choice; secrets need encryption at rest and a reviewed recovery flow. Until MFA is implemented and reviewed, privileged login is refused when `APP_ENV=production`. Tests use a deterministic bypass that cannot be enabled in production.
3. **Email verification** before first login versus before sensitive actions.

## Consequences
Deployment domain layout is now a security requirement, recorded in the threat model.
