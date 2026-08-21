FROM composer:2 AS composer

FROM node:22-alpine AS assets

WORKDIR /app

COPY . .
RUN npm install --no-audit --no-fund
RUN npx @tailwindcss/cli -i ./assets/css/app.css -o ./public/css/app.css --minify

FROM phalconphp/cphalcon:v5.9.2-php8.4

USER root

RUN docker-php-ext-install pdo_mysql

# ext-mongodb: built from the upstream release tarball rather than `pecl
# install mongodb` — this base image's bundled PEAR channel cache for
# pecl.php.net is corrupt ("No releases available"), so pecl can't resolve
# the package. Building from source sidesteps PEAR entirely.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl build-essential libssl-dev zlib1g-dev libsasl2-dev pkg-config \
    && curl -sSL -o /tmp/mongodb.tgz https://github.com/mongodb/mongo-php-driver/releases/download/2.4.0/mongodb-2.4.0.tgz \
    && mkdir /tmp/mongodb-src && tar xzf /tmp/mongodb.tgz -C /tmp/mongodb-src --strip-components=1 \
    && ( cd /tmp/mongodb-src && phpize && ./configure && make -j"$(nproc)" && make install ) \
    && docker-php-ext-enable mongodb \
    && rm -rf /tmp/mongodb.tgz /tmp/mongodb-src \
    && apt-get purge -y --auto-remove curl build-essential libssl-dev zlib1g-dev libsasl2-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

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
