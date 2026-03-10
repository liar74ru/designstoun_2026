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
  echo "LOG_CHANNEL=single"
  echo "LOG_LEVEL=${LOG_LEVEL:-debug}"
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

# Удаляем старый закешированный конфиг
rm -f bootstrap/cache/config.php \
      bootstrap/cache/routes*.php \
      bootstrap/cache/services.php \
      bootstrap/cache/packages.php

# APP_KEY ОБЯЗАН быть задан через ENV переменную в Timeweb.
if [ -z "${APP_KEY}" ]; then
    echo "⚠️  APP_KEY не задан в ENV — генерируем временный."
    echo "⚠️  Скопируйте значение ниже в переменные окружения Timeweb!"
    php artisan key:generate --force --no-interaction --show
    echo "⚠️  Без постоянного APP_KEY сессии будут сбрасываться при каждом деплое."
fi

echo "→ Ожидаем PostgreSQL на ${DB_HOST}:${DB_PORT}..."
until php artisan db:show --no-interaction 2>/dev/null; do
    echo "  ...повтор через 3 сек"
    sleep 3
done
echo "  ✓ БД готова"

echo "→ Применяем миграции..."
php artisan migrate --force --no-interaction

# Seed — только если таблица users пустая
USER_COUNT=$(php artisan tinker --no-interaction --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 || echo "0")
if [ "${USER_COUNT}" = "0" ]; then
    echo "→ Запускаем seed (БД пустая)..."
    php artisan db:seed --force --no-interaction
    echo "  ✓ Seed выполнен"
else
    echo "  → Seed пропущен (users: ${USER_COUNT})"
fi

# Кешируем роуты и вьюхи ПОСЛЕ migrate
php artisan route:cache --no-interaction
php artisan view:cache  --no-interaction
php artisan storage:link --force 2>/dev/null || true

# Права на storage
mkdir -p storage/logs storage/framework/{sessions,views,cache} bootstrap/cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

echo "✅ Запускаем Apache..."
exec apache2-foreground
