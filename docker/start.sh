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

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "RUN_MIGRATIONS=true; running Laravel migrations..."
    attempts=0

    until php artisan migrate --force --no-interaction; do
        attempts=$((attempts + 1))

        if [ "$attempts" -ge 5 ]; then
            echo "Database migrations failed after ${attempts} attempts." >&2
            exit 1
        fi

        echo "Database migrations failed; retrying in 5 seconds..." >&2
        sleep 5
    done

    echo "Laravel migrations completed."
fi

if [ "${RUN_DEMO_SEEDER:-false}" = "true" ]; then
    DEMO_SEEDER_CLASS="${DEMO_SEEDER_CLASS:-CurrentVersionDemoSeeder}"
    echo "RUN_DEMO_SEEDER=true; running demo seeder [${DEMO_SEEDER_CLASS}]..."
    php artisan db:seed --class="${DEMO_SEEDER_CLASS}" --force --no-interaction
    echo "Demo seeder [${DEMO_SEEDER_CLASS}] completed."
fi

envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

php-fpm -D
exec nginx -g 'daemon off;'
