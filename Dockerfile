# syntax=docker/dockerfile:1

# ---- base: PHP + Apache + extensions ---------------------------------------
ARG PHP_IMAGE=php:8.4.27-apache
FROM ${PHP_IMAGE} AS base

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.17/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl pdo_mysql opcache zip bcmath sockets gd redis \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# ---- vendor: production dependencies (cached until composer files change) --
FROM base AS vendor
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# ---- runtime ---------------------------------------------------------------
FROM base AS runtime
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .

# .env is a secret-free template; real values come from the environment at runtime.
# The throwaway values below only let the container compile at build time.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && mkdir -p var/cache var/log \
    && DATABASE_URL='mysql://build:build@127.0.0.1:3306/build?serverVersion=8.0&charset=utf8mb4' \
    DOCTRINE_ENCRYPTION_ENCRYPT_KEY='AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
    APP_SECRET=build-only \
    php bin/console cache:warmup --env=prod \
    && php bin/console assets:install public --env=prod \
    && chown -R www-data:www-data var

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

EXPOSE 80
ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
