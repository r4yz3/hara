# Operations runbook

Status on 2026-11-13: rehearsed on the production-like stack in `infra/rehearsal/` only. There is no staging or production environment, no monitoring and no alerting yet (E-01 lists the alerts that are still missing). Commands below are the rehearsal's; on a real host, run the same Docker commands against that host.

## Health

| Check | Healthy | Meaning when it fails |
|---|---|---|
| `GET /health/live` | 200 `{"status":"ok"}` | PHP or nginx is down; touches no dependency |
| `GET /health/ready` | 200, `database` and `redis` true | a dependency is down. Answers only to loopback and private addresses; anyone else gets 404 |
| Logs | no `http.request` events at level error | each 5xx is logged with its `request_id` |

Every response carries `X-Request-Id`, and every log line carries the same id. Ask a person reporting an error for it.

## Rolling back

Choose the smallest step that fixes the problem.

### A. Application only: the new code is wrong, the new schema is fine

Run the previous release's images again. Leave the schema as it is: the previous code must already work on it, because migrations are expand-only (`deploy.md`).

```powershell
.\infra\scripts\rehearsal.ps1 up -Release <previous release>
```

Rehearsed: the previous release served normally on the newer schema, before and after the switch.

### B. Application and schema: the new migration itself is the problem

1. Roll back the migration **using the new release**. Only the release that added a migration contains its `down()`.
   ```powershell
   .\infra\scripts\rehearsal.ps1 rollback-migration -Release <new release> -Steps <number of migrations it added>
   ```
2. Run the previous release: `up -Release <previous release>`.
3. Check health and a public read.

**Data written into anything the migration added is lost.** In the rehearsal, a value the new release stored in its new column disappeared with the column. If that data matters, export it first, or prefer a forward fix: a new release that corrects the problem and keeps the column.

`ProductionGuard` does not block `migrate:rollback` when the configuration is safe. It refuses commands only in an environment with unsafe settings, and in that case it lets only repair commands run (`migrate`, `config:clear`, ...).

### C. Restore the backup taken before the deploy

Use this when the schema or data cannot be trusted, for example after a migration failed half-way or a release corrupted rows. **Everything written since the backup is lost.**

1. Stop or pause traffic. The restore ends the API's open connections.
2. Switch to the release that matches the backup: `up -Release <previous release>`.
3. `.\infra\scripts\rehearsal.ps1 restore`. It checks the backup's SHA-256, then **drops and recreates the database** and restores into it.
4. Check health, a public read, and that `php artisan migrate:status` matches the release.

Rehearsed, and it found a bug: the first version of the restore used `pg_restore --clean`, which drops only objects that are in the backup. A table created by the newer release survived while the migration ledger said it had never been created, so the next deploy of that release would have failed. The restore now replaces the whole database. After the fix, the newer release could be deployed again cleanly, and the audit log was still append-only.

A restore with a wrong checksum is refused.

## Routine tasks

| Task | Command (rehearsal) | Notes |
|---|---|---|
| Back up | `rehearsal.ps1 backup` | before every deploy; see `backups.md` for the development drill |
| Migration status | `rehearsal.ps1 status -Release <tag>` | |
| Logs | `docker compose -p food-delivery-rehearsal logs -f api web` | JSON from the API, one line per request from nginx (path only, never a query string) |
| Rotate `APP_KEY` | not supported yet | sessions and encrypted values would become unreadable; needs a procedure first |

## Known gaps

- No monitoring or alerting (5xx rate, database down, backup failure, disk).
- No scheduled or off-site backups (`backups.md`).
- No queue worker or scheduler: Phase 1 has no queued jobs.
- No deployment procedure for the web apps or the rider app.
