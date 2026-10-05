FROM php:8.1-apache

# Устанавливаем расширения
RUN docker-php-ext-install mysqli pdo pdo_mysql opcache

# Stage 8: лимит загрузки изображений 5 МБ должен проходить целиком.
# Дефолт upload_max_filesize=2M отсекал бы 3-4 МБ файлы на транспортном
# уровне с UPLOAD_ERR_INI_SIZE ещё до нашей проверки размера.
RUN echo 'upload_max_filesize = 6M' > /usr/local/etc/php/conf.d/uploads.ini \
    && echo 'post_max_size = 8M' >> /usr/local/etc/php/conf.d/uploads.ini

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
