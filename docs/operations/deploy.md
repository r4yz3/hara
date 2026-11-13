# Deploying the API

Status on 2026-11-13: there is **no staging or production environment** (E-07 is waiting on a hosting decision). Everything below has been run only on the authoring workstation, against the production-like rehearsal stack in `infra/rehearsal/`. It is a procedure that has been rehearsed, not one that has run on a real host.

The web apps (customer, merchant, admin) and the rider app have no deployment procedure yet.

## What a release is

A release is a pair of images built from one commit (ADR-014):

| Image | Built with | Runs |
|---|---|---|
| `food-delivery-api:<release>` | `--target api` | PHP-FPM as `www-data`, port 9000 |
| `food-delivery-web:<release>` | `--target web` | nginx as `nginx`, port 8080 |

Use the commit id as `<release>`. Build each release **once** and run that same image everywhere: base images are referenced by tag, so a rebuild can differ.

```powershell
docker build -f infra/docker/prod/api.Dockerfile --target api -t food-delivery-api:<release> .
docker build -f infra/docker/prod/api.Dockerfile --target web -t food-delivery-web:<release> .
```

Both images are scanned in CI by the `image` job in `.github/workflows/security.yml`. Do not deploy a release whose scan failed.

## What the environment must provide

- PostgreSQL with PostGIS, reachable only from the API.
- Redis, reachable only from the API.
- A TLS-terminating proxy in front of `web`, forwarding `X-Forwarded-Proto`.
- The settings below, injected as environment variables from the host's secret store. Never bake them into an image or commit them. `ProductionGuard` refuses to serve if any rule fails. The message names the setting, never its value.

| Setting | Rule |
|---|---|
| `APP_ENV` | `production` or `staging` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | set; generate once per environment and keep it |
| `APP_URL` | an https URL |
| `CUSTOMER_APP_URL`, `MERCHANT_APP_URL`, `ADMIN_APP_URL` | three distinct public https origins |
| `SESSION_SECURE_COOKIE` | `true` |
| `HASH_DRIVER` | `argon2id` |
| `DB_PASSWORD` | a real secret |
| `DEMO_PASSWORD` | unset |
| `TRUSTED_PROXIES` | the TLS proxy's IP addresses or CIDR ranges, comma-separated; never `*` or a `/0` range. Without it Laravel sees plain HTTP and never sends HSTS |
| `LOG_CHANNEL=stack`, `LOG_STACK=json` | logs as JSON to the container's stderr (E-01) |

Admin sign-in stays refused in production until two-step verification exists (B-09). That is intended.

## Deploying a release

1. **Back up** the database and confirm the backup's checksum was recorded (`docs/operations/backups.md`).
2. **Migrate with the new release** while the old one still serves:
   `docker compose run --rm --no-deps api php artisan migrate --force`
   with `RELEASE=<new release>`. Migrations must be **expand-only**: add, never rename or drop, in the same release. The old code must keep working on the new schema. The rehearsal checked this: the old release served normally after the new migration.
3. **Switch traffic**: start `api` and `web` on the new release. `up -d --wait` returns only once `web` answers `/health/live`.
4. **Check**: `/health/live` 200, `/health/ready` 200, a public read such as `/api/v1/public/config`, and the logs for 5xx `http.request` events.
5. **Contract later**: remove old columns or tables in a later release, after the code that used them is gone everywhere.

If any step fails, follow the rollback in `docs/operations/runbook.md`.

## Rehearsing locally

`infra/scripts/rehearsal.ps1` runs the same images under `APP_ENV=staging`, with its own database, Redis and volumes, and nginx on `127.0.0.1:8088` only. It never touches the development stack.

```powershell
.\infra\scripts\rehearsal.ps1 env                     # once: writes infra\rehearsal\.env with random secrets (git-ignored)
.\infra\scripts\rehearsal.ps1 build -Release <tag>
.\infra\scripts\rehearsal.ps1 up -Release <tag>
.\infra\scripts\rehearsal.ps1 migrate -Release <tag>
.\infra\scripts\rehearsal.ps1 backup                  # infra\backups\rehearsal\, with checksum
.\infra\scripts\rehearsal.ps1 rollback-migration -Release <tag> -Steps 1
.\infra\scripts\rehearsal.ps1 restore                 # replaces the rehearsal database with the newest rehearsal backup
.\infra\scripts\rehearsal.ps1 down                    # keeps the rehearsal database
.\infra\scripts\rehearsal.ps1 destroy                 # deletes the rehearsal database
```

There is no TLS in the rehearsal. The https settings are satisfied by configuration only. Requests from the workstation reach nginx from the Docker gateway, so setting `TRUSTED_PROXIES` to that address in `infra\rehearsal\.env` makes the gateway play the TLS proxy (`docs/qa/evidence/sprint7-trusted-proxy-check.txt`).

The last rehearsal is recorded in `docs/qa/evidence/e05-rollback-rehearsal.txt`.
