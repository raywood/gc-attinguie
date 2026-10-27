FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql intl zip bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/backend
