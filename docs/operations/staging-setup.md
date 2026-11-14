# Staging setup on one VPS (E-07)

Status on 2026-11-14: **the files and this procedure are written and checked on the authoring workstation only. Nothing here has been run on the host.** The checks that did run are listed in section 13. The first real run will find problems; fix them here and record the result in `docs/PHASE1_STATUS.md`.

What you are setting up: one Ubuntu 24.04 VPS (4 vCPU, 8 GB RAM, 2 GB swap, Docker with the compose plugin, user `deploy`, `ufw` allowing SSH and Cloudflare to 80/443) running the whole stack in Docker, behind Cloudflare (SSL mode Full (strict)):

| Host | Served by |
|---|---|
| `staging.haradelivery.com` | customer Next.js app |
| `staging-merchant.haradelivery.com` | merchant Next.js app |
| `staging-admin.haradelivery.com` | admin Next.js app |
| `staging-api.haradelivery.com` | Laravel API (the ADR-014 images) |

```
Cloudflare --443--> edge (nginx) --> customer | merchant | admin   (network "edge")
                                 --> web (nginx) --> api (PHP-FPM) --> postgres, redis   (network "backend", internal only)
```

Files: `infra/staging/` (compose, nginx, env example, firewall script, systemd units), `infra/docker/prod/web.Dockerfile` (the three Next.js apps), `infra/scripts/staging.ps1` (workstation helper). Background: `deploy.md`, `runbook.md`, `backups.md`, ADR-011 "Staging domain layout", ADR-014.

Rules for this document:

- **Never put a secret in git, in chat or in a ticket.** That covers `.env`, the certificate's private key, the deploy key, `APP_KEY` and passwords. Commands below generate secrets on the server and never print them.
- `<SERVER_IP>`, `<OWNER>/<REPO>` and `<COMMIT>` are placeholders you fill in. The IP address is not recorded anywhere in the repository.
- Run each block on the server (as `deploy`) unless it says "workstation" or "Cloudflare dashboard".

## 0. Before you start

1. The infra files must be committed and pushed so the server can clone them. They are currently uncommitted in the working tree (`infra/staging/`, `infra/docker/prod/web.Dockerfile*`, `infra/scripts/staging.ps1`, the three `apps/*/next.config.ts`, this document). Review, commit and push them yourself; no agent has.
2. Decide the open items in section 14. Two of them block useful testing: the first admin account, and mail.
3. Check the server basics:

```bash
docker version --format '{{.Server.Version}}' && docker compose version && free -h && df -h /
```

```bash
sudo ufw status verbose
```

## 1. Directories on the server

```bash
sudo install -d -m 0755 -o deploy -g deploy /srv/hara
```

```bash
sudo install -d -m 0700 -o deploy -g deploy /srv/hara-backups
```

The certificate directory is readable by the nginx container's user (uid/gid 101), so it is owned by root with group 101:

```bash
sudo install -d -m 0750 -o root -g 101 /etc/hara/certs
```

## 2. Cloudflare Origin Certificate (the private key stays on the server)

Cloudflare's origin certificates are trusted only by Cloudflare. That is what Full (strict) needs. The steps below generate the key on the server and give Cloudflare only a certificate signing request (public), so the private key is never shown anywhere else.

1. Generate the key and request on the server:

```bash
sudo openssl req -new -newkey rsa:2048 -nodes -keyout /etc/hara/certs/origin.key -out /tmp/origin.csr -subj "/CN=haradelivery.com"
```

2. Print the request (it is public) and copy it:

```bash
cat /tmp/origin.csr
```

3. Cloudflare dashboard, domain `haradelivery.com` > SSL/TLS > Origin Server > Create Certificate > "Use my private key and CSR". Paste the request. Hostnames: `haradelivery.com` and `*.haradelivery.com` (the wildcard covers the four flat staging names). Choose a validity period (15 years is the default; a shorter one is fine for staging). Create. Copy the **Origin Certificate** text (begins `-----BEGIN CERTIFICATE-----`). Ignore any private-key box: you supplied your own.

4. Put the certificate on the server (paste, save, exit):

```bash
sudo nano /etc/hara/certs/origin.pem
```

5. Set owners and permissions, then remove the request:

```bash
sudo chown root:101 /etc/hara/certs/origin.key /etc/hara/certs/origin.pem && sudo chmod 0640 /etc/hara/certs/origin.key && sudo chmod 0644 /etc/hara/certs/origin.pem && sudo rm -f /tmp/origin.csr
```

6. Check that the key and certificate belong together (the two lines must be identical) and when the certificate expires:

```bash
sudo openssl x509 -in /etc/hara/certs/origin.pem -noout -pubkey | sha256sum; sudo openssl pkey -in /etc/hara/certs/origin.key -pubout | sha256sum
```

```bash
sudo openssl x509 -in /etc/hara/certs/origin.pem -noout -subject -enddate
```

Put the expiry date in your calendar. Nothing renews this certificate.

## 3. Cloudflare DNS and SSL settings (dashboard)

1. DNS > Records: four `A` records, **Proxied** (orange cloud), pointing at `<SERVER_IP>`: `staging`, `staging-merchant`, `staging-admin`, `staging-api`. If the server has a public IPv6 address you may add `AAAA` records the same way; staging works without them.
2. SSL/TLS > Overview: encryption mode **Full (strict)**.
3. SSL/TLS > Edge Certificates: turn on **Always Use HTTPS**. Minimum TLS version 1.2.
4. Optional later: Authenticated Origin Pulls (Cloudflare presents a client certificate; the edge would verify it). Not set up here; the firewall and the nginx peer check already limit who can connect.

## 4. Get the code onto the server

**Recommendation: a read-only deploy key.** It grants read access to this one repository only, it can be revoked in one click, and the private key never leaves the server. An HTTPS clone needs a personal access token, which is a broader credential stored in the server's git config. If you prefer HTTPS anyway, use a fine-grained token limited to this repository with read-only "Contents" and delete it after the clone.

1. Create the key (no passphrase, so the server can pull unattended):

```bash
ssh-keygen -t ed25519 -N "" -C "hara-staging-deploy" -f ~/.ssh/hara_deploy
```

2. Print the **public** key and add it in GitHub: repository > Settings > Deploy keys > Add deploy key, **leave "Allow write access" unticked**.

```bash
cat ~/.ssh/hara_deploy.pub
```

3. Tell ssh to use it for GitHub:

```bash
printf 'Host github.com\n  IdentityFile ~/.ssh/hara_deploy\n  IdentitiesOnly yes\n' >> ~/.ssh/config && chmod 600 ~/.ssh/config
```

4. Clone (the first connection asks you to confirm GitHub's host key; compare it with the fingerprints GitHub publishes at docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints):

```bash
git clone git@github.com:<OWNER>/<REPO>.git /srv/hara
```

5. Check out the exact commit to deploy (a commit id, not a moving branch):

```bash
git -C /srv/hara checkout <COMMIT>
```

## 5. Firewall: Cloudflare only (Docker bypasses ufw)

Docker adds its own iptables rules ahead of `ufw`'s, so a published port can be open to the world even though `ufw` says otherwise. `cloudflare-only-firewall.sh` adds a `HARA-CF` chain, called from `DOCKER-USER`, that drops anything to ports 80/443 that is not from a Cloudflare range. nginx also refuses non-Cloudflare peers (a second layer). Port 22 is not touched.

1. Install the script and the systemd units as root-owned files (so editing the repository later cannot change what root runs):

```bash
sudo install -m 0755 -o root -g root /srv/hara/infra/staging/cloudflare-only-firewall.sh /usr/local/sbin/hara-cloudflare-firewall
```

```bash
sudo install -m 0644 -o root -g root /srv/hara/infra/staging/systemd/hara-cloudflare-firewall.service /srv/hara/infra/staging/systemd/hara-cloudflare-firewall-refresh.service /srv/hara/infra/staging/systemd/hara-cloudflare-firewall.timer /etc/systemd/system/
```

2. Apply now, then enable at boot and the weekly refresh (the rules are lost on reboot otherwise):

```bash
sudo hara-cloudflare-firewall apply
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now hara-cloudflare-firewall.service hara-cloudflare-firewall.timer
```

3. Look at the result:

```bash
sudo hara-cloudflare-firewall status
```

You should see two jump rules (ports 443 and 80) at the top of `DOCKER-USER` and a `HARA-CF` chain of `RETURN` rules for Cloudflare ranges ending in `DROP`.

Notes:

- After a reboot, Docker may start the containers a moment before the unit applies the rules. Staging data is synthetic; if that window matters, check `status` after each reboot.
- If you change the Docker version or enable IPv6 in Docker, run `status` again and re-test (section 10).
- To undo: `sudo hara-cloudflare-firewall remove`.
- The script was exercised against real `iptables` and `ip6tables` inside a throwaway container on the workstation (apply twice, status, remove). It has not run on this host.

## 6. Write `.env` (secrets are generated here and never printed)

```bash
cd /srv/hara/infra/staging && cp .env.example .env && chmod 600 .env
```

Generate the secrets in place (the commands print nothing):

```bash
cd /srv/hara/infra/staging && sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
```

```bash
cd /srv/hara/infra/staging && sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" .env
```

```bash
cd /srv/hara/infra/staging && sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$(openssl rand -hex 24)|" .env
```

```bash
cd /srv/hara/infra/staging && sed -i "s|^RELEASE=.*|RELEASE=$(git -C /srv/hara rev-parse --short HEAD)|" .env
```

Check that no placeholder is left and review everything except the secrets:

```bash
cd /srv/hara/infra/staging && grep -n "CHANGE_ME" .env; echo "placeholders left: $(grep -c '^[A-Z_]*=CHANGE_ME' .env)"
```

```bash
cd /srv/hara/infra/staging && grep -vE '^(#|$)|(KEY|PASSWORD)=' .env
```

**Back up `.env` (above all `APP_KEY`) somewhere safe and off the server, for example in a password manager.** Without the key, existing sessions and encrypted values cannot be read after a rebuild. Do it by opening the file on the server yourself; do not paste it into chat.

Settings that matter (see `.env.example` for the full list and comments):

| Setting | Value | Why |
|---|---|---|
| `APP_ENV` | `staging` | `ProductionGuard` applies in full |
| `APP_URL` | `https://staging-api.haradelivery.com` | must be https |
| `CUSTOMER_APP_URL`, `MERCHANT_APP_URL`, `ADMIN_APP_URL` | the three web hosts | distinct https origins; also the CORS allow-list |
| `SESSION_DOMAIN` | empty | host-only cookie on the API host (ADR-011) |
| `SESSION_DRIVER` | `database` | suspension and two-step reset revoke sessions only in the database store |
| `TRUSTED_PROXIES` | `172.31.77.0/24` | the `edge` network in `compose.yaml`; the API believes `X-Forwarded-*` only from there |
| `DEMO_PASSWORD` | empty | the guard refuses to serve if it is set |

## 7. Build the images (on the server, one at a time)

The release is the commit id. Build each image once and run that same image; do not rebuild a release under the same tag. Keep the previous release's images for rollback, so do not run `docker image prune -a`. Building the three Next.js apps one after another keeps memory within 8 GB plus swap.

```bash
cd /srv/hara && export RELEASE="$(git rev-parse --short HEAD)" && echo "$RELEASE"
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/api.Dockerfile --target api -t food-delivery-api:$(git rev-parse --short HEAD) .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/api.Dockerfile --target web -t food-delivery-web:$(git rev-parse --short HEAD) .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=customer-web --build-arg NEXT_PUBLIC_API_URL=https://staging-api.haradelivery.com -t food-delivery-customer-web:$(git rev-parse --short HEAD) .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=merchant-web --build-arg NEXT_PUBLIC_API_URL=https://staging-api.haradelivery.com -t food-delivery-merchant-web:$(git rev-parse --short HEAD) .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=admin-web --build-arg NEXT_PUBLIC_API_URL=https://staging-api.haradelivery.com -t food-delivery-admin-web:$(git rev-parse --short HEAD) .
```

`NEXT_PUBLIC_API_URL` is written into the browser bundle, so a different API host means rebuilding the three web images. It is public; never pass a secret as a build argument.

Both API images are scanned in CI (`security.yml`). Do not deploy a release whose scan failed. The three web images are not in the CI scan yet (open item).

Alternative, if the server is too slow to build: build on the workstation with `.\infra\scripts\staging.ps1 build -Release <COMMIT>` and move the images with `docker save <image> | ssh deploy@<SERVER_IP> docker load`. There is no registry in this setup.

## 8. First start: database, migrate, role catalogue, everything else

From here the commands run in `/srv/hara/infra/staging`, where compose finds `.env`.

```bash
cd /srv/hara/infra/staging && docker compose config --quiet && echo compose-ok
```

```bash
cd /srv/hara/infra/staging && docker compose up -d --wait postgres redis
```

Take the pre-deploy backup once there is something to back up (skip on the very first deploy: the database is empty). See section 11.

Migrate with the new release while nothing else serves (migrations must be expand-only, `deploy.md`):

```bash
cd /srv/hara/infra/staging && docker compose run --rm --no-deps api php artisan migrate --force --no-ansi
```

Sync the role catalogue (permissions reach the database only through this seeder):

```bash
cd /srv/hara/infra/staging && docker compose run --rm --no-deps api php artisan db:seed --class=RbacSeeder --force --no-ansi
```

Start everything and wait for the health checks:

```bash
cd /srv/hara/infra/staging && docker compose up -d --wait
```

```bash
cd /srv/hara/infra/staging && docker compose ps
```

If `api` exits at start, its log lists each unsafe setting by name (never the value):

```bash
cd /srv/hara/infra/staging && docker compose logs --tail 40 api
```

## 9. Demo data

**Not possible on staging as the code stands, on purpose.** `DevelopmentSeeder` refuses to run outside the `local` and `testing` environments, and `DEMO_PASSWORD` must be unset or the guard refuses to serve. Do not weaken either. Staging therefore starts with the role catalogue only. If a synthetic city and zone are wanted on staging, that needs a new, explicitly staging-safe seeder (no real coordinates, no accounts with a known password) approved by a human; it is an open decision (section 14). No real restaurant, customer or coordinate data belongs on staging either.

## 10. Smoke tests

On the server, bypassing Cloudflare (the certificate is Cloudflare-only, hence `-k`; `--resolve` sends the request to this machine):

```bash
curl -sk --resolve staging-api.haradelivery.com:443:127.0.0.1 https://staging-api.haradelivery.com/health/live
```

Expected: `{"status":"ok"}`.

```bash
cd /srv/hara/infra/staging && docker compose exec -T web wget -q -O - http://127.0.0.1:8080/health/ready
```

Expected: `{"status":"ready","checks":{"database":true,"redis":true}}`. The same path through the public host must be 404:

```bash
curl -sk -o /dev/null -w '%{http_code}\n' --resolve staging-api.haradelivery.com:443:127.0.0.1 https://staging-api.haradelivery.com/health/ready
```

```bash
curl -sk -o /dev/null -w '%{http_code}\n' --resolve staging-api.haradelivery.com:443:127.0.0.1 https://staging-api.haradelivery.com/api/v1/public/config
```

Expected: 200. Then the three web hosts (each must print 200):

```bash
for h in staging staging-merchant staging-admin; do curl -sk -o /dev/null -w "$h %{http_code}\n" --resolve $h.haradelivery.com:443:127.0.0.1 https://$h.haradelivery.com/; done
```

Through Cloudflare, from **your workstation** (the real path; DNS must have propagated):

```powershell
curl.exe -sS https://staging-api.haradelivery.com/health/live
```

```powershell
curl.exe -sSI https://staging-api.haradelivery.com/api/v1/public/config
```

Expected: 200, `Strict-Transport-Security`, `X-Request-Id`, `Cache-Control: no-store, private`.

```powershell
curl.exe -sS -D - -o NUL -H "Origin: https://staging-admin.haradelivery.com" https://staging-api.haradelivery.com/api/v1/auth/csrf
```

Expected: `Access-Control-Allow-Origin: https://staging-admin.haradelivery.com`, `Access-Control-Allow-Credentials: true`, and two `Set-Cookie` lines (`XSRF-TOKEN` and `fd_admin_session`), both `secure` and `samesite=lax`, the session one `httponly`, neither with a `domain`.

```powershell
curl.exe -sSI https://staging.haradelivery.com/
```

Expected: 200 with `Strict-Transport-Security`, `X-Frame-Options: DENY` and a `Content-Security-Policy` header.

The firewall, from a machine that is **not** Cloudflare (your workstation; a timeout is the pass):

```powershell
curl.exe -sS -k -m 8 https://<SERVER_IP>/
```

Expected: the connection times out. Any HTML or any TLS answer means the Cloudflare-only rules are not in force: stop and fix section 5.

Ports and networks, on the server (only 80 and 443 may be published, and nothing for PostgreSQL or Redis):

```bash
cd /srv/hara/infra/staging && docker compose ps --format 'table {{.Service}}\t{{.Ports}}'
```

```bash
sudo ss -ltnp | grep -E ':(80|443|5432|6379|9000|3000|3001|3002|8080) '
```

Logs and memory:

```bash
cd /srv/hara/infra/staging && docker compose logs --since 10m api web edge | grep -E '"status":5|" 5[0-9][0-9] ' || echo "no 5xx in the last 10 minutes"
```

```bash
docker stats --no-stream
```

Record the memory use in `docs/operations/budget-plan.md` and, if needed, lower or raise the limits in `compose.yaml`.

Real client address: after a request through Cloudflare, the `edge` log line must show your public address, not a Docker address:

```bash
cd /srv/hara/infra/staging && docker compose logs --tail 5 edge
```

## 11. Backup and restore

The dump is plain output of `pg_dump` written by the host user into `/srv/hara-backups` (mode 0700). It holds personal data once real accounts exist: never commit or share it. There is no schedule and no off-server copy yet (E-03); copy backups off the server by hand until there is.

Back up (always before a deploy that migrates):

```bash
cd /srv/hara/infra/staging && umask 077 && docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > /srv/hara-backups/staging-$(date -u +%Y%m%d-%H%M%S).dump
```

Record its checksum:

```bash
cd /srv/hara-backups && ls -t staging-*.dump | head -n 1 | xargs -I{} sh -c 'sha256sum {} > {}.sha256 && cat {}.sha256'
```

Confirm it can be read (a table-of-contents listing; it does not restore):

```bash
cd /srv/hara/infra/staging && docker compose exec -T postgres pg_restore -l < /srv/hara-backups/$(cd /srv/hara-backups && ls -t staging-*.dump | head -n 1) | head -n 5
```

A real restore drill on the server (restore into a scratch database, compare, drop it) is still open; `infra/scripts/db-restore-drill.sh` is written for the development stack and was not adapted. Until a drill has passed here, "staging database backup restored successfully" in the Phase 1 gate is **not met**.

## 12. Rollback

Choose the smallest step that fixes the problem (same logic as `runbook.md`).

**A. Application only (new code wrong, schema fine).** Point `RELEASE` at the previous release (its images are still on the host) and recreate:

```bash
cd /srv/hara/infra/staging && sed -i "s|^RELEASE=.*|RELEASE=<PREVIOUS_COMMIT>|" .env
```

```bash
cd /srv/hara/infra/staging && docker compose up -d --wait
```

Check section 10 again. Previous code must work on the newer schema because migrations are expand-only.

**B. Application and schema (a new migration is the problem).** Roll back with the **new** release's image (only it has the migration's `down()`), then run the previous release as in A. Data in anything that migration added is lost.

```bash
cd /srv/hara/infra/staging && docker compose run --rm --no-deps api php artisan migrate:rollback --step=<NUMBER_OF_MIGRATIONS_IT_ADDED> --force --no-ansi
```

**C. Restore the backup taken before the deploy.** Everything written since is lost. Stop the apps so nothing writes, verify the checksum, replace the database, restore:

```bash
cd /srv/hara/infra/staging && docker compose stop edge customer merchant admin web api
```

```bash
cd /srv/hara-backups && sha256sum -c staging-<TIME>.dump.sha256
```

```bash
cd /srv/hara/infra/staging && docker compose exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" --force "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" -O "$POSTGRES_USER" "$POSTGRES_DB"'
```

```bash
cd /srv/hara/infra/staging && docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --single-transaction --no-owner --exit-on-error' < /srv/hara-backups/staging-<TIME>.dump
```

Then run the previous release as in A (the `up -d --wait` also starts the stopped services). The drop-and-recreate (not `pg_restore --clean`) follows the rehearsal's finding in `runbook.md`. The restore from standard input has not been run on this host.

**Firewall or certificate trouble that locks you out of the web:** SSH is unaffected. `sudo hara-cloudflare-firewall remove` opens 80/443 to everyone again (nginx still refuses non-Cloudflare peers). To take the site down quickly: `docker compose stop edge`.

## 13. What was checked on the workstation (2026-11-14), and what was not

Run on Windows 11 with Docker Desktop:

| Check | Result |
|---|---|
| `pnpm lint`, `pnpm typecheck`, `pnpm build`, `pnpm test` | all passed (normal builds are unchanged: standalone output is on only when `NEXT_OUTPUT=standalone`) |
| `docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=<app>-web ...` for customer, merchant and admin | all three built; images about 320 to 333 MB; run as uid 1000 (`node`); the customer image served `/favicon.ico` and `/` with the security headers |
| `docker compose config --quiet` on `infra/staging/compose.yaml` (`staging.ps1 config`) | valid |
| Whole stack started under a throwaway project name with throwaway secrets, a self-signed certificate and workstation-built images | all 8 services healthy; migrate and `RbacSeeder` ran; four hosts answered 200 through the edge; HTTP redirected to HTTPS; an unknown host closed the TLS handshake; `/health/ready` was 404 through the edge and healthy inside; HSTS and `Secure; SameSite=Lax` cookies and CORS for the admin origin were right; `CF-Connecting-IP` showed up as the client address in the edge log; PostgreSQL was reachable from `api` and not from `edge`; only `edge` published ports (80 and 443); a `pg_dump` custom-format dump from the host worked. The stack and its volumes were removed afterwards |
| `cloudflare-only-firewall.sh apply/apply/status/remove` against real `iptables`/`ip6tables` in a throwaway Ubuntu 24.04 container | rules installed once (idempotent), 15 IPv4 and 7 IPv6 ranges, removed cleanly |
| `bash -n` on the scripts | passed |

Found and fixed on the way: an API image built from an older commit lacked `config/trustedproxy.php`, so the API ignored `TRUSTED_PROXIES` and sent no HSTS. Build the release images from the commit you deploy.

**Not checked:** anything on the real host; real Cloudflare (Origin CA certificate, Full (strict), the proxied path); the firewall with Docker's real iptables rules and with IPv6 reaching the host; a reboot (systemd units); memory under load (limits are ceilings, not measurements); a CI image scan of the web images; a restore drill on the server; a staging restore; an admin sign-in (there is no first admin account, below).

## 14. Decisions and open items for the operator

1. **First admin account.** There is no command or seeder that creates the first `platform_owner` outside the development seeder, and staff are otherwise invited by an existing owner. Without it nobody can sign in to the admin app on staging. A small, audited bootstrap command (run once on the host, prints nothing secret) needs a decision and an ADR note; it is not built.
2. **Mail.** `MAIL_MAILER=log` writes messages, including any reset or invitation link, into the API container log. Choosing a provider (open since `budget-plan.md`) also needs outbound network access for `api`: add the `edge` network to the `api` service in `compose.yaml` (the `backend` network has no route out). Keep the provider's key out of git.
3. **Demo/synthetic data on staging** (section 9).
4. **Authenticated Origin Pulls** and Cloudflare WAF/rate-limit rules: optional hardening not done.
5. **Backups:** no schedule, no off-server copy, no server-side restore drill (E-03).
6. **Origin certificate renewal:** manual; calendar the expiry date.
7. **HSTS on the web hosts** is set at the edge to one week (`snippets/web-proxy.inc`); raise it when staging is stable. The API sends its own (one year, no `includeSubDomains`).
8. **CSP:** the web apps still send only the static baseline CSP (the nonce trade-off is an open decision in the Phase 1 status).
9. **Image scan** for the three web images in `security.yml`.
10. **Monitoring and alerting** (5xx rate, disk, database, backups): none; the compose healthchecks only restart nothing by themselves (`restart: unless-stopped` restarts a crashed container, not an unhealthy one).
11. **Rider app and tile hosting** are out of scope here (ADR-015: R2 bucket, `MAP_STYLE_URL` stays empty until then).
