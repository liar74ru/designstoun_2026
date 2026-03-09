# ============================================================
#  STAGE 1 — сборка фронтенда (Node.js)
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
FROM composer:2.7 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts


# ============================================================
#  STAGE 3 — финальный образ (PHP 8.2 + Nginx + Supervisor)
# ============================================================
FROM php:8.2-fpm-alpine AS app

LABEL maintainer="designstoun"

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
    bash \
    shadow

# ── PHP-расширения ────────────────────────────────────────────
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    opcache

# ── Настройка PHP ─────────────────────────────────────────────
COPY docker/php/php.ini        /usr/local/etc/php/conf.d/app.ini
COPY docker/php/opcache.ini    /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf       /usr/local/etc/php-fpm.d/www.conf

# ── Nginx ─────────────────────────────────────────────────────
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# ── Supervisor (запускает nginx + php-fpm) ────────────────────
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# ── Скрипт первого запуска ────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# ── Приложение ────────────────────────────────────────────────
WORKDIR /var/www/html

# Копируем код приложения
COPY --chown=www-data:www-data . .

# Копируем vendor из stage 2
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

# Копируем собранный фронтенд из stage 1
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

# Создаём нужные папки и права
RUN mkdir -p storage/logs storage/framework/{sessions,views,cache} bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
