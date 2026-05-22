#!/usr/bin/env sh
# Do not use "set -e" — a failed migration must not prevent the HTTP server from starting.

PORT="${PORT:-8080}"

# JWT keys (gitignored)
if [ ! -f config/jwt/private.pem ]; then
  php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true
fi

# Warm prod cache in background (optional; speeds first real request)
(php bin/console cache:warmup --env=prod --no-debug 2>/dev/null || true) &

# Migrations in background — a slow/unreachable DB must not block healthcheck for ~5 minutes
if [ -n "${DATABASE_URL}" ]; then
  (
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1
  ) &
else
  echo "WARN: DATABASE_URL is not set — skipping migrations. Link MySQL in Railway Variables."
fi

echo "Starting PHP on 0.0.0.0:${PORT} ..."
exec php -S "0.0.0.0:${PORT}" -t public
