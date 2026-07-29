FROM php:8.3-fpm-alpine

# mbstring e json ja vem habilitados na imagem oficial; mysqli e intl nao.
RUN apk add --no-cache icu-libs mysql-client \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
 && docker-php-ext-install -j"$(nproc)" mysqli intl \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Por padrao o php-fpm remove o ambiente do container dos workers, e a
# configuracao do banco chega justamente por variavel de ambiente. Aqui a
# diretiva e acrescentada ao proprio pool www, e nao num arquivo separado com um
# segundo [www], que o php-fpm recusa como pool duplicado.
RUN printf '\nclear_env = no\n' >> /usr/local/etc/php-fpm.d/www.conf

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
