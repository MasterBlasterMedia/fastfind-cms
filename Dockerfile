FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# The official base already supplies cURL, DOM, mbstring, SQLite and Sodium.
RUN php -r 'foreach (["curl", "dom", "fileinfo", "gd", "mbstring", "openssl", "pdo_mysql", "pdo_pgsql", "pdo_sqlite", "sodium", "xmlwriter", "zip"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing PHP extension: ".$extension); } }'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
