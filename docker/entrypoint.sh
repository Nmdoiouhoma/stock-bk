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

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

exec php -S 0.0.0.0:${PORT} -t public
