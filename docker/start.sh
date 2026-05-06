#!/usr/bin/env sh
set -eu

: "${PORT:=10000}"

if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_URL:-}" ]; then
    export DB_URL="$DATABASE_URL"
fi

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache public

if [ ! -e public/storage ]; then
    php artisan storage:link || true
fi

php artisan config:clear --no-interaction
php artisan view:clear --no-interaction
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction

envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

php-fpm -D
exec nginx -g 'daemon off;'
