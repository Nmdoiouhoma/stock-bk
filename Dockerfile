FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    openssl \
    && docker-php-ext-install \
        pdo_pgsql \
        intl \
        zip \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── dependencies ──────────────────────────────────────────────────────────────
FROM base AS vendor

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ── dev ───────────────────────────────────────────────────────────────────────
FROM base AS dev

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]

# ── prod ──────────────────────────────────────────────────────────────────────
FROM base AS prod

ENV APP_ENV=prod

COPY --from=vendor /app /app
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/entrypoint.sh"]
