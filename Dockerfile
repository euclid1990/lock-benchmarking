FROM php:7.2-apache

RUN pecl install redis-4.3.0 \
    && pecl install xdebug-2.7.2 \
    && docker-php-ext-enable redis xdebug

RUN docker-php-ext-install \
    pdo_mysql \
    mysqli

RUN a2enmod rewrite

COPY . /var/www/html

COPY ./000-default.conf /etc/apache2/sites-enabled/000-default.conf

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN mv composer.phar /usr/local/bin/composer

RUN chmod 755 /usr/local/bin/composer

RUN composer install
