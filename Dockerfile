# syntax=docker/dockerfile:1

FROM php:8.4-apache AS base

# pcntl is what lets the Reverb and queue processes shut down cleanly.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && a2enmod rewrite

WORKDIR /var/www/html


FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Dependencies first so code changes don't reinstall them.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && php artisan package:discover


FROM node:24-alpine AS assets

# The browser connects to Reverb on the address published by compose. These are baked into the bundle.
ARG VITE_APP_NAME=Antre
ARG VITE_REVERB_APP_KEY=antre-local-key
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8090
ARG VITE_REVERB_SCHEME=http
ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
# Tailwind scans Laravel's pagination views for class names.
COPY --from=vendor /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build


FROM base

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
