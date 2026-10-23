#!/bin/sh
set -e

# Logs and cached views live on a volume, which starts out owned by root.
chown -R www-data:www-data storage bootstrap/cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
