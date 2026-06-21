FROM php:8.2-cli

# Install system deps & PHP extensions
RUN apt-get update && apt-get install -y \
    git curl zip unzip libzip-dev libpng-dev \
    && docker-php-ext-install pdo pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Node (untuk build Vite)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

WORKDIR /app
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Install & build frontend assets
RUN npm install && npm run build

# Set permission untuk storage & cache
RUN chmod -R 775 storage bootstrap/cache

# Cache config Laravel (jalan saat build, bukan runtime)
RUN php artisan config:clear

EXPOSE 8080

# Jalankan migrasi lalu start server saat container dijalankan
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
