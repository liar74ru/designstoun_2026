# Базовый образ PHP-FPM 8.2
FROM php:8.2-fpm

# 1. Системные зависимости
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libzip-dev libpq-dev libonig-dev libxml2-dev \
    nginx supervisor \
 && docker-php-ext-configure zip \
 && docker-php-ext-install zip pdo pdo_mysql pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# 2. Node.js (18.x)
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
 && apt-get update && apt-get install -y nodejs \
 && rm -rf /var/lib/apt/lists/*

# 3. Composer
RUN curl -sS https://getcomposer.org/installer | php \
 && mv composer.phar /usr/local/bin/composer \
 && chmod +x /usr/local/bin/composer

# 4. Рабочая директория
WORKDIR /var/www/html

# 5. Копируем файлы и ставим PHP-зависимости
COPY . /var/www/html

# Если есть package-lock.json / yarn.lock — можно оптимизировать кэш,
# но для простоты ставим напрямую.
RUN composer install --no-dev --no-interaction --optimize-autoloader

# 6. Сборка фронтенда (vite/webpack)
RUN npm install \
 && npm run build \
 && rm -rf node_modules

# 7. Права
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 8. Конфиг Nginx
RUN rm -f /etc/nginx/sites-enabled/default
COPY ./docker/nginx.conf /etc/nginx/conf.d/default.conf

# 9. Конфиг supervisord
COPY ./docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 80

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
