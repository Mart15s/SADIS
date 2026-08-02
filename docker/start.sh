#!/usr/bin/env sh
set -eu

: "${PORT:=10000}"

is_true() {
    case "${1:-}" in
        1|true|TRUE|yes|YES|on|ON) return 0 ;;
        *) return 1 ;;
    esac
}

case "$PORT" in
    ''|*[!0-9]*)
        echo "PORT must be an integer between 1 and 65535." >&2
        exit 1
        ;;
esac

if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be an integer between 1 and 65535." >&2
    exit 1
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    case "${APP_KEY:-}" in
        ''|base64:GENERATE_WITH_*)
            echo "A generated APP_KEY is required in production." >&2
            exit 1
            ;;
    esac

    if is_true "${APP_DEBUG:-false}"; then
        echo "APP_DEBUG=true is forbidden in production." >&2
        exit 1
    fi

    if is_true "${RUN_DEMO_SEEDER:-false}" || is_true "${RUN_DEMO1_RICH_SEEDER:-false}"; then
        echo "Demo seeders are forbidden when APP_ENV=production." >&2
        exit 1
    fi
fi

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
    php artisan storage:link --no-interaction
fi

php artisan config:clear --no-interaction
php artisan route:clear --no-interaction
php artisan view:clear --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

if is_true "${RUN_MIGRATIONS:-false}"; then
    echo "RUN_MIGRATIONS is deprecated. Use RUN_SCHEMA_MIGRATIONS=true for small, reviewed schema migrations only." >&2
fi

# This switch runs only Laravel schema migrations. Stage 1 legacy data
# transformations are Artisan commands and are deliberately never invoked at
# application boot; run them as an explicit, monitored deployment operation.
if is_true "${RUN_SCHEMA_MIGRATIONS:-false}"; then
    echo "RUN_SCHEMA_MIGRATIONS=true; running reviewed Laravel schema migrations..."
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

    echo "Laravel schema migrations completed."
fi

if is_true "${RUN_DEMO_SEEDER:-false}"; then
    DEMO_SEEDER_CLASS="${DEMO_SEEDER_CLASS:-CurrentVersionDemoSeeder}"
    echo "RUN_DEMO_SEEDER=true; running demo seeder [${DEMO_SEEDER_CLASS}]..."
    php artisan db:seed --class="${DEMO_SEEDER_CLASS}" --force --no-interaction
    echo "Demo seeder [${DEMO_SEEDER_CLASS}] completed."
fi

if is_true "${RUN_DEMO1_RICH_SEEDER:-false}"; then
    echo "RUN_DEMO1_RICH_SEEDER=true; enriching the existing demo1 dataset..."
    php artisan db:seed --class="Demo1RichDataSeeder" --force --no-interaction
    echo "Demo1 rich data seeding completed."
fi

envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

php-fpm -t
nginx -t

php-fpm -F &
php_fpm_pid=$!
nginx -g 'daemon off;' &
nginx_pid=$!

shutdown() {
    trap - TERM INT
    kill -TERM "$php_fpm_pid" "$nginx_pid" 2>/dev/null || true
    wait "$php_fpm_pid" 2>/dev/null || true
    wait "$nginx_pid" 2>/dev/null || true
}

trap 'shutdown; exit 0' TERM INT

# Keep PID 1 responsible for both services. If either process exits, stop the
# other and fail the container so the platform can replace the unhealthy unit.
while kill -0 "$php_fpm_pid" 2>/dev/null && kill -0 "$nginx_pid" 2>/dev/null; do
    sleep 1
done

echo "nginx or PHP-FPM exited unexpectedly; stopping the container." >&2
shutdown
exit 1
