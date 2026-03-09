FROM php:8.2-apache

# Установка системных зависимостей и PostgreSQL-расширений
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    curl \
    zip \
    unzip \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Установка Node.js и npm (для сборки фронтенда)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Настройка Apache
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Копирование проекта
COPY . /var/www/html
WORKDIR /var/www/html

# Установка PHP-зависимостей
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Сборка фронтенда
RUN npm install && npm run build

# Права доступа
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
