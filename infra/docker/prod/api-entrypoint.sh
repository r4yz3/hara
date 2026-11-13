#!/bin/sh
# Caches configuration and routes from this container's environment, then
# starts the given command. Runs as www-data. ProductionGuard checks the
# configuration on the first request and refuses to serve if it is unsafe.
set -eu
cd /var/www/backend
php artisan config:cache --no-ansi > /dev/null
php artisan route:cache --no-ansi > /dev/null
exec "$@"
