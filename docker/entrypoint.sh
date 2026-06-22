#!/bin/sh
set -e

PORT=${PORT:-10000}

# Generate JWT keys if not present
if [ ! -f config/jwt/private.pem ]; then
    mkdir -p config/jwt
    openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096
    openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem
fi

# Warm up cache
php bin/console cache:warmup --env=prod --no-debug

# Wait for the database to be reachable before running migrations
echo "Waiting for database..."
RETRIES=30
until php bin/console doctrine:query:sql "SELECT 1" --env=prod > /dev/null 2>&1 || [ "$RETRIES" -eq 0 ]; do
    echo "  database not ready, retrying in 2s... ($RETRIES left)"
    RETRIES=$((RETRIES - 1))
    sleep 2
done

if [ "$RETRIES" -eq 0 ]; then
    echo "ERROR: database did not become ready in time. Check DATABASE_URL env var."
    exit 1
fi

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

exec php -S 0.0.0.0:${PORT} -t public
