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
# ============================================================
FROM php:8.4-alpine AS vendor

WORKDIR /app

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

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
FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
    nginx supervisor postgresql-dev libpng-dev \
    libzip-dev oniguruma-dev icu-dev curl bash

RUN docker-php-ext-install \
    pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip intl opcache

RUN cat > /usr/local/etc/php/conf.d/app.ini <<'EOINI'
upload_max_filesize = 32M
post_max_size       = 32M
max_execution_time  = 60
memory_limit        = 256M
date.timezone       = Europe/Moscow
display_errors      = Off
log_errors          = On
error_log           = /proc/1/fd/2
expose_php          = Off
EOINI

RUN cat > /usr/local/etc/php/conf.d/opcache.ini <<'EOINI'
opcache.enable                  = 1
opcache.memory_consumption      = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files   = 10000
opcache.validate_timestamps     = 0
opcache.save_comments           = 1
opcache.fast_shutdown           = 1
EOINI

RUN cat > /usr/local/etc/php-fpm.d/www.conf <<'EOINI'
[www]
user  = www-data
group = www-data
listen = /run/php-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660
pm                   = dynamic
pm.max_children      = 10
pm.start_servers     = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests      = 500
clear_env            = no
catch_workers_output = yes
php_admin_value[error_log]  = /proc/self/fd/2
php_admin_flag[log_errors]  = on
EOINI

# Включаем error_log php-fpm master процесса
RUN sed -i 's|^;error_log = log/php-fpm.log|error_log = /proc/1/fd/2|' /usr/local/etc/php-fpm.conf

RUN mkdir -p /etc/supervisor/conf.d
COPY docker/nginx.conf        /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf  /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor  --chown=www-data:www-data /app/vendor        ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

RUN mkdir -p storage/logs storage/framework/{sessions,views,cache} bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
