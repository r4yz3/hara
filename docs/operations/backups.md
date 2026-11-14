# Backups and restore

Status on 2026-11-14: staging has nightly encrypted, signed off-site backups to R2 with a passing restore drill (docs/operations/staging-setup.md section 11). For the development database, as of 2026-11-13: a manual backup and an automated restore drill exist and have been run against the **local development database only**. There is no scheduled backup, no off-machine copy, no encryption of backup files, no point-in-time recovery and no production environment. The RPO and RTO in the master plan (15 minutes, 4 hours) are targets that nothing here meets yet.

## What exists

| Task | Windows | Linux, macOS, WSL | What it does |
|---|---|---|---|
| Back up | `.\infra\scripts\dev.ps1 backup` | `make backup` | `pg_dump` in custom format of the application database to `infra/backups/food_delivery-<UTC time>.dump` |
| Restore drill | `.\infra\scripts\dev.ps1 restore-drill` | `make restore-drill` | Restores the newest backup into a new scratch database, verifies it, removes the scratch database |

The Make targets have not been run (`make` is not installed on the authoring workstation).

`infra/backups/` is ignored by git. A backup contains personal data (accounts, addresses, the audit log). Handle it like the database: do not commit it, share it, or keep copies you do not need.

The backup task writes the file's SHA-256 beside it. The drill refuses to restore a backup with no recorded checksum or one that does not match, because `pg_restore` executes whatever the archive contains. The dump is created with owner-only permissions, and the copies inside the database container are deleted after the backup and after the drill.

Still true: the drill connects as the database's own superuser and creates its scratch database in the same server as the source. A drill in a real environment should restore into separate infrastructure with a restricted role.

## What the drill proves

The drill never writes to the source database. It passes only if all of these hold:

1. Every table has the same number of rows as the source.
2. The migration ledger is identical.
3. Every delivery zone and exclusion shape is byte-identical, valid, and in SRID 4326.
4. The four spatial indexes exist.
5. The audit log is still append-only: its triggers survived, and an UPDATE and a DELETE on a probe row are refused.
6. A serviceability lookup at a point inside a stored active zone returns the same zone from both databases.

Last run: `docs/qa/evidence/restore-drill.txt` (passed; 31 tables; restore took about one second on a database of a few dozen rows, which says nothing about a production-sized one).

## What the drill does not prove

- That it would **catch** a bad restore. It has only ever been run against a good one; no fault was injected.
- Anything about Redis (sessions, queues, rate limits) or object storage. Neither is backed up. Sessions are disposable; queued jobs and uploaded files will matter once they exist.
- That a backup can be restored on a **different machine** or PostGIS version.
- Restore time or backup size at real scale.
- Recovery of the `.env` file and `APP_KEY`. Without the key, encrypted columns and existing sessions cannot be read, so the key needs its own protected backup.

## To restore for real (local)

There is deliberately no one-command "restore over the live database". To replace the development database, an operator creates a new database, runs `pg_restore --no-owner --no-privileges --exit-on-error` into it, points `DB_DATABASE` at it and restarts the stack. Dropping the old database is a separate, deliberate step.

## Before any real environment

- Scheduled backups with monitoring and an alert on failure (spec 11.3).
- Encrypted copies stored away from the database host, with a defined retention period (awaits the privacy review).
- Point-in-time recovery if the hosting supports it.
- A drill that restores onto separate infrastructure, with a fault-injection case.
- A written decision on who may read backups.
