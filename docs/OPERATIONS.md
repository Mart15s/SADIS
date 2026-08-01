# Yava Stage 1 operations and deployment

## Deployment invariants

- Back up and verify the PostgreSQL database before applying Stage 1 schema changes.
- Deploy from a reviewed commit, with `APP_DEBUG=false` and a generated `APP_KEY`.
- Run normal schema migrations as an explicit pre-deploy operation.
- Never invoke legacy data transformation from the web container startup path.
- Keep the previous image tag and database backup until acceptance checks finish.
- Never enable demo seeders against a production database.

`RUN_SCHEMA_MIGRATIONS` defaults to `false`. It exists for platforms that cannot run a pre-deploy command and runs only `php artisan migrate --force`. The deprecated `RUN_MIGRATIONS` variable no longer triggers any migration. Legacy conversion is always a separate Artisan command.

## Local startup

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

```powershell
cd frontend
npm ci
npm run dev
```

## Release procedure

1. Record the current application image/commit and migration batch.
2. Put the application into a maintenance window if migration rehearsal indicates lock or runtime risk.
3. Create a provider-native database snapshot and verify its completion.
4. Build the Docker image from the reviewed commit.
5. Run `php artisan migrate --force` as a one-off operation.
6. Run the legacy migration dry-run and save its count/orphan report.
7. Run the resumable conversion explicitly, then rerun the report.
8. Deploy the application image with `RUN_SCHEMA_MIGRATIONS=false`.
9. Verify `/up`, login, context switching, field save, task mutation, inventory movement, reservation approval, and dashboards.
10. Retain the backup and old image for the agreed observation period.

Do not combine step 5 or 7 with container boot. If a release platform lacks one-off jobs, run them from an authenticated administrative shell attached to the exact release image.

## Health and smoke checks

```text
GET /up
GET /
GET /api/user             (authenticated)
```

Check response security headers, application logs, database connection saturation, 4xx/5xx rates, queue failures, mail delivery, and external weather/geocoding degradation. Never log bearer tokens, OTP values, passwords, or full provider credentials.

## Demo data

Confirm the database name, then create deterministic Stage 1 demo data only in a local or disposable database:

```powershell
cd backend
php artisan yava:stage1-demo
```

The command delegates to `Database\Seeders\YavaStageOneDemoSeeder`. `RUN_DEMO_SEEDER` and `RUN_DEMO1_RICH_SEEDER` remain `false` in production.

## Restoration and rollback

Application rollback and database restoration are different operations:

1. Stop new writes or enable maintenance mode.
2. Capture logs and a final database snapshot for incident analysis.
3. If only application code failed and the new schema is additive, redeploy the previous image; do not run `migrate:rollback` automatically.
4. If data conversion produced invalid writes, restore the verified pre-release database snapshot. This discards all writes after that snapshot, so owner authorization is required.
5. Restore the previous environment configuration and image.
6. Run legacy smoke tests before reopening traffic.

Stage 1 retains legacy tables and columns. Laravel `migrate:rollback --step=N` may remove additive schema, but it is not the production recovery default because later writes may depend on it. Prefer an application rollback for compatible additive schema or a full verified snapshot restore for data corruption.

## PostgreSQL rehearsal

```powershell
docker compose -f docker/compose.postgres-test.yml up -d --wait
cd backend
php artisan test --configuration phpunit.pgsql.xml
cd ..
docker compose -f docker/compose.postgres-test.yml down
```

The database uses a `tmpfs`; stopping the service destroys the disposable test data by design.
