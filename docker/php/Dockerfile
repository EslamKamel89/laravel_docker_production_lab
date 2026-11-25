FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    git \
    unzip \
    procps \
    iproute2 \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    zip \
    bcmath \
    pcntl \
    opcache \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

# PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

# Prepare writable Laravel directories
RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
