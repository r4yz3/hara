# Staging setup on one VPS (E-07)

Status on 2026-11-14: **deployed once on the host by the operator** (release `bce7e3f`). What ran on the host is in section 13; the open items are in section 14. Repeat the deploy before relying on this environment (the reboot test passed on 2026-11-14, see section 13), and record each run in `docs/PHASE1_STATUS.md`.

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
| `TRUSTED_PROXIES` | `172.31.77.10` | the `edge` container's fixed address in `compose.yaml`; the API believes `X-Forwarded-*` only from it. Not the whole subnet: the web app containers share that network and call the API directly |
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
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=customer-web --build-arg NEXT_PUBLIC_API_URL=https://staging-api.haradelivery.com --build-arg NEXT_PUBLIC_MAP_STYLE_URL=https://tiles.haradelivery.com/style-20261113.json -t food-delivery-customer-web:$(git rev-parse --short HEAD) .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=merchant-web --build-arg NEXT_PUBLIC_API_URL=https://staging-api.haradelivery.com --build-arg NEXT_PUBLIC_MAP_STYLE_URL=https://tiles.haradelivery.com/style-20261113.json -t food-delivery-merchant-web:$(git rev-parse --short HEAD) .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=admin-web --build-arg NEXT_PUBLIC_API_URL=https://staging-api.haradelivery.com --build-arg NEXT_PUBLIC_MAP_STYLE_URL=https://tiles.haradelivery.com/style-20261113.json -t food-delivery-admin-web:$(git rev-parse --short HEAD) .
```

`NEXT_PUBLIC_API_URL` is written into the browser bundle, so a different API host means rebuilding the three web images. It is public; never pass a secret as a build argument.

`NEXT_PUBLIC_MAP_STYLE_URL` is also a required web build argument (since release `1ef4016`). The strict CSP reads its origin to allow the tile host, and the browser fetches the style from it. It must be the same URL as the API's `MAP_STYLE_URL` (`https://tiles.haradelivery.com/style-20261113.json` on staging). Changing it means rebuilding the three web images.

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


### First platform owner (once)

Staging starts with no account. After the release that contains ADR-018 is built, migrated (`php artisan migrate --force`, adds one nullable column and one flag column to `staff_invitations`) and running, create the first platform owner from the server. It works only while no active platform owner exists, and it prints a link to the admin app instead of creating a password:

```bash
cd /srv/hara/infra/staging && docker compose run --rm --no-deps api php artisan staff:bootstrap-owner <OWNER_EMAIL>
```

`--no-deps` is enough: it reuses the already running `postgres` on the project network, as the migrate command above does (the database must be up). It needs `ADMIN_APP_URL` in `.env` (already set) and `APP_ENV=staging` passing the production guard.

- Open the printed link (`https://staging-admin.haradelivery.com/invite#token=...`) yourself, set a password of 10 or more characters, sign in, and enrol the authenticator (TOTP) when asked. Save the recovery codes.
- The link is valid 72 hours, works once, and is shown only in this terminal. Do not paste it into chat or a ticket, and do not leave it in shell history or a screen recording.
- Lost or expired link: run it again. If the first link is still live the command refuses; add `--replace` to revoke it and print a new one:
  `docker compose run --rm --no-deps api php artisan staff:bootstrap-owner <OWNER_EMAIL> --replace`
- Once an owner exists the command refuses. Everyone else is invited from the admin app (ADR-016). Design: ADR-018.

### Test rider (once)

Staging has no rider account, so the rider app cannot complete a sign-in. ADR-021 (proposed, awaiting approval) adds a console command that creates one synthetic, approved test rider. Not yet run on staging; the operator runs it.

```bash
cd /srv/hara/infra/staging && docker compose run --rm --no-deps api php artisan rider:invite-test <EMAIL>
```

Optional: `--name=<NAME>` (default "Test rider").

- It creates one user with a random password that nobody knows, one approved `riders` row, and nothing else: no role, no customer profile, no vehicle, no city access. It refuses staff, customer, merchant and real-rider addresses. It is rate limited to 3 runs per 15 minutes.
- It emails a set-password link to the customer app's `/reset-password` page. The token is in the URL fragment and is valid for 60 minutes, once. Do not paste the link into chat or a ticket. This is also the first real end-to-end test of a password-reset style mail on staging.
- The audit log records `rider_test.created` and `rider_test.link_issued`, without the address or the token.
- Cleanup: there is no delete command. Suspend the test rider's user account when the rider is no longer needed.

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

What exists: a nightly job (`infra/staging/backup.sh`, run by `hara-backup.timer` as `deploy`) that dumps the database, checks the dump, encrypts it, signs the encrypted file, copies both to Cloudflare R2 and prunes old copies; and `infra/staging/restore-drill.sh`, which verifies the signature of the newest off-server copy, decrypts it and restores it in a throwaway container with no network. **Status (2026-11-14): running on staging, release `001f8ee`.** The workstation checks used stand-ins for `age` and `rclone` (neither was installed there). On the server the operator created the R2 bucket, installed `rclone` and `age`, made the signing key, enabled the timer and uploaded a first backup, then passed the restore drill in 11.6. Evidence: `docs/qa/evidence/e07-2026-11-14-staging-deploy.txt`, section 8. The Phase 1 gate item "staging database backup restored successfully" is met by that drill. The evidence records no real restore (11.7), and nothing alerts on a failed run yet.

Design, in short:

| Choice | Why |
|---|---|
| `pg_dump -Fc` through `docker compose exec -T postgres`, to `/srv/hara-backups/hara-staging-<UTC time>-<release>.dump` (mode 600) | Same Postgres 17 client as the server; the database password never leaves the container. Checked with `pg_restore --list` before anything else happens. |
| Local retention: newest 7 | Fast restore without the network. |
| Encryption: **age with a public key** (not gpg with a passphrase file) | The server holds only the public key, so someone who takes the server, or the R2 token, cannot read the backups. With gpg symmetric the passphrase would sit on the same server as the backups. The cost: you must keep the private key safe off the server; lose it and the off-server copies are unreadable. |
| Off-server: rclone to a private R2 bucket, 30 days, pruned by the date in the file name | R2 has no egress fee and has a free tier. The newest 3 files are never pruned, and nothing is pruned if listing fails. |
| Signature: `ssh-keygen -Y sign -n hara-backup` over the **encrypted** file, uploaded as `<name>.dump.age.sig` (uploaded first, so a file never appears without it) | The R2 token sits on the server and the age recipient is public, so whoever obtains the token alone could upload a crafted archive that a restore would then run. The drill and a real restore verify the signature against `allowed_signers` **before decrypting** and refuse a missing or invalid one. Threat model: this protects against an attacker who has only the R2 token (or the bucket). An attacker with access to the server can read the signing key and sign; that is accepted, which is why the drill is also isolated (next row). |
| The drill restores in a throwaway `postgis/postgis` container (same tag as `compose.yaml`), `--network none`, as a non-superuser | A hostile archive restored as superuser could run `COPY ... FROM PROGRAM` and similar. The drill never touches the live container, its volume or its network. The postgis extension needs a superuser, so the drill creates it first in the empty database (no dump content involved), leaves the archive's `EXTENSION` entries and postgis's own `spatial_ref_sys` rows out with `pg_restore -L`, refuses an archive that asks for any other extension, and restores as the role `drill_owner` (NOSUPERUSER) with `--no-owner --no-privileges --exit-on-error`. |
| Schedule: 03:15 Asia/Manila (`OnCalendar` names the timezone; the server clock is CET) | A quiet hour for the users; `Persistent=true` catches up after downtime. |

The dump holds personal data once real accounts exist. Never commit or share it. No IP address, key or bucket secret belongs in the repository.

### 11.1 Cloudflare: a private bucket and a token for that bucket only (dashboard)

1. Cloudflare dashboard, **R2 object storage**, **Create bucket**. Name: `hara-staging-backups`. Leave **public access disabled** (no `r2.dev` URL, no custom domain). Location hint: Asia-Pacific if offered.
2. **R2 object storage, Manage API tokens, Create API token**. Permission: **Object Read & Write**. Under **Specify bucket(s)**, pick `hara-staging-backups` only. Create.
3. Copy the **Access Key ID**, **Secret Access Key** and the **S3 endpoint** (`https://<ACCOUNT_ID>.r2.cloudflarestorage.com`) into your password manager. The secret is shown once. Do not paste them into chat or a ticket.

Free-tier limits (estimates from memory, **verify on the Cloudflare R2 pricing page before relying on them**): 10 GB-month of storage, 1 million Class A (write and list) and 10 million Class B (read) operations per month, no egress fee. One nightly run is a handful of operations. Storage: the development database dumps to about 0.1 MB. Even at 100 MB per dump, 30 copies are 3 GB. Check the size now and then (`rclone size`, section 11.5) and revisit when a dump passes about 300 MB.

### 11.2 Server: tools, key and rclone remote

```bash
sudo apt-get update && sudo apt-get install -y rclone age
```

Ubuntu 24.04 ships rclone 1.60 and age 1.1.1 (R2 needs rclone 1.59 or later; confirm the versions). Check:

```bash
rclone version | head -n 1 && age --version
```

Create the key pair **on your own PC**, not on the server (Windows: `winget install FiloSottile.age`; macOS: `brew install age`):

```bash
age-keygen -o hara-staging-backup.age.key
```

It prints `Public key: age1...`. Put the file `hara-staging-backup.age.key` (the private key) in your password manager and in a second safe place, then delete the loose copy. **If this key is lost, every off-server backup is unreadable.** Put only the public key on the server, root-owned and world-readable (it is public):

```bash
echo 'age1...paste-the-public-key...' | sudo tee /etc/hara/backup.age.pub >/dev/null && sudo chmod 0644 /etc/hara/backup.age.pub && cat /etc/hara/backup.age.pub
```

Write the rclone remote as `deploy`. The secret is read without echo, so it is not in the shell history or the process list:

```bash
umask 077 && mkdir -p ~/.config/rclone && read -rp 'R2 Access Key ID: ' R2_ID && read -rsp 'R2 Secret Access Key: ' R2_SECRET && echo && read -rp 'R2 endpoint (https://<ACCOUNT_ID>.r2.cloudflarestorage.com): ' R2_ENDPOINT
```

```bash
cat > ~/.config/rclone/rclone.conf <<CONF
[r2]
type = s3
provider = Cloudflare
access_key_id = ${R2_ID}
secret_access_key = ${R2_SECRET}
endpoint = ${R2_ENDPOINT}
acl = private
no_check_bucket = true
CONF
unset R2_ID R2_SECRET R2_ENDPOINT; chmod 600 ~/.config/rclone/rclone.conf
```

Check that the token reaches the bucket, and only that bucket:

```bash
rclone lsf r2:hara-staging-backups && echo "bucket reachable"
```

An empty bucket prints only the second line. `rclone lsd r2:` (list all buckets) should fail, because the token is scoped to one bucket.

### 11.2a Signing key for the backups (once)

On the server, as `deploy`. The private key never leaves the server and has no passphrase (the timer must run unattended); the directory is `deploy`-owned, the key mode 0600:

```bash
sudo install -d -m 0750 -o deploy -g deploy /etc/hara/backup-signing && ssh-keygen -q -t ed25519 -N '' -C hara-backup-signing -f /etc/hara/backup-signing/id_ed25519 && chmod 600 /etc/hara/backup-signing/id_ed25519
```

Create `allowed_signers` (public key only; identity and namespace are both `hara-backup`, which the scripts use):

```bash
echo "hara-backup namespaces=\"hara-backup\" $(cut -d' ' -f1,2 /etc/hara/backup-signing/id_ed25519.pub)" > /etc/hara/backup-signing/allowed_signers && cat /etc/hara/backup-signing/allowed_signers
```

Copy that one public line into your password manager as well: if the server is lost, it is what lets you verify the off-server backups on a new machine (`ALLOWED_SIGNERS=<file>`). On a rotation, make a new key and **add** its line to `allowed_signers`; keep the old line for as long as backups signed with it exist. `backup.sh` refuses to run without a readable key of mode 0600 or 0400, so a missing key shows up as a failed unit rather than as unsigned backups.

### 11.3 Install the scripts and the timer

The scripts run from the repository checkout, so they must be committed, pushed and pulled to `/srv/hara` first.

```bash
sudo cp /srv/hara/infra/staging/systemd/hara-backup.service /srv/hara/infra/staging/systemd/hara-backup.timer /etc/systemd/system/ && sudo systemctl daemon-reload
```

See what the timer means in server time (the server is on CET):

```bash
systemd-analyze calendar "*-*-* 03:15:00 Asia/Manila"
```

```bash
sudo systemctl enable --now hara-backup.timer && systemctl list-timers hara-backup.timer
```

### 11.4 Run it once and check

```bash
sudo systemctl start hara-backup.service
```

It blocks until done. Then:

```bash
journalctl -u hara-backup.service -n 20 --no-pager
```

Expect "dump ok", "encrypted", "signed", "uploaded and listed" (the file and its `.sig`) and "done". Then:

```bash
ls -l /srv/hara-backups && rclone lsl r2:hara-staging-backups
```

The local `.dump` must be mode `-rw-------`. The R2 files end in `.dump.age` and `.dump.age.sig`. A failure is non-zero: `systemctl is-failed hara-backup.service` prints `failed` and the journal has a `FAILED` line. Nothing alerts you yet (there is no monitoring), so look at the journal weekly until there is.

Without an upload (for example before the bucket exists): `/srv/hara/infra/staging/backup.sh --no-upload`. It still dumps, verifies, encrypts and signs, and prints "LOCAL ONLY".

Before a deploy that migrates, run the same job by hand with `sudo systemctl start hara-backup.service`. The file name carries the release that was running when the dump was taken.

### 11.5 Housekeeping

```bash
rclone size r2:hara-staging-backups
```

Local dumps are plaintext (mode 600 in a 0700 directory). The server's disk is not an off-server copy.

### 11.6 Restore drill (the gate item)

The drill downloads the newest `.dump.age` and its `.sig`, **verifies the signature against `/etc/hara/backup-signing/allowed_signers` before decrypting** (a missing or invalid signature, or a missing `allowed_signers`, stops it), decrypts, then starts a throwaway container `hara-drill-<time>` from the postgis image named in `compose.yaml` (`docker run --rm -d --network none`, random password, no volume you keep), restores into it as the non-superuser `drill_owner`, runs the sanity queries and removes the container (also on failure). It never touches the live postgres container, volume or network. It needs the **private** age key for a few minutes. From your PC (fill in the server; no IP is stored in the repository):

```bash
scp hara-staging-backup.age.key deploy@<SERVER_IP>:/dev/shm/backup.age.key
```

On the server:

```bash
AGE_IDENTITY=/dev/shm/backup.age.key /srv/hara/infra/staging/restore-drill.sh
```

Expect "downloading", "signature verified", "decrypting", "starting throwaway container", "restoring as drill_owner (no superuser)", a table count above 0, a migration count above 0 with the latest migration name, "RESTORE DRILL PASSED" and "throwaway container ... removed". To restore a particular file instead of the newest, add `--file hara-staging-<time>-<release>.dump.age`. The postgis image is already on the server because the live database runs it (the container has no network, so it could not pull it). Then remove the key from the server, whether the drill passed or not:

```bash
shred -u /dev/shm/backup.age.key; ls /dev/shm/backup.age.key
```

`ls` must say the file does not exist (`/dev/shm` is memory, so it also does not survive a reboot). Confirm no drill container remains:

```bash
docker ps -a --filter name=hara-drill- --format '{{.Names}}'
```

It must print nothing. Check once, on the server, that the signature check bites: copy a `.dump.age` and its `.sig` into a temp directory, append a byte to the `.age` copy and run `restore-drill.sh --local <copy>.dump.age`; it must stop with "signature check FAILED" and never print "decrypting".

Record the date, the file name and the output in `docs/PHASE1_STATUS.md` once the drill has passed. Repeat it after any change to the scripts, the keys or the Postgres version, and at least monthly.

**Fault injection (repeatable, on the workstation).** `infra/staging/tests/restore-drill-faults.sh` (run `.\infra\staging\tests\restore-drill-faults.ps1`, Docker Desktop running, postgis image present) builds its own tiny signed and encrypted backups and runs the unmodified `restore-drill.sh` against them with stand-ins for `age` and `rclone` (real `ssh-keygen` signing; `USE_REAL_AGE=1` uses real age if installed). It asserts that the drill exits non-zero, starts no restore (no `drill_owner`, no `pg_restore`), and leaves no `hara-drill-*` container or temp directory for: a tampered `.age` (appended or flipped byte), a missing or empty `.sig`, a `.sig` from another key, a wrong signature namespace, a dump cut short or with a damaged header (`pg_restore --list` fails), and an archive that needs `hstore`; it also checks a wrong age key and a dump cut in half, and that a good backup still passes. Every docker call it makes is checked to name only a `hara-drill-*` container, and the fixture is built in its own container, never from the development database. Run it after any change to `restore-drill.sh`, `backup.sh`, the signing scheme or the postgis image. Last run 2026-11-15: 163 checks passed, 0 failed; a mutant with the signature check disabled fails 28 checks. Evidence: `docs/qa/evidence/e03-2026-11-15-restore-faults.txt`. This covers the script logic only: the run used stand-ins, not the real `age` and `rclone` on the server.

### 11.7 Restoring for real

The drill's isolation does not apply here: this restores into the live database, so **authenticity is the control**. Never restore a file whose signature you have not verified.

1. Download the file **and** its `.sig` (`rclone copyto r2:hara-staging-backups/<name>.dump.age <name>.dump.age`, and the same for `<name>.dump.age.sig`).
2. Verify, using the `allowed_signers` from the server or from your password manager (section 11.2a), before anything else:

   ```bash
   ssh-keygen -Y verify -f allowed_signers -I hara-backup -n hara-backup -s <name>.dump.age.sig < <name>.dump.age
   ```

   It must print `Good "hara-backup" signature`. Anything else: stop, do not decrypt, and treat the bucket as compromised (rotate the R2 token).
3. Only then decrypt (`age --decrypt --identity <key> --output <name>.dump <name>.dump.age`). If you have any doubt about the file, restore it into a throwaway container first with `restore-drill.sh --local <name>.dump.age` (it verifies the signature itself).
4. Follow section 12 and `runbook.md` to restore into a freshly created database; do not use `pg_restore --clean` on a database that a newer release has touched, see the finding in `runbook.md`, C.

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

### On the host (2026-11-14, operator run, release bce7e3f)

| Check | Result |
|---|---|
| Compose stack | all 8 services healthy (admin, api, customer, merchant, edge, postgres, redis, web); migrate and `RbacSeeder` ran |
| Cloudflare-only firewall | `hara-cloudflare-firewall status` shows DOCKER-USER jumps for 443 and 80 on eth0 to HARA-CF with Cloudflare RETURN rules; systemd unit and weekly timer enabled |
| Through Cloudflare, from the workstation | staging, staging-merchant and staging-admin 200; staging-api `/` 404 (no route, expected); `/health/live` `{"status":"ok"}`; `/health/ready` 404 through the edge (hidden by design) |
| Origin reachability | direct HTTPS to the origin address gets no response |
| Headers | web: CSP baseline, X-Frame-Options DENY, nosniff, HSTS max-age=604800, `cf-cache-status` DYNAMIC on pages; API: HSTS max-age=31536000, deny-all CSP |
| Redeploy to `1cf50aa` (2026-11-14, operator run) | API images rebuilt; web images re-tagged from `bce7e3f` (unchanged); migrate ran (`2026_11_14_000003`, expand-only) |
| First platform owner (2026-11-14, operator run) | `staff:bootstrap-owner` run once on the server; invitation accepted in the browser; password and TOTP set; signed in to the staging admin app as platform owner (operator-reported, not checked from the workstation) |
| Redeploy to `001f8ee` (2026-11-14, operator run) | All 8 services healthy. From the workstation: the three web hosts return 200 with no `x-powered-by` header; `/health/live` is ok |
| Backups and restore drill (2026-11-14, operator run, release `001f8ee`) | `hara-backup.timer` enabled (next run 03:15 Asia/Manila); first manual backup uploaded to R2 with its `.sig`. Drill passed: signature verified, decrypted, restored into a throwaway no-network container as `drill_owner`, 35 tables, 13 migrations, latest `2026_11_14_000003`; container removed; private key shredded from `/dev/shm` |
| Reboot persistence (2026-11-14, operator run, release `001f8ee`) | Server rebooted at 15:19:12 (server time CET, `uptime -s`). About a minute later all 8 services were Up (healthy) with no manual action. `hara-cloudflare-firewall status` showed the DOCKER-USER jumps for 443 and 80 on eth0 to HARA-CF with 15 IPv4 and 7 IPv6 Cloudflare RETURN rules ending in DROP, so the firewall unit re-applied at boot. `/dev/shm` was empty |
| Redeploy to `515e28c` (2026-11-15, operator run) | API images rebuilt; web images re-tagged. All 9 compose services healthy; new `egress` network for `api` (section 15) |
| Mail through Resend (2026-11-15, operator run) | `mail:test` printed `Mailer: smtp (smtp.resend.com:465)` and `Handed to the mailer`; the test message arrived in the operator's inbox, not spam. `MAIL_REQUIRE_REAL_TRANSPORT=true` set and `api` healthy. Invitation, reset and recovery-code mails not yet checked |
| Redeploy to `1ef4016` (2026-11-15, operator run) | Web images rebuilt with `NEXT_PUBLIC_MAP_STYLE_URL` (section 7). Strict nonce CSP live (ADR-020; `87dbde7`, `1ef4016`). From the workstation: `/` returns 200 with the nonce CSP on customer, merchant and admin, a new nonce per request; a missing path returns 404 with the CSP; `/favicon.ico` returns 200 with the static floor CSP. Operator in a browser: customer location check returned a serviceable area; admin city page and the admin zone map render under the CSP. Offline banner (`368af5a`) is in the same images, not yet exercised on staging. Resend onboarding key deleted (operator-reported) |

Found on the host: empty `apps/*/public` folders are not tracked by git, so the web image build failed; fixed in `bce7e3f`. Evidence: `docs/qa/evidence/e07-2026-11-14-staging-deploy.txt`.

**Still not checked:** IPv6 reaching the host; the public hosts from the workstation after the reboot (not recorded for that boot); the next scheduled backup and firewall-timer run after the reboot; memory under load (limits are ceilings, not measurements); a CI image scan of the web images; a real restore onto staging (section 11.7). The restore drill on the server passed on 2026-11-14 (see the backups row above).

## 14. Decisions and open items for the operator

1. **First admin account.** Done on staging 2026-11-14 (operator-reported): `staff:bootstrap-owner` (ADR-018, section 9) ran on release `1cf50aa`, the invitation was accepted, and the platform owner signed in to the admin app. The ADR is still proposed and needs the operator's review. The command refuses while an active platform owner exists; invite others from the admin app.
2. **Mail.** Decided 2026-11-15: Resend over SMTP, sending domain `mail.haradelivery.com`, `api` on the new `egress` network. Set-up steps are in section 15; run on the host on 2026-11-15 (test message delivered and `MAIL_REQUIRE_REAL_TRANSPORT=true` set, section 13). Before that, `MAIL_MAILER=log` wrote messages, including reset and invitation links, into the API container log. Keep the key out of git.
3. **Demo/synthetic data on staging** (section 9).
4. **Authenticated Origin Pulls** and Cloudflare WAF/rate-limit rules: optional hardening not done.
5. **Backups:** done on staging on 2026-11-14 (section 11): a nightly encrypted copy to R2 and a restore drill that passed on the server. Still open: alerting on a failed run (item 10), repeating the drill at least monthly (11.6), and recording a real restore onto staging (11.7).
6. **Origin certificate renewal:** manual; calendar the expiry date.
7. **HSTS on the web hosts** is set at the edge to one week (`snippets/web-proxy.inc`); raise it when staging is stable. The API sends its own (one year, no `includeSubDomains`).
8. **CSP:** the web apps still send only the static baseline CSP (the nonce trade-off is an open decision in the Phase 1 status).
9. **Image scan** for the three web images in `security.yml`.
10. **Monitoring and alerting** (5xx rate, disk, database, backups): none; the compose healthchecks only restart nothing by themselves (`restart: unless-stopped` restarts a crashed container, not an unhealthy one).
11. **Rider app** is out of scope here. **Map tiles:** the hosting plan (public R2 bucket, `tiles.haradelivery.com`, extract, style, `MAP_STYLE_URL`) is in `map-tiles.md`; written, not run. `MAP_STYLE_URL` stays empty until the operator has done it (ADR-015).

## 15. Email through Resend (invitations, password resets, recovery-code alerts)

Operator's choice on 2026-11-15. The API sends mail over Resend's SMTP service with Laravel's built-in `smtp` mailer, so there is no extra PHP package. Run on the host on 2026-11-15: test message delivered (15.4), and `MAIL_REQUIRE_REAL_TRANSPORT=true` set with `api` healthy (15.5; evidence `docs/qa/evidence/e07-2026-11-14-staging-deploy.txt`, section 11). Not yet checked: invitation, reset and recovery-code mails end to end. Budget: Resend's free tier. Its limits are estimates from memory, **verify them on resend.com/pricing before relying on them**: roughly 3,000 emails a month and 100 a day, one sending domain, short log retention. That is plenty for staging invitations and resets.

How it fits together:

| Piece | Choice |
|---|---|
| Host and port | `smtp.resend.com`, port `465` (implicit TLS). This Laravel version has no `MAIL_ENCRYPTION`: `MAIL_SCHEME=smtps` selects implicit TLS (port 465 also selects it when the scheme is empty). Port 587 with `MAIL_SCHEME=smtp` is STARTTLS that is negotiated only when offered, so prefer 465 |
| Login | user name is the word `resend`, password is the API key |
| Network | `api` joins a second network, `egress` (compose.yaml): not internal, no published port, not `edge`. Outbound connections are NAT-ed by Docker and nothing can connect in through it. `backend` stays internal, so PostgreSQL and Redis are still unreachable from outside and `TRUSTED_PROXIES=172.31.77.10` is unaffected. The only other way to give `api` internet access would be to put it on `edge`, which the web apps share, or to make `backend` non-internal, which would expose the database network to routing |
| Firewall | The `ufw` default allows outbound traffic, and the Cloudflare script only filters inbound 80/443. If the host blocks outbound 465, use 587 with `MAIL_SCHEME=smtp` |
| Sending domain | `mail.haradelivery.com`, a subdomain, so its reputation (and any future mailbox on the root domain) stays separate |

### 15.1 Resend account and domain (operator, in the browser)

1. Sign up at resend.com (free plan). Turn on two-step sign-in for the account.
2. Domains > Add Domain: `mail.haradelivery.com`. Region: choose the one nearest the users if offered.
3. Resend shows DNS records. Add exactly what it shows in the Cloudflare dashboard (DNS > Records), **DNS only (grey cloud)**, never proxied. Typically: a `TXT` for SPF and a `MX` for bounces on the `send.mail` name, a `TXT` for DKIM at `resend._domainkey.mail`. Copy the values from Resend, not from this document: the names and values are generated for you.
4. Optional but recommended, once the above verifies: a DMARC record, type `TXT`, name `_dmarc.mail`, value `v=DMARC1; p=none; rua=mailto:<A_MAILBOX_YOU_READ>`, then tighten the policy later. `p=none` only reports. Do not use a mailbox that does not exist.
5. In Resend, click Verify. DNS can take minutes to hours. Wait for the domain to show Verified.

### 15.2 API key

1. Resend > API Keys > Create API Key. Permission **Sending access**, domain restricted to `mail.haradelivery.com`. Name it `hara-staging`.
2. Copy it into your password manager at once; Resend shows it once. Do not paste it into chat, a ticket or git.

### 15.3 Put the key on the server (no echo)

On the server, as `deploy`. The key is read without echo, so it is not in the shell history or the process list. This replaces the `MAIL_*` lines of `.env` (if your `.env` predates this section, first copy the new Mail block from `.env.example` into it):

```bash
cd /srv/hara/infra/staging && read -rsp 'Resend API key: ' RESEND_KEY && echo
```

```bash
cd /srv/hara/infra/staging && sed -i -e "s|^MAIL_PASSWORD=.*|MAIL_PASSWORD=${RESEND_KEY}|" -e "s|^MAIL_MAILER=.*|MAIL_MAILER=smtp|" .env; unset RESEND_KEY
```

```bash
cd /srv/hara/infra/staging && grep -E '^MAIL_(MAILER|HOST|PORT|SCHEME|USERNAME|FROM_ADDRESS|REQUIRE_REAL_TRANSPORT)=' .env
```

Do not print the `MAIL_PASSWORD` line. Recreate `api` so it picks up the new network and the new settings (a compose file change, so use `up`, not `restart`):

```bash
cd /srv/hara/infra/staging && docker compose up -d --wait api
```

### 15.4 Send a test email

`mail:test` sends one fixed plain-text message, is rate limited to 5 an hour, has no route, and never prints the key:

```bash
cd /srv/hara/infra/staging && docker compose exec -T api php artisan mail:test <YOUR_ADDRESS>
```

Expect "Mailer: smtp (smtp.resend.com:465)" and "Handed to the mailer". Then check the inbox **and the spam folder**, and Resend > Emails for the delivery state (Delivered, Bounced, or an error). A failure prints the transport's message: "Connection could not be established" means the network or an outbound block (see the table); an authentication error means the key or the `resend` user name is wrong; a rejected sender means the domain is not verified or `MAIL_FROM_ADDRESS` is not on `mail.haradelivery.com`. On the free plan you can send only to addresses you own until the domain is verified.

### 15.5 Then require it

Once the test arrived and the invitation, reset and recovery-code mails are exercised, set `MAIL_REQUIRE_REAL_TRANSPORT=true` in `.env` and recreate `api`. `ProductionGuard` then refuses to start if the mailer is ever `log` or `array`, or the SMTP password is empty, so a later edit cannot silently stop mail. To go back to the log mailer, set `MAIL_REQUIRE_REAL_TRANSPORT=false` and `MAIL_MAILER=log` first.

Rollback: set `MAIL_MAILER=log`, `MAIL_REQUIRE_REAL_TRANSPORT=false`, `docker compose up -d --wait api`. Revoke the API key in Resend if it may have leaked. The `egress` network can stay; it is harmless without a mailer that uses it, or remove it from the `api` service to restore the old isolation.
