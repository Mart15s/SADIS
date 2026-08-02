# Yava backend

The Yava backend is a Laravel REST API backed by Eloquent, PostgreSQL in production, and Laravel Sanctum for cookie-first authentication. The additive `/api/v1` API contains the Stage 1 Community, Farm, Field, Crop, task, inventory, resource, reservation, analytics, OTP, onboarding, and legacy-migration workflows. Compatibility routes for the original garden domain remain available while migration is in progress.

## Local setup

Requirements: PHP 8.3 or later, Composer 2, and the PHP extensions required by `composer.json`.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

The frontend development origin must be included in `SANCTUM_STATEFUL_DOMAINS`, and `FRONTEND_URL`/CORS settings must describe the same origin. Keep `AUTH_EMIT_LEGACY_TOKEN=false` for cookie-first clients. The development OTP provider is safe only in local/test environments; configure a production provider before deploying with `APP_ENV=production`.

## Verification

The default test configuration uses an in-memory SQLite database:

```bash
php artisan test
vendor/bin/pint --test
php artisan route:list
php artisan config:cache
php artisan route:cache
```

For the PostgreSQL compatibility gate, create a disposable database using the values in `.env.testing.pgsql.example`, then run PHPUnit directly so the configuration file is supplied exactly once:

```bash
cp .env.testing.pgsql.example .env.testing.pgsql
vendor/bin/phpunit -c phpunit.pgsql.xml
```

Never point the PostgreSQL test configuration at development or production data. `RefreshDatabase` drops and recreates application tables.

## Stage 1 data and legacy migration

Create deterministic demonstration data explicitly:

```bash
php artisan yava:stage1-demo
```

Preview migration effects without writing to any database table:

```bash
php artisan yava:stage1-migrate
```

The dry run prints a human-readable entity summary followed by structured JSON. Execute or resume a write run only after reviewing it:

```bash
php artisan yava:stage1-migrate --execute
php artisan yava:stage1-migrate --execute --run=<run-uuid>
php artisan yava:stage1-report <run-uuid>
```

Migration execution is chunked, resumable, mapping-backed, and idempotent. Completed mappings are terminal; ambiguous and historical records are retained as legacy history.

## Useful maintenance commands

```bash
php artisan plant-care:repair-shared-links
php artisan inventory:repair-calendar-resources
php artisan weather:repair-forecasts --dry-run
php artisan optimize:clear
```

Do not commit `.env` files, credentials, production database dumps, `vendor/`, or generated caches.
