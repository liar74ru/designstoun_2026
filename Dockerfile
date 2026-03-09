# ============================================================
#  STAGE 1 — сборка фронтенда
# ============================================================
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --silent

COPY resources/ resources/
COPY vite.config.js postcss.config.js tailwind.config.js ./
COPY public/ public/

RUN npm run build


# ============================================================
#  STAGE 2 — PHP-зависимости (только prod)
#  Используем php:8.2 + composer чтобы версия PHP совпадала
#  с финальным образом и composer.lock не конфликтовал
# ============================================================
FROM php:8.2-alpine AS vendor

WORKDIR /app

# Устанавливаем composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Нужные расширения для composer install
RUN apk add --no-cache postgresql-dev libzip-dev oniguruma-dev icu-dev \
    && docker-php-ext-install pdo pdo_pgsql zip mbstring intl

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts


# ============================================================
#  STAGE 3 — финальный образ
# ============================================================
FROM php:8.2-fpm-alpine AS app

# ── Системные пакеты ─────────────────────────────────────────
RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    curl \
    bash

# ── PHP-расширения ────────────────────────────────────────────
RUN docker-php-ext-install \
    pdo pdo_pgsql pgsql \
    mbstring exif pcntl bcmath gd zip intl opcache

# ── PHP настройки ─────────────────────────────────────────────
RUN cat > /usr/local/etc/php/conf.d/app.ini <<'EOF'
upload_max_filesize = 32M
post_max_size       = 32M
max_execution_time  = 60
memory_limit        = 256M
date.timezone       = Europe/Moscow
display_errors      = Off
log_errors          = On
error_log           = /proc/1/fd/2
expose_php          = Off
EOF

RUN cat > /usr/local/etc/php/conf.d/opcache.ini <<'EOF'
opcache.enable                  = 1
opcache.memory_consumption      = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files   = 10000
opcache.validate_timestamps     = 0
opcache.save_comments           = 1
opcache.fast_shutdown           = 1
EOF

# ── PHP-FPM пул ───────────────────────────────────────────────
RUN cat > /usr/local/etc/php-fpm.d/www.conf <<'EOF'
[www]
user  = www-data
group = www-data
listen = /run/php-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660
pm                   = dynamic
pm.max_children      = 20
pm.start_servers     = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests      = 500
clear_env            = no
php_admin_value[error_log]  = /proc/self/fd/2
php_admin_flag[log_errors]  = on
EOF

# ── Nginx и Supervisor — из папки docker/ ────────────────────
RUN mkdir -p /etc/supervisor/conf.d

COPY docker/nginx.conf        /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf  /etc/supervisor/conf.d/supervisord.conf

# ── Entrypoint ────────────────────────────────────────────────
RUN cat > /usr/local/bin/entrypoint.sh <<'SCRIPT'
#!/bin/bash
set -e

echo "→ Ожидаем PostgreSQL..."
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
SCRIPT

RUN chmod +x /usr/local/bin/entrypoint.sh

# ── Приложение ────────────────────────────────────────────────
WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor  --chown=www-data:www-data /app/vendor        ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

RUN mkdir -p storage/logs storage/framework/{sessions,views,cache} bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
