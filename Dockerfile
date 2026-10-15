FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y git unzip libzip-dev libxml2-dev poppler-utils \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock* ./
RUN if [ -f composer.lock ]; then composer install --no-dev --prefer-dist --no-interaction; else composer install --no-dev --prefer-dist --no-interaction; fi

COPY . .

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
