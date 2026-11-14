# Deploying the web apps and the rider Android app

Status on 2026-11-15: the web procedure was run on staging for release `f42d652` (the three web images, nonce CSP live since `1ef4016`). The rider APK was installed on one device (`docs/qa/evidence/rider-device-2026-11-15.txt`). Nothing here is production. Phase 1 has no app-store distribution: the rider app is a sideloaded APK for test devices only.

Read first: `deploy.md` (what a release is, the expand-only migration rule, the four checks) and `staging-setup.md` (the server layout; section 7 for the build commands; section 12 for the API rollback). This file covers what `deploy.md` leaves out: the three Next.js images and the rider app.

The commands use placeholders. Replace `<COMMIT>` with the short commit id you are deploying and `<PREVIOUS_COMMIT>` with the release that is running now. Never put an IP address, a password or a token in a command or in a ticket.

## 1. Web images on staging (run on the server as `deploy`)

Each Next.js app has its own image, built from one file (`infra/docker/prod/web.Dockerfile`, chosen with `APP`). The compose file runs all three under one `RELEASE` tag, so every app needs an image tagged with the new release, even when its code did not change.

`NEXT_PUBLIC_API_URL` and `NEXT_PUBLIC_MAP_STYLE_URL` are written into the browser bundle at build time. Both are **required** and both are public. `NEXT_PUBLIC_MAP_STYLE_URL` must have the same origin as the API's `MAP_STYLE_URL`, because the strict nonce CSP (ADR-020) allows the tile host from it. The commands below read the value from `.env`, so the two cannot drift apart.

### 1.1 Check out the release and set the variables

```bash
cd /srv/hara && git fetch --quiet origin && git checkout --quiet <COMMIT> && git log -1 --oneline
```

```bash
export RELEASE=<COMMIT> PREVIOUS=<PREVIOUS_COMMIT> && echo "release $RELEASE, previous $PREVIOUS"
```

```bash
export API_PUBLIC_URL=https://staging-api.haradelivery.com && export MAP_STYLE_PUBLIC_URL="$(grep '^MAP_STYLE_URL=' /srv/hara/infra/staging/.env | cut -d= -f2-)" && echo "$MAP_STYLE_PUBLIC_URL"
```

Expected: a `https://tiles.haradelivery.com/...json` URL. If it is empty, stop: the tile host is not configured and the map will not load under the CSP.

### 1.2 Decide which images must be rebuilt

List the files that changed since the running release:

```bash
cd /srv/hara && git diff --name-only $PREVIOUS $RELEASE -- apps packages package.json pnpm-lock.yaml pnpm-workspace.yaml infra/docker/prod/web.Dockerfile
```

Rules:

- A change only under `apps/customer-web/` rebuilds only `customer-web`. The same holds for `merchant-web` and `admin-web`.
- A change under `packages/`, `package.json`, `pnpm-lock.yaml`, `pnpm-workspace.yaml` or `web.Dockerfile` rebuilds **all three**, because the shared packages end up in every app.
- A change to `NEXT_PUBLIC_API_URL` or `MAP_STYLE_URL` rebuilds **all three**, even when no code changed.
- An app with no change is re-tagged from the previous release (1.4). Do not rebuild it: a fresh build is not identical to the one that was tested.

### 1.3 Build the changed apps (one at a time)

Building one after another keeps memory within 8 GB plus swap. Skip any block whose app did not change.

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=customer-web --build-arg NEXT_PUBLIC_API_URL=$API_PUBLIC_URL --build-arg NEXT_PUBLIC_MAP_STYLE_URL=$MAP_STYLE_PUBLIC_URL -t food-delivery-customer-web:$RELEASE .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=merchant-web --build-arg NEXT_PUBLIC_API_URL=$API_PUBLIC_URL --build-arg NEXT_PUBLIC_MAP_STYLE_URL=$MAP_STYLE_PUBLIC_URL -t food-delivery-merchant-web:$RELEASE .
```

```bash
cd /srv/hara && docker build -f infra/docker/prod/web.Dockerfile --build-arg APP=admin-web --build-arg NEXT_PUBLIC_API_URL=$API_PUBLIC_URL --build-arg NEXT_PUBLIC_MAP_STYLE_URL=$MAP_STYLE_PUBLIC_URL -t food-delivery-admin-web:$RELEASE .
```

If the backend changed (`backend/` or `infra/docker/prod/api.Dockerfile`), build the two API images as in `staging-setup.md` section 7 instead of re-tagging them.

### 1.4 Re-tag the unchanged images

Valid only when that image's inputs did not change (1.2). Replace `<name>` with `customer-web`, `merchant-web`, `admin-web`, `api` or `web` (the `web` image is the API's nginx, `--target web` in `api.Dockerfile`). Repeat for each unchanged image.

```bash
docker tag food-delivery-<name>:$PREVIOUS food-delivery-<name>:$RELEASE
```

Check that every image the release uses exists under the new tag (five repositories: `api`, `web`, `customer-web`, `merchant-web`, `admin-web`):

```bash
docker image ls --format '{{.Repository}}:{{.Tag}}' | grep -E "food-delivery-(api|web|customer-web|merchant-web|admin-web):$RELEASE"
```

Expected: five lines.

### 1.5 Migrations (only if the release adds any)

Check whether the release has new migrations:

```bash
cd /srv/hara && git diff --name-only $PREVIOUS $RELEASE -- backend/database/migrations
```

If the list is empty, skip to 1.6. Otherwise follow `deploy.md` steps 1 and 2: back up, migrate with the new release while the old one still serves, then run `RbacSeeder`. Migrations must be expand-only.

### 1.6 Point the stack at the release and start it

```bash
cd /srv/hara/infra/staging && sed -i "s|^RELEASE=.*|RELEASE=$RELEASE|" .env && grep '^RELEASE=' .env
```

```bash
cd /srv/hara/infra/staging && docker compose config --quiet && echo compose-ok
```

```bash
cd /srv/hara/infra/staging && docker compose up -d --wait
```

`--wait` returns when the health checks pass. If it fails, read the logs (below) and roll back with 1.8:

```bash
cd /srv/hara/infra/staging && docker compose ps && docker compose logs --tail 40 customer merchant admin web
```

### 1.7 Smoke checks on the server

Each web host must answer 200. The commands go through the edge on the server, bypassing Cloudflare with `--resolve`; the certificate is Cloudflare-only, hence `-k`:

```bash
for h in staging staging-merchant staging-admin; do curl -sk -o /dev/null -w "$h %{http_code}\n" --resolve $h.haradelivery.com:443:127.0.0.1 https://$h.haradelivery.com/; done
```

Expected: three lines, each `200`.

Each web host must send a Content-Security-Policy header with a nonce (ADR-020). A missing nonce means the page will not run its inline scripts under the strict CSP:

```bash
for h in staging staging-merchant staging-admin; do curl -sk -D - -o /dev/null --resolve $h.haradelivery.com:443:127.0.0.1 https://$h.haradelivery.com/ | grep -i '^content-security-policy' | grep -q "'nonce-" && echo "$h nonce ok" || echo "$h NO NONCE: stop and roll back"; done
```

Expected: `nonce ok` for each host.

The web apps have no `/health/live`: that path is on the API host only. Check the API and its readiness split, as in `staging-setup.md` section 10:

```bash
curl -sk --resolve staging-api.haradelivery.com:443:127.0.0.1 https://staging-api.haradelivery.com/health/live
```

Expected: `{"status":"ok"}`.

```bash
cd /srv/hara/infra/staging && docker compose exec -T web wget -q -O - http://127.0.0.1:8080/health/ready
```

Expected: `{"status":"ready","checks":{"database":true,"redis":true}}`.

Look for 5xx in the last ten minutes:

```bash
cd /srv/hara/infra/staging && docker compose logs --since 10m api web edge customer merchant admin | grep -E '" 5[0-9][0-9] |"status":5' || echo "no 5xx in the last 10 minutes"
```

Then, from the **workstation**, open each host through Cloudflare and confirm that the page renders. On the admin app, confirm that the zone map draws under the CSP.

### 1.8 Rollback (web)

Keep the previous release's images on the host. Do not run `docker image prune -a` or `docker system prune -a`. Rolling back the application is one line and one start (`staging-setup.md` section 12 A):

```bash
cd /srv/hara/infra/staging && sed -i "s|^RELEASE=.*|RELEASE=$PREVIOUS|" .env && grep '^RELEASE=' .env
```

```bash
cd /srv/hara/infra/staging && docker compose up -d --wait
```

Run the smoke checks in 1.7 again. The previous release must work on the newer schema, because migrations are expand-only. If the problem is a migration, use `staging-setup.md` section 12 B or C instead.

The web images are not in the CI scan yet (open item in `staging-setup.md` section 7). A web release is not scanned.

## 2. Rider Android app (on the workstation, PowerShell)

The rider app is built on the Windows workstation, not on the server. Gradle needs **JDK 21**. Android Studio's bundled Java 25 fails the build with `What went wrong: 25.0.4`, so do not use it. Use Microsoft OpenJDK 21 or another JDK 21 install, and put its folder in place of `<JDK21_DIR>` below.

Phase 1 has no app-store distribution. The APK is for test devices only. The release build is still signed with the **debug key** (`apps/rider-flutter/android/app/build.gradle.kts`, a TODO in the file). Do not publish it or give it to people outside the test group. A release signing key is an operator decision.

### 2.1 Check the toolchain

```powershell
flutter --version
```

```powershell
& "<JDK21_DIR>\bin\java.exe" -version
```

Expected: a `21.` version line.

```powershell
adb version
```

Expected: an Android Debug Bridge version line. If `adb` is not found, add the Android SDK `platform-tools` folder to `PATH`.

### 2.2 Point Flutter at JDK 21 (once per machine)

```powershell
flutter config --jdk-dir "<JDK21_DIR>"
```

### 2.3 Run the checks

```powershell
cd F:\PROJECTS\hara\apps\rider-flutter; flutter pub get
```

```powershell
cd F:\PROJECTS\hara\apps\rider-flutter; flutter analyze
```

```powershell
cd F:\PROJECTS\hara\apps\rider-flutter; flutter test test/platform/location_permission_guard_test.dart
```

Expected: all 9 tests pass. This guard does two things. It requires `android.permission.INTERNET` in the main manifest: the first device run showed "No internet" on every request because only the debug and profile manifests had it. It also refuses location permissions and plugins, which Phase 1 forbids.

Then the whole rider suite:

```powershell
cd F:\PROJECTS\hara\apps\rider-flutter; flutter test
```

Expected: all tests pass. The last recorded count was 56 (commit `c272771`); it may have grown.

Check the permission in the main manifest by hand:

```powershell
Select-String -Path F:\PROJECTS\hara\apps\rider-flutter\android\app\src\main\AndroidManifest.xml -Pattern 'android.permission.INTERNET'
```

Expected: one line.

### 2.4 Build the release APK against staging

`API_BASE_URL` is public. It is the API host name, not a secret and not an IP. Use the same host as the web apps, `https://staging-api.haradelivery.com`.

```powershell
cd F:\PROJECTS\hara\apps\rider-flutter; flutter build apk --release --dart-define=API_BASE_URL=https://staging-api.haradelivery.com
```

Output: `apps\rider-flutter\build\app\outputs\flutter-apk\app-release.apk` (about 45 MB). `build/` is git-ignored.

Keep a copy of every APK you install, outside the repository, so that rollback is possible:

```powershell
New-Item -ItemType Directory -Force -Path C:\hara-apks | Out-Null
```

```powershell
Copy-Item F:\PROJECTS\hara\apps\rider-flutter\build\app\outputs\flutter-apk\app-release.apk "C:\hara-apks\rider-<COMMIT>.apk"
```

Do not commit the APK, and do not commit anything under `C:\hara-apks`.

### 2.5 Install on the device

Turn on USB debugging on the phone. On some phones (the Realme device in the evidence file, for one) `adb input` also needs "Disable permission monitoring" under Developer options. That affects test automation only, not the install.

```powershell
adb devices
```

Expected: one line for the device with status `device`, not `unauthorized`. If the phone shows an authorisation prompt, accept it.

```powershell
adb install -r F:\PROJECTS\hara\apps\rider-flutter\build\app\outputs\flutter-apk\app-release.apk
```

Expected: `Success`. `-r` replaces the app and keeps its data. Every build from this workstation uses the same debug key, so `-r` works across rebuilds. If it fails with `INSTALL_FAILED_UPDATE_INCOMPATIBLE`, the phone has a build signed with another key. `adb uninstall com.example.rider_app` removes the app and its local data; then install again.

Check the permission on the device:

```powershell
adb shell dumpsys package com.example.rider_app | Select-String 'android.permission.INTERNET'
```

Expected: one line. No line means the release build cannot reach any API.

Start the app:

```powershell
adb shell monkey -p com.example.rider_app -c android.intent.category.LAUNCHER 1
```

### 2.6 Smoke check on the device

- The sign-in screen renders.
- Sign in with an account that does not exist. Expected: "wrong email or password". That shows the release build reached the staging API and received the expected refusal.
- A successful rider sign-in needs an approved rider on staging. Staging has none until the test rider command runs (`staging-setup.md` section 9, "Test rider"; ADR-021, not yet run on staging). Until then, record a successful sign-in as not verified.

Record the device model, Android version, commit and result in `docs/qa/evidence/` and in `docs/PHASE1_STATUS.md`.

### 2.7 Rollback (rider)

Install the previous APK kept in 2.4:

```powershell
adb install -r "C:\hara-apks\rider-<PREVIOUS_COMMIT>.apk"
```

Expected: `Success`. There is no server-side rollback for the app. The API must still accept the previous app, which is why migrations are expand-only (`deploy.md`).

## 3. Who does what

- The operator runs the server commands in 1.6 to 1.8, or reports them back.
- The workstation builds the APK (2.4) and installs it on test devices (2.5). The server never builds the rider app.
- A release signing key, a store listing and any wider distribution are operator decisions and are not in this procedure.

## 4. If something fails

- A web build fails: fix it on the workstation, commit, then rebuild that app only. Do not re-tag an image over a failed build.
- `up -d --wait` fails: the failing service's health check says why. Roll back with 1.8.
- A host has no CSP nonce: the image is not the release you expect. Check `docker image inspect food-delivery-<name>:$RELEASE` and the `RELEASE` line in `.env`. If it persists, roll back with 1.8.
- The rider app shows "No internet" on every request: the INTERNET permission is missing from the build. Run the guard test in 2.3 and check `dumpsys` in 2.5.
