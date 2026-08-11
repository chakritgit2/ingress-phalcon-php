FROM composer:2 AS composer

FROM node:22-alpine AS assets

WORKDIR /app

COPY . .
RUN npm install --no-audit --no-fund
RUN npx @tailwindcss/cli -i ./assets/css/app.css -o ./public/css/app.css --minify

FROM phalconphp/cphalcon:v5.9.2-php8.4

USER root

RUN docker-php-ext-install pdo_mysql

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

COPY . .
COPY --from=assets /app/public/css/app.css ./public/css/app.css

RUN chown -R www-data:www-data /app

HEALTHCHECK NONE

EXPOSE 9000

CMD ["php-fpm"]
