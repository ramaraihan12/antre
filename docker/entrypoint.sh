#!/bin/sh
set -e

# Make storage and bootstrap/cache writable for www-data, whatever user owns them in the image.
chown -R www-data:www-data storage bootstrap/cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
