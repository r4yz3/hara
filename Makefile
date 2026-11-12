# Linux, macOS and WSL. On Windows use: .\infra\scripts\dev.ps1 <target>
# UNVERIFIED: `make` is not installed on the authoring workstation.

COMPOSE := docker compose -f infra/compose.yaml --env-file .env

.PHONY: setup up down migrate seed-demo check logs

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
	@echo "seed-demo is not available yet: the gated DevelopmentSeeder is backlog item D-05 (Sprint 3)." && exit 1

logs:
	$(COMPOSE) logs -f --tail 200

check:
	pnpm lint
	pnpm typecheck
	pnpm build
	$(COMPOSE) exec -T api vendor/bin/pint --test
	$(COMPOSE) exec -T api vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	$(COMPOSE) exec -T api php artisan test
	cd apps/rider-flutter && flutter analyze && flutter test
