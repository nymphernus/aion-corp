FROM php:8.1-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql opcache

RUN apt-get update && apt-get install -y \
        libjpeg-dev \
        libpng-dev \
        libwebp-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd \
        --with-jpeg \
        --with-webp \
        --with-freetype \
    && docker-php-ext-install gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN echo 'upload_max_filesize = 10M' > /usr/local/etc/php/conf.d/uploads.ini \
    && echo 'post_max_size = 12M' >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/uploads.ini

ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV APACHE_LOG_DIR=/var/log/apache2
ENV APACHE_LOCK_DIR=/var/lock/apache2
ENV APACHE_PID_FILE=/var/run/apache2/apache2.pid

RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/:80/:8080/' /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite \
    && a2enmod headers \
    && a2enmod expires \
    && a2enmod deflate

COPY --chown=www-data:www-data src/ /var/www/html/

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN rm -rf /var/lib/apt/lists/* \
    && rm -rf /tmp/pear/

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
