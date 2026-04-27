# Laravel API — Railway / Docker
# composer.lock: spatie/activitylog ^8.4, zipstream ^8.3, phpspreadsheet ext-zip
FROM php:8.4-cli-bookworm

# Docker build: composer as root; phpspreadsheet/Excel needs ext-zip
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip \
    libpng-dev libonig-dev libxml2-dev libpq-dev \
    libfreetype6-dev libjpeg62-turbo-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
       pdo pdo_pgsql pdo_mysql zip mbstring exif pcntl bcmath gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && php -r "file_exists('bootstrap/cache') || mkdir('bootstrap/cache', 0755, true);"

# İlk açılışta (volume yok) izin; Railway’de de çalışır
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8080

ENV PHP_CLI_SERVER_WORKERS=4

# PORT Railway verir. JSON exec form (sinyal / init davranisi)
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache 2>/dev/null; exec php artisan serve --host=0.0.0.0 --port=\"${PORT:-8080}\""]
