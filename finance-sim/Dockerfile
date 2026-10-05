FROM php:8.4-cli-alpine
RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo_sqlite
WORKDIR /app
COPY index.php .
ENV PHP_CLI_SERVER_WORKERS=24
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]