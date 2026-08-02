# Yava

Yava is a farm and community operations platform for fields, crop seasons, tasks, inventory, shared equipment, reservations, weather-aware recommendations, and privacy-safe analytics. Stage 1 preserves the legacy SADiS garden data and API transition surface while introducing the new domain additively. Drone and WebODM functionality are outside Stage 1.

## Technology

- PHP 8.3, Laravel 13, Eloquent, Laravel Sanctum
- React 19, React Router, Vite
- PostgreSQL in production and for database acceptance tests
- Docker, nginx, and PHP-FPM deployment image
- Dompdf document export

## Local development

Prerequisites: PHP 8.3 with Composer, Node.js 22 with npm, and PostgreSQL 17. Docker Desktop or OrbStack can provide the disposable test database. From a POSIX shell:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

In a second terminal:

```bash
cd frontend
npm ci
npm run dev
```

The default frontend URL is `http://localhost:5173`; the Laravel URL is normally `http://127.0.0.1:8000`. Never commit `.env` files, credentials, database dumps, logs, dependency directories, or generated builds.

## Quality checks

```bash
cd backend
php artisan test
composer validate --strict
composer audit --locked
vendor/bin/pint --test

cd ../frontend
npm test
npm run lint
npm run format:check
npm run english:scan
npm run build
npm audit
```

For a full PostgreSQL rehearsal:

```bash
docker compose -f docker/compose.postgres-test.yml up -d --wait
cd backend
vendor/bin/phpunit --configuration phpunit.pgsql.xml
cd ..
docker compose -f docker/compose.postgres-test.yml down
```

The PostgreSQL service binds only to `127.0.0.1:55432`, stores its data in temporary memory, and uses test-only credentials.

Create Stage 1 demo data only in a local/disposable database:

```bash
cd backend
php artisan yava:stage1-demo
```

The demo command is destructive only to the selected database in the normal sense that it inserts demo records. Confirm the database name first; never run it against production.

## Deployment image check

The production image contains the compiled SPA, Laravel, nginx, and PHP-FPM. It refuses placeholder keys, debug mode, invalid ports, and production demo seeding. A local smoke run does not require production credentials:

```bash
docker build -t yava-stage1:local .
docker run --detach --rm --name yava-stage1-local \
  --publish 127.0.0.1:10000:10000 \
  --env APP_KEY="base64:$(openssl rand -base64 32)" \
  yava-stage1:local
for attempt in {1..30}; do
  [ "$(docker inspect --format '{{.State.Health.Status}}' yava-stage1-local)" = healthy ] && break
  sleep 1
done
test "$(docker inspect --format '{{.State.Health.Status}}' yava-stage1-local)" = healthy
curl --fail --show-error http://127.0.0.1:10000/up
curl --fail --show-error http://127.0.0.1:10000/
docker stop yava-stage1-local
```

This smoke check does not exercise database-backed APIs. Use the disposable PostgreSQL suite for that. The container never performs legacy conversion at boot; schema migration at boot is also off unless `RUN_SCHEMA_MIGRATIONS=true` is explicitly supplied. The Render Blueprint instead runs the schema-only `render-predeploy` command before deployment.

## Safe migration and deployment

Schema migration and legacy data transformation are deliberately separate. Application boot does not transform legacy records. Review [operations and deployment](docs/OPERATIONS.md) and [legacy migration](docs/LEGACY_MIGRATION.md) before a release.

```bash
cd backend
php artisan migrate --force
php artisan yava:stage1-migrate
php artisan yava:stage1-migrate --execute --chunk=250
php artisan yava:stage1-report
```

Additional references:

- [Stage 1 user testing guide](docs/USER_TESTING_GUIDE.md)
- [Environment variables](docs/ENVIRONMENT.md)
- [Security model and operations](docs/SECURITY.md)
- [Roles, permissions, and data sharing](docs/AUTHORIZATION.md)
- [API transition matrix](docs/API_TRANSITION_MATRIX.md)
- [Acceptance checklist](docs/ACCEPTANCE_CHECKLIST.md)
- [Latest Stage 1 verification record](docs/STAGE_1_VERIFICATION.md)
- [Known limitations and Stage 2 exclusions](docs/KNOWN_LIMITATIONS.md)
