# ADR-014: Production image is PHP-FPM behind nginx, both unprivileged

- Status: proposed (agent decision, reversible; needs the product owner's review)
- Date: 2026-11-13
- Backlog: E-05

## Context
The only API image was a development image: it runs `php artisan serve` as root with the source mounted from the host. The spec (11, 12) asks for a two-tier deployment behind an HTTPS proxy, with a deploy and rollback that have been rehearsed. No hosting provider has been chosen (E-07), so the image must not assume one.

## Decision
- Two images from one Dockerfile, `infra/docker/prod/api.Dockerfile`:
  - `api`: PHP 8.4 FPM on Alpine, code baked in, Composer dependencies without development packages, an authoritative class map, production `php.ini` with OPcache, file uploads off, `expose_php` off. Runs as `www-data`; only `storage/` and `bootstrap/cache/` are writable, by that user alone. Composer and the tests are removed from the image.
  - `web`: nginx (`nginxinc/nginx-unprivileged`), running as `nginx`, serving `backend/public/` and passing every other request to `api:9000`. Hidden files and any `.php` path other than the front controller return 404.
- Configuration comes only from the environment at run time. The entry point caches configuration and routes on start, and `ProductionGuard` refuses to serve with unsafe settings.
- TLS ends before nginx, at whatever proxy or load balancer the host provides.
- nginx logs the requested path without its query string and never a request body (ADR-013), plus the request id. PHP-FPM's own access log is off so each request is logged once.
- Both images run `apk upgrade` at build time, and CI scans both (E-04).

PHP-FPM behind nginx is the arrangement in Laravel's deployment documentation. It needs no new Composer package, unlike Octane, FrankenPHP or RoadRunner.

## Consequences
- Releases are image tags. Rolling back the application means running the previous tag. Rolling back the schema is a separate, deliberate step (see `docs/operations/runbook.md`).
- Migrations run as a one-off container from the new release, never on start, so several API containers can run without racing each other.
- Base images are referenced by tag, not digest, so a rebuild picks up security fixes. The trade-off is that two builds of one commit can differ. Each release must be built once and the same image promoted.
- The `web` image carries a copy of `public/`. A change there needs both images rebuilt from the same commit.

## Not decided here
- Where the images run, and what terminates TLS (E-07).
- A queue worker and scheduler container: Phase 1 has no queued jobs, so there is nothing to run.
- A read-only root filesystem: the entry point writes the configuration cache. It needs writable mounts for `storage/` and `bootstrap/cache/`, which depends on the host.
- Trusted proxies: behind the host's proxy, Laravel will see plain HTTP until the proxy's address is trusted, and only then send HSTS (E-02). That needs the proxy's address, which comes with the host.
