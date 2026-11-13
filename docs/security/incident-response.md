# Incident response

Status on 2026-11-13: a first procedure written before any environment exists. No on-call rota, contacts, status page or monitoring are in place. The roles below are placeholders until the product owner names people (AGENTS.md rule 20).

## What counts as an incident

- The API is down or failing (5xx) for customers, merchants or staff.
- Data is exposed, changed or deleted without authorisation, or that is suspected.
- A secret (database password, `APP_KEY`, a token) may have been seen by someone who should not have it.
- A dependency or image vulnerability is being exploited or has a public exploit (`docs/security/container-scan.md`).

## Roles

| Role | Responsibility | Who |
|---|---|---|
| Incident lead | decides, keeps the timeline, calls the end | to be named |
| Operator | runs commands, rollbacks and restores (`docs/operations/runbook.md`) | to be named |
| Communications | tells affected users and, for personal data, the data protection officer | to be named |

## Steps

1. **Declare.** Write the time (UTC), what was seen and the request ids involved. Start a timeline and add to it as you go.
2. **Contain.** Stop the damage before looking for the cause:
   - a bad release: roll back the application (`runbook.md`, path A);
   - a leaked secret: rotate it in the secret store, restart the API, and end sessions if `APP_KEY` or the session store was involved;
   - a compromised staff account: suspend it (`users.status`), which takes effect on its next request, and review its audit entries.
3. **Preserve evidence.** Keep logs and the audit log; it is append-only. Take a database backup before any repair that changes data. Do not copy personal data anywhere it does not need to go.
4. **Recover.** Deploy a fix or restore (`runbook.md`, paths B and C). Check health and the affected feature.
5. **Notify.** If personal data may be involved, the Philippine Data Privacy Act requires notifying the National Privacy Commission and affected people within 72 hours of knowing about a breach. The product owner and counsel decide; record when you learned of it.
6. **Review** within a week: timeline, cause, what detected it, and changes with backlog ids. Blame no one.

## Where to look

- Request-level: the `http.request` events and `rbac.denied` events in the JSON logs (E-01), joined by `request_id`.
- Changes: `GET /api/v1/admin/audit-logs`, or the `audit_logs` table, filterable by actor, subject and city.
- Deployment state: `GET /api/v1/admin/system/status` (global staff only).

## Not in place yet

On-call rota and contacts; alerting; a status page; a tested secret-rotation procedure for `APP_KEY`; legal review of the notification step.
