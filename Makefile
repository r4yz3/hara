# Linux, macOS and WSL. On Windows use: .\infra\scripts\dev.ps1 <target>
# UNVERIFIED: `make` is not installed on the authoring workstation.

COMPOSE := docker compose -f infra/compose.yaml --env-file .env

.PHONY: setup up down migrate seed-demo check logs backup restore-drill

setup:
	test -f .env || cp .env.example .env
	test -f backend/.env || cp .env.example backend/.env
	pnpm install --frozen-lockfile
	cd backend && composer install --no-interaction
	cd backend && (grep -q '^APP_KEY=base64:' .env || php artisan key:generate --ansi)
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
	$(COMPOSE) cp postgres:/tmp/backup.dump infra/backups/food_delivery-$$(date -u +%Y%m%d-%H%M%S).dump

# Restores the newest backup into a scratch database, verifies it, removes the scratch database.
restore-drill:
	$(COMPOSE) cp "$$(ls infra/backups/*.dump | sort | tail -n 1)" postgres:/tmp/backup.dump
	$(COMPOSE) cp infra/scripts/db-restore-drill.sh postgres:/tmp/db-restore-drill.sh
	$(COMPOSE) exec -T postgres sh /tmp/db-restore-drill.sh

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
