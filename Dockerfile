FROM php:8.3-cli
RUN apt-get update && apt-get install -y libcurl4-openssl-dev && docker-php-ext-install curl || true
WORKDIR /app
COPY . .
CMD php -S 0.0.0.0:$PORT
