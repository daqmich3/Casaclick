# CasaClick — multi-stage build for Railway (no NodeSource curl script)

# 1) PHP dependencies
FROM composer:2 AS vendor
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

COPY . .
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

# 2) Webpack Encore assets (needs vendor/ for @symfony/ux-turbo file: dep)
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY --from=vendor /app .
RUN npm ci && npm run build

# 3) Runtime
FROM php:8.2-cli
WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql zip intl \
    && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=prod
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

RUN mkdir -p var/cache var/log \
    && chmod +x scripts/railway-start.sh

EXPOSE 8080
CMD ["sh", "scripts/railway-start.sh"]
