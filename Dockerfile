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
#  STAGE 3 — финальный образ: Apache + mod_php
# ============================================================
FROM php:8.4-apache AS app

# Системные зависимости + расширения PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libpng-dev libzip-dev libonig-dev libicu-dev \
        curl unzip \
    && docker-php-ext-install \
        pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip intl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# PHP настройки
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

# Apache VirtualHost для Laravel
RUN cat > /etc/apache2/sites-available/000-default.conf <<'EOCONF'
<VirtualHost *:80>
    DocumentRoot /var/www/html/public
    DirectoryIndex index.php index.html

    <Directory /var/www/html/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  /proc/1/fd/2
    CustomLog /proc/1/fd/1 combined
</VirtualHost>
EOCONF

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor   --chown=www-data:www-data /app/vendor        ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build  ./public/build

RUN mkdir -p storage/logs storage/framework/{sessions,views,cache} bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
