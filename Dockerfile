FROM php:8.1-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql opcache

COPY src/ /var/www/html/

RUN a2enmod rewrite \
    && a2enmod headers \
    && a2enmod expires \
    && a2enmod deflate

RUN rm -rf /var/lib/apt/lists/* \
    && rm -rf /tmp/pear/