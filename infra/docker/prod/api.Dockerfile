# Production-style API image (backlog E-05, ADR-014). Build from the repository root:
#   docker build -f infra/docker/prod/api.Dockerfile --target api   -t food-delivery-api:<version> .
#   docker build -f infra/docker/prod/api.Dockerfile --target web   -t food-delivery-web:<version> .
#
# `api` runs PHP-FPM as an unprivileged user with the code baked in, no
# development dependencies and production php.ini. `web` is nginx, also
# unprivileged, serving the one public entry point and passing requests to
# `api` on port 9000. Neither image holds a secret: configuration comes from
# the environment at run time, and ProductionGuard refuses unsafe settings.
#
# Not yet run anywhere but this workstation; there is no staging host (E-07).

FROM composer:2.11.2 AS composer

# Dependencies only, so this layer is reused until composer.lock changes.
FROM composer AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

FROM php:8.4-fpm-alpine3.23 AS api

# apk upgrade first: the base image can lag behind Alpine's security fixes (E-04).
RUN apk upgrade --no-cache \
    && apk add --no-cache icu-libs libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY infra/docker/prod/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY infra/docker/prod/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend
COPY --from=vendor /app/vendor ./vendor
COPY backend/ ./
# The vendor stage skipped the extension check (the composer image lacks them); check here.
RUN composer check-platform-reqs --no-dev \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm /usr/bin/composer \
    && rm -rf tests .env .env.* phpunit.xml \
    && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=,o= storage bootstrap/cache

COPY --chmod=0755 infra/docker/prod/api-entrypoint.sh /usr/local/bin/api-entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["api-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

FROM nginxinc/nginx-unprivileged:1.29.8-alpine AS web
USER root
RUN apk upgrade --no-cache
USER nginx
COPY infra/docker/prod/nginx.conf /etc/nginx/conf.d/default.conf
# Only the files nginx may serve directly. index.php is never read by nginx:
# it only needs to exist for try_files; PHP runs in the api container.
COPY backend/public/ /var/www/backend/public/
EXPOSE 8080
