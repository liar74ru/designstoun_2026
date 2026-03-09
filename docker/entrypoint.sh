#!/bin/bash
set -e

cd /var/www/html

# Timeweb Cloud передаёт БД через POSTGRESQL_* переменные.
# Маппим их в стандартные DB_* которые ожидает Laravel.
DB_HOST="${DB_HOST:-${POSTGRESQL_HOST:-127.0.0.1}}"
DB_PORT="${DB_PORT:-${POSTGRESQL_PORT:-5432}}"
DB_DATABASE="${DB_DATABASE:-${POSTGRESQL_DATABASE:-designstoun}}"
DB_USERNAME="${DB_USERNAME:-${POSTGRESQL_USERNAME:-designstoun}}"
DB_PASSWORD="${DB_PASSWORD:-${POSTGRESQL_PASSWORD:-}}"

echo "→ Генерируем .env..."
cat > .env << ENVFILE
APP_NAME=${APP_NAME:-designstoun}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY:-}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-http://localhost}

LOG_CHANNEL=stderr
LOG_LEVEL=${LOG_LEVEL:-error}

DB_CONNECTION=pgsql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MOYSKLAD_TOKEN=${MOYSKLAD_TOKEN:-}
DEFAULT_STORE_ID=${DEFAULT_STORE_ID:-}
ENVFILE

echo "  ✓ .env готов (DB_HOST=${DB_HOST})"

# Генерируем APP_KEY если не задан
if [ -z "${APP_KEY}" ]; then
    echo "→ Генерируем APP_KEY..."
    php artisan key:generate --force --no-interaction
fi

echo "→ Ожидаем PostgreSQL на ${DB_HOST}:${DB_PORT}..."
until php artisan db:show --no-interaction 2>/dev/null; do
    echo "  ...повтор через 3 сек"
    sleep 3
done
echo "  ✓ БД готова"

php artisan config:cache --no-interaction
php artisan route:cache  --no-interaction
php artisan view:cache   --no-interaction
php artisan migrate --force --no-interaction
php artisan storage:link --force 2>/dev/null || true

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
mkdir -p /run && chown www-data:www-data /run

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
