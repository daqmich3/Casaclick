# CasaClick — Railway / Docker (PHP 8.2 + Webpack Encore)
FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Node 20 for Encore (Debian packages on php image are often too old)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    && npm ci \
    && npm run build \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists \
    && mkdir -p var/cache var/log \
    && chmod +x scripts/railway-start.sh

EXPOSE 8080

CMD ["sh", "scripts/railway-start.sh"]
