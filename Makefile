# Linux, macOS and WSL. On Windows use: .\infra\scripts\dev.ps1 <target>
# UNVERIFIED: `make` is not installed on the authoring workstation.

COMPOSE := docker compose -f infra/compose.yaml --env-file .env

.PHONY: setup up down migrate seed-demo check logs backup restore-drill

setup:
	test -f .env || cp .env.example .env
	test -f backend/.env || cp .env.example backend/.env
	pnpm install --frozen-lockfile
	cd backend && composer install --no-interaction
	@# One APP_KEY in both files: the api container reads the root .env, native PHP reads backend/.env.
	@# Never replaces a non-empty key and never prints it.
	@val() { sed -n 's/^APP_KEY=//p' "$$1" 2>/dev/null | sed 's/[[:space:]]*#.*//;s/[[:space:]]*$$//' | head -n1; }; \
	key=$$(val .env); [ -n "$$key" ] || key=$$(val backend/.env); \
	[ -n "$$key" ] || { key=$$(cd backend && php artisan key:generate --show --no-ansi | tail -n1); echo "Generated a new APP_KEY"; }; \
	for f in .env backend/.env; do \
	  if [ -z "$$(val $$f)" ]; then sed "s|^APP_KEY=.*|APP_KEY=$$key|" $$f > $$f.tmp && mv $$f.tmp $$f; echo "Set APP_KEY in $$f"; fi; \
	done
	cd apps/rider-flutter && flutter pub get

up:
	$(COMPOSE) up -d --build --wait

# Stops containers and keeps data volumes. Never add -v here.
down:
	$(COMPOSE) down

migrate:
	$(COMPOSE) exec api php artisan migrate

seed-demo:
	$(COMPOSE) exec -T api php artisan db:seed --class=DevelopmentSeeder

# The dump holds personal data. infra/backups/ is git-ignored; keep it that way.
backup:
	mkdir -p infra/backups
	$(COMPOSE) cp infra/scripts/db-backup.sh postgres:/tmp/db-backup.sh
	$(COMPOSE) exec -T postgres sh /tmp/db-backup.sh
	f=infra/backups/food_delivery-$$(date -u +%Y%m%d-%H%M%S).dump; \
	$(COMPOSE) cp postgres:/tmp/backup.dump $$f && \
	$(COMPOSE) exec -T postgres rm -f /tmp/backup.dump /tmp/db-backup.sh && \
	sha256sum $$f | cut -d' ' -f1 > $$f.sha256

# Restores the newest backup into a scratch database, verifies it, removes the scratch database.
restore-drill:
	f="$$(ls infra/backups/*.dump | sort | tail -n 1)"; test -f "$$f.sha256" && \
	$(COMPOSE) cp "$$f" postgres:/tmp/backup.dump && \
	$(COMPOSE) cp infra/scripts/db-restore-drill.sh postgres:/tmp/db-restore-drill.sh && \
	$(COMPOSE) exec -T -e EXPECTED_SHA256="$$(cat "$$f.sha256")" postgres sh /tmp/db-restore-drill.sh

logs:
	$(COMPOSE) logs -f --tail 200

check:
	pnpm lint
	pnpm typecheck
	pnpm test
	pnpm build
	$(COMPOSE) exec -T api vendor/bin/pint --test
	$(COMPOSE) exec -T api vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	$(COMPOSE) exec -T api php artisan test
	cd apps/rider-flutter && flutter analyze && flutter test
