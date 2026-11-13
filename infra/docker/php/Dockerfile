# Development image for the Laravel API. Not a production image: it runs the
# built-in server and mounts the source tree. The production images (non-root
# PHP-FPM behind nginx, no dev dependencies) are infra/docker/prod/api.Dockerfile
# (ADR-014).
#
# Built and run locally on 2026-11-13 (Docker 29.9.3).

FROM composer:2.11.2 AS composer

FROM php:8.5-fpm-alpine3.23

# apk upgrade first: the base image can lag behind Alpine's security fixes
# (E-04 found libpq 18.6 with 38 HIGH findings while 18.7 was available).
RUN apk upgrade --no-cache \
    && apk add --no-cache icu-libs libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" bcmath intl pcntl pdo_pgsql pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize

# Do not announce the PHP version in an X-Powered-By header (E-02).
RUN printf 'expose_php = Off\n' > "$PHP_INI_DIR/conf.d/zz-security.ini"

EXPOSE 8000

# --no-reload is required: without it `artisan serve` strips the container's
# environment from the server process and falls back to backend/.env, so the
# values from Compose (database, Redis, session driver) are silently ignored.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
