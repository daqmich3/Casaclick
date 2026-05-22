#!/usr/bin/env sh
set -e

# JWT keys (gitignored); generate on first boot if build step did not run
if [ ! -f config/jwt/private.pem ]; then
  php bin/console lexik:jwt:generate-keypair --skip-if-exists
fi

# DATABASE_URL is set by Railway when MySQL is linked
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

exec php -S "0.0.0.0:${PORT:-8080}" -t public
