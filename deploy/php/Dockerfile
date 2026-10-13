FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j1 pdo_mysql mbstring curl \
    && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN printf '%s\n' \
    'memory_limit=64M' \
    'display_errors=Off' \
    'log_errors=On' \
    'error_log=/proc/self/fd/2' \
    'date.timezone=Europe/Moscow' \
    > "$PHP_INI_DIR/conf.d/fulab.ini"

RUN printf '%s\n' \
    'ServerName localhost' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    > /etc/apache2/conf-available/fulab.conf \
    && a2enconf fulab

WORKDIR /var/www/html/vk_bot
