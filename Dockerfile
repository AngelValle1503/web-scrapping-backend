FROM composer:2 AS dependencies

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

COPY . ./

RUN composer dump-autoload --no-dev --optimize

FROM php:8.4-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache nginx gettext libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql zip \
    && apk del .build-deps

COPY --from=dependencies /var/www/html /var/www/html
COPY docker/nginx.conf.template /etc/nginx/http.d/default.conf.template
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/start-web.sh /usr/local/bin/start-web

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod +x /usr/local/bin/entrypoint /usr/local/bin/start-web

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["/usr/local/bin/start-web"]
