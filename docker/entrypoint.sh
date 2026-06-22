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

# Show which DB host we are targeting (no password)
DB_HOST=$(php -r "echo parse_url(getenv('DATABASE_URL'), PHP_URL_HOST);")
DB_PORT=$(php -r "\$p = parse_url(getenv('DATABASE_URL'), PHP_URL_PORT); echo \$p ?: 5432;")
DB_NAME=$(php -r "echo ltrim(parse_url(getenv('DATABASE_URL'), PHP_URL_PATH), '/');")
echo "Connecting to DB: host=$DB_HOST port=$DB_PORT db=$DB_NAME"

# Wait for the database using a direct TCP + PDO check
echo "Waiting for database..."
RETRIES=30
until php -r "
  \$url = getenv('DATABASE_URL');
  \$p   = parse_url(\$url);
  \$dsn = 'pgsql:host='.\$p['host'].';port='.(\$p['port']??5432).';dbname='.ltrim(\$p['path'],'/');
  new PDO(\$dsn, \$p['user'], \$p['pass']);
" > /dev/null 2>&1 || [ "$RETRIES" -eq 0 ]; do
    echo "  database not ready, retrying in 2s... ($RETRIES left)"
    RETRIES=$((RETRIES - 1))
    sleep 2
done

if [ "$RETRIES" -eq 0 ]; then
    echo "ERROR: database did not become ready in time. Check DATABASE_URL env var."
    exit 1
fi

echo "Database ready."

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

exec php -S 0.0.0.0:${PORT} -t public public/index.php
