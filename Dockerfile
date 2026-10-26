FROM php:8.5-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev zip \\
    && docker-php-ext-install pdo_mysql intl zip opcache \\
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 9000
CMD ["php-fpm"]
