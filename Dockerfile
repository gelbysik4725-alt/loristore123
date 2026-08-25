FROM php:8.3-cli
WORKDIR /app
COPY . .
# увеличить лимиты загрузки
RUN echo "upload_max_filesize=300M" > /usr/local/etc/php/conf.d/uploads.ini \
 && echo "post_max_size=300M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_execution_time=600" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini
CMD php -S 0.0.0.0:$PORT
