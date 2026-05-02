### Arovolife PHP 8.4 image ###
# Minimal, opinionated image for Laravel 13. Keep it small and predictable.
FROM php:8.4-fpm-alpine AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache \
        bash \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        mysql-client \
        linux-headers \
        git \
        unzip \
        curl \
        autoconf \
        g++ \
        make \
        libxml2-dev \
        openssl-dev \
        pcre-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        mbstring \
        xml \
        zip \
        gd

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer 2.x
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# User + working dir
RUN addgroup -S -g 1000 app && adduser -S -G app -u 1000 app
RUN mkdir -p /var/www/html && chown -R app:app /var/www/html
WORKDIR /var/www/html

USER app
EXPOSE 9000
CMD ["php-fpm"]
