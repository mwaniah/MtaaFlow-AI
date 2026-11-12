FROM node:20 AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.3-cli
RUN apt-get update && apt-get install -y git unzip libzip-dev \
 && docker-php-ext-install zip bcmath \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
COPY --from=assets /app/public/build public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && touch database/database.sqlite \
 && chmod -R 775 storage bootstrap/cache database
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["sh", "-c", "php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php -S 0.0.0.0:${PORT:-10000} -t public public/index.php"]