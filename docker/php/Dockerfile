FROM php:8.3-fpm-alpine

# mbstring e json ja vem habilitados na imagem oficial; mysqli e intl nao.
RUN apk add --no-cache icu-libs mysql-client \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
 && docker-php-ext-install -j"$(nproc)" mysqli intl \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
