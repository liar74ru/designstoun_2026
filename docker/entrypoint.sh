#!/bin/bash
set -e

cd /var/www/html

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_DATABASE="${DB_DATABASE:-designstoun}"
DB_USERNAME="${DB_USERNAME:-designstoun}"
DB_PASSWORD="${DB_PASSWORD:-}"

echo "→ Генерируем .env..."
{
  echo "APP_NAME=${APP_NAME:-designstoun}"
  echo "APP_ENV=${APP_ENV:-production}"
  echo "APP_KEY=${APP_KEY:-}"
  echo "APP_DEBUG=${APP_DEBUG:-false}"
  echo "APP_URL=${APP_URL:-http://localhost}"
  echo ""
  echo "LOG_CHANNEL=stderr"
  echo "LOG_LEVEL=${LOG_LEVEL:-error}"
  echo ""
  echo "DB_CONNECTION=pgsql"
  echo "DB_HOST=${DB_HOST}"
  echo "DB_PORT=${DB_PORT}"
  echo "DB_DATABASE=${DB_DATABASE}"
  echo "DB_USERNAME=${DB_USERNAME}"
  printf "DB_PASSWORD=%s\n" "${DB_PASSWORD}"
  echo ""
  echo "SESSION_DRIVER=database"
  echo "CACHE_STORE=database"
  echo "QUEUE_CONNECTION=database"
  echo ""
  echo "MOYSKLAD_TOKEN=${MOYSKLAD_TOKEN:-}"
  echo "DEFAULT_STORE_ID=${DEFAULT_STORE_ID:-}"
} > .env
echo "  ✓ .env готов (DB_HOST=${DB_HOST})"

# Удаляем старый закешированный конфиг — он мог содержать 127.0.0.1
rm -f bootstrap/cache/config.php bootstrap/cache/routes*.php bootstrap/cache/services.php

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

# НЕ кешируем конфиг — Laravel будет читать .env напрямую
# config:cache вредит когда DB_PASSWORD содержит спецсимволы
php artisan route:cache  --no-interaction
php artisan view:cache   --no-interaction
php artisan migrate --force --no-interaction
php artisan storage:link --force 2>/dev/null || true

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
mkdir -p /run && chown www-data:www-data /run

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
