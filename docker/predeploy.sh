#!/usr/bin/env sh
set -eu

# Render exposes its managed connection as DATABASE_URL while current Laravel
# configuration reads DB_URL. Keep the translation local to deployment code.
if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_URL:-}" ]; then
    export DB_URL="$DATABASE_URL"
fi

if [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
    echo "A PostgreSQL DATABASE_URL/DB_URL or DB_HOST configuration is required for pre-deploy migration." >&2
    exit 1
fi

php artisan migrate --force --no-interaction
