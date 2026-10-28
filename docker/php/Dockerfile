FROM php:8.4-fpm

# Laravel runtime extensions: pdo_mysql (MariaDB), mbstring, zip, exif,
# pcntl (queue/scheduler signals), bcmath, opcache, curl, dom (phpunit),
# gd (avatar resize/re-encode, which also strips embedded metadata).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libxml2-dev libcurl4-openssl-dev libpng-dev libjpeg-dev \
    && docker-php-ext-configure gd --with-jpeg \

    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath opcache curl dom gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/rev2-entrypoint.sh
RUN chmod +x /usr/local/bin/rev2-entrypoint.sh

WORKDIR /var/www

ENTRYPOINT ["rev2-entrypoint.sh"]
CMD ["php-fpm"]
