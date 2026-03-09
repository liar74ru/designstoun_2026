FROM php:8.2-fpm

# Установка системных зависимостей (добавил libpq-dev для PostgreSQL)
RUN apt-get update && apt-get install -y \
    nginx \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \          # Добавлено для PostgreSQL
    zip \
    unzip \
    nodejs \             # Добавлен Node.js
    npm                  # Добавлен npm

# Очистка кэша
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Установка PHP расширений (ЗАМЕНИЛ pdo_mysql на pdo_pgsql)
RUN docker-php-ext-install pdo_pgsql pgsql mbstring exif pcntl bcmath gd

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Установка рабочей директории
WORKDIR /var/www/html

# Копирование файлов проекта
COPY . .

# Установка зависимостей Laravel
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Сборка фронтенда
RUN npm install && npm run build

# Настройка прав (Timeweb обычно требует прав www-data или root)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Конфигурация Nginx
COPY ./nginx.conf /etc/nginx/sites-available/default

# Скрипт запуска с миграциями
RUN echo '#!/bin/bash\n\
# Ждем, пока база данных будет готова (опционально)\n\
echo "Ожидание PostgreSQL..."\n\
sleep 5\n\
\n\
# Запуск миграций\n\
php artisan migrate --force\n\
php artisan db:seed --force\n\
\n\
# Запуск Nginx и PHP-FPM\n\
service nginx start\n\
php-fpm' > /start.sh && chmod +x /start.sh

# Открытие порта (используем порт, который дает Timeweb)
EXPOSE 8080

# Запуск через скрипт
CMD ["/start.sh"]
