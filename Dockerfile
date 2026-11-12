FROM php:8.4-cli

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions zip bcmath intl gd exif pcntl pdo_sqlite

RUN apt-get update && apt-get install -y git unzip curl \
 && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y nodejs \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN cp .env.example .env
RUN composer install --no-dev --optimize-autoloader --no-interaction
RUN php artisan key:generate
RUN npm ci
RUN npm run build
RUN rm -rf .env node_modules \
 && touch database/database.sqlite \
 && chmod -R 775 storage bootstrap/cache database

ENV PHP_CLI_SERVER_WORKERS=4
CMD ["sh", "-c", "php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php -S 0.0.0.0:${PORT:-10000} -t public public/index.php"]