# Yava Stage 1 operations and deployment

## Deployment invariants

- Back up and verify the PostgreSQL database before applying Stage 1 schema changes.
- Deploy from a reviewed commit, with `APP_DEBUG=false` and a generated `APP_KEY`.
- Run normal schema migrations as an explicit pre-deploy operation.
- Never invoke legacy data transformation from the web container startup path.
- Keep the previous image tag and database backup until acceptance checks finish.
- Never enable demo seeders against a production database.

`RUN_SCHEMA_MIGRATIONS` defaults to `false`. It exists for platforms that cannot run a pre-deploy command and runs only `php artisan migrate --force --no-interaction`. The deprecated `RUN_MIGRATIONS` variable only emits a warning and does not trigger a migration. Legacy conversion is always a separate Artisan command.

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

The checked-in Render blueprint runs `render-predeploy`, which performs only `php artisan migrate --force --no-interaction`; it never runs legacy conversion or demo seeding. Its health check targets `/up`, which exercises nginx, PHP-FPM, and Laravel; probing `/` would validate only the static SPA. Automatic deploys remain off so the snapshot, migration, and evidence gates can be reviewed before each release. Before the first Blueprint deployment, populate every `sync: false` value in the Render dashboard. `CORS_ALLOWED_ORIGINS`, `APP_URL`, and `FRONTEND_URL` are the same exact HTTPS origin for the supplied same-origin image; `SANCTUM_STATEFUL_DOMAINS` is the hostname without a scheme; and `TRUSTED_PROXIES=REMOTE_ADDR` trusts the immediate Render edge hop.

## Health and smoke checks

```text
GET /up
GET /
GET /api/me               (authenticated)
```

`/up` is a liveness endpoint and does not run a database query. Pair it with an authenticated `/api/me` request and a database-backed read for release readiness. Check response security headers, application logs, database connection saturation, 4xx/5xx rates, mail delivery, and external weather/geocoding degradation. Never log bearer tokens, OTP values, passwords, or full provider credentials.

## Local image verification

After all dependency lock files are final, run:

```bash
docker build --pull -t yava-stage1:verify .
docker run --detach --rm --name yava-stage1-verify \
  --publish 127.0.0.1:10000:10000 \
  --env APP_KEY="base64:$(openssl rand -base64 32)" \
  yava-stage1:verify
for attempt in {1..30}; do
  health="$(docker inspect --format '{{.State.Health.Status}}' yava-stage1-verify)"
  [ "$health" = healthy ] && break
  [ "$health" = unhealthy ] && break
  sleep 1
done
test "$health" = healthy
curl --fail --show-error --dump-header - http://127.0.0.1:10000/up
curl --fail --show-error --dump-header - http://127.0.0.1:10000/
curl --fail --show-error http://127.0.0.1:10000/a/deep/spa/route
test "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' http://127.0.0.1:10000/api/me)" = 401
docker logs yava-stage1-verify
docker stop yava-stage1-verify
```

The unauthenticated API request is expected to return HTTP 401. Verify `Content-Security-Policy`, `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy` in the dumped headers. The image validates generated nginx configuration before starting and exits if either nginx or PHP-FPM dies.

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

If an `APP_KEY` rotation is part of recovery, set the old key in `APP_PREVIOUS_KEYS` until cookies and encrypted data have passed the planned rotation window. Do not rotate the key casually during an application rollback.

Stage 1 retains legacy tables and columns. Laravel `migrate:rollback --step=N` may remove additive schema, but it is not the production recovery default because later writes may depend on it. Prefer an application rollback for compatible additive schema or a full verified snapshot restore for data corruption.

## PostgreSQL rehearsal

```powershell
docker compose -f docker/compose.postgres-test.yml up -d --wait
cd backend
vendor/bin/phpunit --configuration phpunit.pgsql.xml
cd ..
docker compose -f docker/compose.postgres-test.yml down
```

The database uses a `tmpfs`; stopping the service destroys the disposable test data by design.
