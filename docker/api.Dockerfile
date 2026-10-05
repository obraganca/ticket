# syntax=docker/dockerfile:1
# PHP 8.4 em tudo (composer.json exige ^8.4 porque o composer.lock fixa Symfony 8).

# ---------- base: extensões + nginx ----------
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache nginx su-exec postgresql-dev libzip-dev freetype-dev libjpeg-turbo-dev \
        libpng-dev icu-dev oniguruma-dev libxml2-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pcntl gd zip intl bcmath xml mbstring \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
WORKDIR /app
EXPOSE 8000

# ---------- dev: COM dependências de desenvolvimento (Pest, Pint...). Usado pelo compose ----------
FROM base AS dev
COPY api/composer.json api/composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader
COPY api/ .
RUN composer dump-autoload --no-interaction

# ---------- prod: sem dev deps, autoload otimizado ----------
FROM base AS prod
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader
COPY api/ .
RUN composer dump-autoload --no-dev --optimize --no-interaction
