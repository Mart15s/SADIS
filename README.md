# Yava

Yava is a farm and community operations platform for fields, crop seasons, tasks, inventory, shared equipment, reservations, weather-aware recommendations, and privacy-safe analytics. Stage 1 preserves the legacy SADiS garden data and API transition surface while introducing the new domain additively. Drone and WebODM functionality are outside Stage 1.

## Technology

- PHP 8.3, Laravel 13, Eloquent, Laravel Sanctum
- React 19, React Router, Vite
- PostgreSQL in production and for database acceptance tests
- Docker, nginx, and PHP-FPM deployment image
- Dompdf document export

## Local development

Prerequisites: PHP 8.3 with Composer, Node.js 22 with npm, and PostgreSQL. Docker Desktop can provide the disposable test database.

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

In a second terminal:

```powershell
cd frontend
npm ci
npm run dev
```

The default frontend URL is `http://localhost:5173`; the Laravel URL is normally `http://127.0.0.1:8000`. Never commit `.env` files, credentials, database dumps, logs, dependency directories, or generated builds.

## Quality checks

```powershell
cd backend
php artisan test
composer audit --locked
vendor/bin/pint --test

cd ../frontend
npm test
npm run lint
npm run build
npm audit
```

For a full PostgreSQL rehearsal:

```powershell
docker compose -f docker/compose.postgres-test.yml up -d --wait
cd backend
php artisan test --configuration phpunit.pgsql.xml
cd ..
docker compose -f docker/compose.postgres-test.yml down
```

The PostgreSQL service binds only to `127.0.0.1:55432`, stores its data in temporary memory, and uses test-only credentials.

Create Stage 1 demo data only in a local/disposable database:

```powershell
cd backend
php artisan yava:stage1-demo
```

## Safe migration and deployment

Schema migration and legacy data transformation are deliberately separate. Application boot does not transform legacy records. Review [operations and deployment](docs/OPERATIONS.md) and [legacy migration](docs/LEGACY_MIGRATION.md) before a release.

```powershell
cd backend
php artisan migrate --force
php artisan yava:stage1-migrate
php artisan yava:stage1-migrate --execute --chunk=250
php artisan yava:stage1-report
```

Additional references:

- [Environment variables](docs/ENVIRONMENT.md)
- [Security model and operations](docs/SECURITY.md)
- [API transition matrix](docs/API_TRANSITION_MATRIX.md)
- [Acceptance checklist](docs/ACCEPTANCE_CHECKLIST.md)
