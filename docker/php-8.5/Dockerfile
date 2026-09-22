FROM php:8.5-cli-alpine3.22

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions xdebug
