FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The project is bind-mounted here by docker-compose.yml; the image only provides PHP and Composer.
WORKDIR /var/www/html

EXPOSE 8000
