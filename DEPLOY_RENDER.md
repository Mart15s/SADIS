# Render deployment guide

This project is deployed as one Docker-based Render Web Service:

- Render Web Service: nginx + PHP-FPM + Laravel API
- React SPA: built during Docker build and copied into `backend/public`
- PostgreSQL: Render managed database
- Public access: Render HTTPS URL

The deployment files do not change business logic, API routes, migrations, UI behavior, authorization, or calendar/inventory/plot logic.

## 1. Create PostgreSQL on Render

1. In Render, create a new PostgreSQL database.
2. Keep the database in the same region as the web service.
3. Copy the internal database URL. Use it as `DATABASE_URL` in the web service.

Do not commit database passwords or URLs to Git.

## 2. Create the Web Service

1. Push this repository to GitHub.
2. In Render, create a new Web Service from the GitHub repository.
3. Select Docker runtime.
4. Use:
   - Dockerfile path: `./Dockerfile`
   - Docker build context: `.`
   - Health check path: `/`

The Docker image installs Laravel production dependencies, builds the React SPA with `npm ci && npm run build`, copies the SPA build into Laravel `public`, and starts nginx on Render's `PORT`.

## 3. Environment variables

Set these in the Render dashboard. Use placeholders as examples only:

```env
APP_NAME="SAD System"
APP_ENV=production
APP_KEY=base64:GENERATED_APP_KEY
APP_DEBUG=false
APP_URL=https://your-service-name.onrender.com
TRUSTED_PROXIES=*

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DATABASE_URL=postgresql://USER:PASSWORD@HOST:PORT/DATABASE
RUN_MIGRATIONS=true
RUN_DEMO_SEEDER=false
DEMO_SEEDER_CLASS=CurrentVersionDemoSeeder

SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=your-service-name.onrender.com

CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=SMTP_USERNAME
MAIL_PASSWORD=SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="${APP_NAME}"

PERENUAL_API_KEY=YOUR_PERENUAL_API_KEY
METEO_LT_BASE_URL=https://api.meteo.lt/v1
```

If you do not use `DATABASE_URL`, set Laravel's standard `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` variables instead.

## 4. Generate APP_KEY

Generate the key locally from the backend directory:

```bash
cd backend
php artisan key:generate --show
```

Copy the output into Render as `APP_KEY`.

## 5. Run migrations

When `RUN_MIGRATIONS=true`, the Render container runs pending Laravel migrations on startup with `php artisan migrate --force`. This is recommended for this project because authentication uses Laravel Sanctum and both registration and login require the `personal_access_tokens` table to exist.

If you keep `RUN_MIGRATIONS=false`, run migrations manually after the first successful deploy and after future deploys that include new migrations:

```bash
cd /var/www/html
php artisan migrate --force
```

If registration or login returns HTTP 500 immediately after deployment, check Render logs for missing table errors such as `users`, `profiles`, `garden_owners`, or `personal_access_tokens`, then run the migration command above or enable `RUN_MIGRATIONS=true` and redeploy.

## 6. Load current demo data without Render Shell

Render Shell is not required. The Docker startup script can run the current idempotent demo seeder during redeploy.

For a demo environment, set these Render environment variables:

```env
RUN_MIGRATIONS=true
RUN_DEMO_SEEDER=true
DEMO_SEEDER_CLASS=CurrentVersionDemoSeeder
```

Then trigger a manual redeploy. Render logs should show:

```text
RUN_MIGRATIONS=true; running Laravel migrations...
Laravel migrations completed.
RUN_DEMO_SEEDER=true; running demo seeder [CurrentVersionDemoSeeder]...
Demo seeder [CurrentVersionDemoSeeder] completed.
```

After the seed succeeds, set `RUN_DEMO_SEEDER=false` and redeploy again for normal operation. The seeder is idempotent and cleans only the known demo accounts' owned records before recreating them, but leaving demo seeding enabled on every production restart is not recommended.

Demo seeding is intended for demonstration databases only. Do not run it against a real production database containing real users unless you intentionally want the demo accounts and catalog examples added.

Current demo accounts all use password `password`:

| Account | Email |
| --- | --- |
| Owner | `demo.owner@example.test` |
| Editor | `demo.editor@example.test` |
| Viewer | `demo.viewer@example.test` |
| Neighbor | `demo.neighbor@example.test` |
| Community member | `demo.community@example.test` |

The older `DemoDataSeeder` and `FullFlowDemoAccountSeeder` class names remain as compatibility aliases, but `CurrentVersionDemoSeeder` is the maintained deployment seeder.

## 7. Verify the deployment

1. Open `https://your-service-name.onrender.com`.
2. Confirm the React SPA loads.
3. Register or log in.
4. Confirm authenticated pages call `/api/*` successfully.
5. Check `https://your-service-name.onrender.com/up` for Laravel health status.
6. In Render logs, confirm there are no migration, database, or permission errors.

## 8. Free plan cold starts

On low-cost or free plans, the first request after inactivity can take longer because Render may need to wake the service. Wait for the first request to finish, then refresh once before the demonstration.

## 9. Operational notes

- The container listens on the `PORT` environment variable assigned by Render.
- Render terminates HTTPS before forwarding traffic to the container; `TRUSTED_PROXIES=*` lets Laravel respect forwarded scheme and host headers.
- `DATABASE_URL` is mapped to Laravel's `DB_URL` inside `docker/start.sh` when `DB_URL` is not already set.
- `php artisan config:cache` and `php artisan view:cache` run on startup after Render environment variables are available.
- `php artisan route:cache` is intentionally not used because the project has Closure web routes.
- `php artisan storage:link` is attempted on startup for public storage support.
