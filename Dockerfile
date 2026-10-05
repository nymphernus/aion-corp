FROM php:8.1-apache

# Устанавливаем расширения
RUN docker-php-ext-install mysqli pdo pdo_mysql opcache

# Stage 8-финал: GD с jpeg/png/webp/freetype для модуля сжатия изображений.
# Без jpeg-кодека imagejpeg() нет — конвертация webp->jpg невозможна.
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

# Stage 8: лимит загрузки должен проходить целиком (новый максимум 10 МБ).
# Дефолт upload_max_filesize=2M отсекал бы большие файлы на транспортном
# уровне с UPLOAD_ERR_INI_SIZE ещё до нашей проверки.
RUN echo 'upload_max_filesize = 10M' > /usr/local/etc/php/conf.d/uploads.ini \
    && echo 'post_max_size = 12M' >> /usr/local/etc/php/conf.d/uploads.ini

# Настраиваем Apache для непривилегированного пользователя
ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV APACHE_LOG_DIR=/var/log/apache2
ENV APACHE_LOCK_DIR=/var/lock/apache2
ENV APACHE_PID_FILE=/var/run/apache2/apache2.pid

# Меняем порт Apache на 8080 (непривилегированный)
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/:80/:8080/' /etc/apache2/sites-available/000-default.conf

# Включаем модули
RUN a2enmod rewrite \
    && a2enmod headers \
    && a2enmod expires \
    && a2enmod deflate

# Копируем код с правильными правами
COPY --chown=www-data:www-data src/ /var/www/html/

# Копируем entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Очищаем временные файлы
RUN rm -rf /var/lib/apt/lists/* \
    && rm -rf /tmp/pear/

# Переключаемся на www-data
USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
