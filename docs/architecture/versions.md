# Version manifest

Observed on 2026-11-12 on the development workstation (Windows 11 Pro 10.0.26200). Nothing is pinned yet: there are no lockfiles until Sprint 2. From Sprint 2 the lockfiles (`composer.lock`, `pnpm-lock.yaml`, `pubspec.lock`) and image tags in `infra/compose.yaml` are authoritative and this file is regenerated from them.

## Installed tools (measured)

| Tool | Version | Notes |
|---|---|---|
| Git | 2.54.0.windows.1 | |
| Node.js | 24.16.0 | Satisfies Next.js `engines.node >=20.9.0` |
| pnpm | 9.15.9 | Registry latest is 12.18.0; pin through `packageManager` in Sprint 2 |
| Corepack | 0.35.0 | |
| PHP (Herd Lite, native) | 8.5.0 NTS | Extensions found: bcmath, mbstring, redis, zip. **Not found: pdo_pgsql, pgsql, intl, sodium.** Native PHP cannot run the backend against PostgreSQL; use the `api` container |
| Composer | present (`composer.bat`, Herd Lite) | Version not captured; callable from PowerShell only |
| Flutter | 3.41.7 stable (2026-04-15) | A newer stable exists; decide upgrade in Sprint 2 |
| Dart | 3.11.5 | |
| GitHub CLI | 2.92.0 | |
| WSL | version 2 available | Default distribution is unrelated to this project |

## Missing tools

| Tool | Needed for | Blocks |
|---|---|---|
| Docker Desktop | PostgreSQL/PostGIS, Redis, MinIO, Mailpit, PHP container | Sprint 2 onward |
| Android SDK / `adb` | Building and running the rider app | Rider build in Sprint 2 (`flutter analyze` and `flutter test` may still run) |
| Java (JDK) | Android Gradle build | Same as above |
| `make` | Makefile targets | Nothing: PowerShell equivalents are required by the spec |

## Registry versions on 2026-11-12 (candidates, not pinned)

| Package | Latest | Constraint or risk to check in Sprint 2 |
|---|---|---|
| laravel/framework | 13.42.0 | Requires PHP `^8.3` |
| laravel/sanctum | 4.4.0 | |
| larastan/larastan | 3.15.0 | |
| laravel/pint | 1.32.3 | |
| next | 16.4.6 | |
| react | 19.3.1 | |
| typescript | 7.0.2 | Major version; confirm Next.js, ESLint and Storybook support before adopting, otherwise stay on the last 5.x/6.x they support |
| tailwindcss | 4.3.5 | |
| maplibre-gl | 6.18.0 | v6 is ESM-only; see ADR-007 |
| @playwright/test | 1.65.0 | |
| zod | 4.6.7 | |
| eslint | 10.14.0 | |
| storybook | 10.6.7 | |
| openapi-typescript | 7.13.0 | |

## Not yet selected

Object storage image (ADR-012). The other images were given tag pins in Sprint 2 (below) without being pulled; PostgreSQL 17 with PostGIS 3.5 is a provisional choice until the stack has actually run.

## Pinned in Sprint 2 (2026-11-13, from lockfiles and manifests)

| Component | Version | Source |
|---|---|---|
| laravel/framework | 13.42.0 | `backend/composer.lock` |
| larastan/larastan | ^3.15 | `backend/composer.json` |
| next | 16.4.6 | `apps/*/package.json` |
| react, react-dom | 19.3.0 | `apps/*/package.json` |
| tailwindcss | 4.3.3 | `apps/*/package.json` |
| typescript | ^5 (not 7) | Chosen by `create-next-app` 16.4.6; staying on 5 until Next.js documents support for 7 |
| eslint | ^9 | Same |
| pnpm | 9.15.9 | `packageManager` in root `package.json` |
| Flutter / Dart | 3.41.7 / ^3.11.5 | `.github/workflows/flutter.yml`, `pubspec.yaml` |

Container images named in `infra/compose.yaml` and CI (tags confirmed to exist on Docker Hub, **never pulled or run**): `postgis/postgis:17-3.5-alpine`, `redis:8.8.4-alpine`, `axllent/mailpit:v1.33.1`, `php:8.4-fpm-alpine3.23`, `composer:2.11.2`. These are tag pins, not digest pins; digest pinning is done when Docker is available.

GitHub Actions are pinned by commit SHA with the version in a comment: checkout v7.0.1, setup-node v7.1.0, pnpm/action-setup v6.1.1, shivammathur/setup-php 2.40.0, subosito/flutter-action v2.23.0, gitleaks-action v3.0.0.

Caret ranges (`^5`, `^9`, `@types/*`) remain in the app manifests as generated; the exact resolved versions are in `pnpm-lock.yaml`.
