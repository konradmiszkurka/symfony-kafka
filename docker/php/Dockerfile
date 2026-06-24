FROM php:8.4-fpm-alpine

# Zależności systemowe + rozszerzenia PHP
RUN apk add --no-cache \
        librdkafka-dev \
        icu-dev \
        git \
        unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install intl pdo_mysql opcache \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && apk del .build-deps

# Composer z oficjalnego obrazu
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
