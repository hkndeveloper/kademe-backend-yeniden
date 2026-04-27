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

# Basta config:cache kullanma: APP_KEY/DB yokken komut 1 cikis yapar, serve hic calismaz -> 502.
# Cache'i Release Command / deploy sonrasi veya 'php artisan optimize' ile uretin.
# Railway $PORT'u enjekte eder; 0.0.0.0 zorunlu.
CMD ["sh", "-c", "exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
