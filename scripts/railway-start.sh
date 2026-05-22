#!/usr/bin/env sh
# Do not use "set -e" — DB/bootstrap errors must not block the HTTP server.

PORT="${PORT:-8080}"

if [ ! -f config/jwt/private.pem ]; then
  php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true
fi

if [ -n "${DATABASE_URL}" ]; then
  echo "Running database migrations..."
  php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1 || true

  echo "Bootstrapping demo users + listings (same as local fixtures)..."
  php bin/console app:bootstrap-users --no-interaction 2>&1 || true

  php bin/console cache:clear --env=prod --no-warmup 2>/dev/null || true
else
  echo "WARN: DATABASE_URL is not set — skipping migrations and demo users."
fi

echo "Starting PHP on 0.0.0.0:${PORT} ..."
exec php -S "0.0.0.0:${PORT}" -t public
