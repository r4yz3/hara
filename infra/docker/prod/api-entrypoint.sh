#!/bin/sh
# Caches configuration and routes from this container's environment, then
# starts the given command. Runs as www-data. `route:cache` boots the
# application, so ProductionGuard checks the settings here: with an unsafe
# setting the container exits at start ("Refusing to start with unsafe
# settings" in its log) and never serves a request.
set -eu
cd /var/www/backend
php artisan config:cache --no-ansi > /dev/null
php artisan route:cache --no-ansi > /dev/null
exec "$@"
