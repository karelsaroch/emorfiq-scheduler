FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev procps \
    && docker-php-ext-install pdo_mysql pdo_pgsql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    HOME=/tmp \
    TZ=Europe/Prague

RUN echo 'date.timezone=Europe/Prague' > /usr/local/etc/php/conf.d/timezone.ini \
    && echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/memory.ini

WORKDIR /app
