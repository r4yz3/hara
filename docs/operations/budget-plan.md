# Low-cost plan for staging (E-07)

Status: planning estimate, 2026-11-14. **Every price and size below is an estimate from memory and must be verified on the provider's pricing page.** Buying, registering and paying are the operator's actions (AGENTS.md rule 20). The server was bought on 2026-11-14 and is not set up yet (no OS or IP recorded).

## What has to run

Taken from `infra/rehearsal/compose.yaml` (the production-image layout) and `infra/docker/prod/`:

| Part | Role | Rough memory (estimate, not measured on a host) |
|---|---|---|
| `api` (PHP-FPM, `pm.max_children = 16`, `memory_limit = 256M`) | Laravel API | 0.5 to 1 GB typical; the 16-worker ceiling can reach several GB under load, so lower `max_children` on a small host |
| `web` (nginx in front of PHP-FPM) | Upstream of the TLS proxy, port 8080 | under 50 MB |
| `postgres` (`postgis/postgis:17-3.5-alpine`) | Database with PostGIS | 0.5 to 1 GB for staging data |
| `redis` (`redis:8.8.4-alpine`, no persistence) | Sessions, cache, throttling | under 100 MB |
| Next.js customer, merchant and admin apps | Web front ends | about 150 to 300 MB each if run as Node servers. Not in the production images yet: ADR-014 covers the API only, so a web image or `next start` unit is still to be built |
| Host: OS, Docker, a reverse proxy for TLS | | about 0.5 GB |

Mailpit is a development tool and is not deployed; staging needs a real mail provider (open item).

Total: roughly 3 to 4 GB in normal use. A 4 GB machine would be tight once the three Next apps run; **8 GB is the smallest comfortable size**. Disk: 40 GB is plenty for images, the database and backups at staging volume. Add a swap file as a safety net, and keep off-machine backups (E-03 has none yet).

## Plan

| Item | Choice | Rough monthly cost (estimate, verify) |
|---|---|---|
| Server | Contabo Cloud VPS Plus 4: 4 vCPU, 8 GB RAM, 150 GB NVMe, monthly term, region Asia (Japan). Bought 2026-11-14; Japan latency about 79 ms from the operator | about €21.20 a month: €13.50 base + €7.70 region (from the order screen, not an invoice) |
| CDN, DNS, proxy, universal certificate | Cloudflare free plan | 0 |
| Tile storage | Cloudflare R2 free tier: 10 GB storage and free egress (verify limits and the operation quotas). A PMTiles extract for three municipalities should be a small fraction of 10 GB (estimate, check after the extract) | 0 |
| Domain `haradelivery.com` | Bought 2026-11-14. Renewal is yearly, about US$10 to 15 a year (estimate, verify at renewal) | Bought; not in the monthly total |
| CI | GitHub Actions free tier (private-repo minutes are capped; check the monthly allowance against the web, backend and security workflows) | 0 |
| Android build tools | Android SDK, JDK and Flutter are free; a USB phone for testing | 0 |
| Transactional email | Not chosen. Many providers have a free tier; needed before invitations and password resets work on staging | 0 to a few US$ |
| Error alerting, uptime checks | Free tiers (for example a free uptime monitor). Not chosen | 0 |
| Backups off the machine | A second R2 bucket inside the free 10 GB, or a small Contabo object-storage plan | 0 to about US$3 |

**Estimated total: about €21.20 a month for the server (€13.50 base plus €7.70 for the Japan region), plus optional backup storage (0 to about US$3).** The domain is already bought and is not in this monthly total. The earlier US$ VPS estimate is superseded. Production will cost more (separate database host, paid email and SMS, monitoring, payment fees); this plan is for staging only.

## Things to check before buying

1. Resolved by flat names. Cloudflare's free universal certificate covers `haradelivery.com` and `*.haradelivery.com` only, so staging uses `staging.haradelivery.com`, `staging-merchant.haradelivery.com`, `staging-admin.haradelivery.com` and `staging-api.haradelivery.com`. Two-level names such as `api.staging.haradelivery.com` are not used. See ADR-011 "Staging domain layout".
2. Cloudflare's free-plan limits (request body size, proxying of non-web traffic) against this API.
3. Contabo: confirm any setup fee and the backup add-on price. The €13.50 base, €7.70 Japan region add-on and monthly term come from the operator's order screen; check them against the invoice.
4. R2: confirm CORS rules can allow `GET` and `Range` from the app origins, and that the public bucket (custom domain or `r2.dev`) answers byte-range requests.
5. Memory: after the first deploy, measure `docker stats` and lower PHP-FPM `max_children` to fit.
6. GitHub Actions minutes for a private repository.
