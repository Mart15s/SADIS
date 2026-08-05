# Production deployment

## Architecture

The React/Vite frontend is deployed from `frontend/` to Vercel. The Laravel 13 API runs from the repository Dockerfile on Render and connects to managed Render PostgreSQL. This preserves Laravel's PHP runtime, migrations, PDF support, and storage layout without relying on an unofficial Vercel PHP runtime.

Authentication uses Laravel Sanctum personal access tokens. The browser stores the token and sends it in the `Authorization` header. Production requests therefore go directly from Vercel to Render without cross-site session cookies.

## Vercel frontend

- Root directory: `frontend`
- Install command: `npm ci`
- Build command: `npm run build`
- Output directory: `dist`
- Production variable: `VITE_API_BASE_URL=https://<render-service>.onrender.com`

`frontend/vercel.json` preserves static files, returns 404 for accidental same-origin `/api` and `/sanctum` requests, and falls back to `index.html` for React Router paths.

## Render API and PostgreSQL

The root `render.yaml` defines a Docker web service and a managed PostgreSQL database. Configure secrets in Render, never in committed files.

Required production values:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY`
- `APP_URL=https://<render-service>.onrender.com`
- `FRONTEND_URL=https://<vercel-project>.vercel.app`
- `DATABASE_URL` (injected from the managed Render database)
- `DB_CONNECTION=pgsql`
- `CORS_ALLOWED_ORIGINS=https://<vercel-project>.vercel.app`
- `CORS_SUPPORTS_CREDENTIALS=false`
- `SANCTUM_STATEFUL_DOMAINS=<render-service>.onrender.com`
- `SESSION_DOMAIN` unset
- `SESSION_SECURE_COOKIE=true`
- `SESSION_SAME_SITE=lax`
- `TRUSTED_PROXIES=*` (only behind Render's managed proxy)
- `LOG_CHANNEL=stderr`
- `CACHE_STORE=file`
- `QUEUE_CONNECTION=sync`
- `FILESYSTEM_DISK=local`

Optional integrations require `MAIL_*`, `PERENUAL_API_KEY`, `METEO_LT_BASE_URL`, and the Nominatim variables from `backend/.env.example`. Password reset email is unavailable when mail remains on the log driver. No queued worker or scheduler is currently required, and the application does not currently persist user uploads.

## Safe migration and demo setup

For a new empty database only, temporarily set `RUN_MIGRATIONS=true`, deploy once, verify migration success, and set it back to `false`. Never use `migrate:fresh` in production.

The maintained demo dataset is `CurrentVersionDemoSeeder`. To seed a new empty deployment, set a strong secret `DEMO_ACCOUNT_PASSWORD`, temporarily set `RUN_DEMO_SEEDER=true`, deploy once, then immediately return `RUN_DEMO_SEEDER=false`. The password is required at runtime and is not stored in source control. Do not run the seeder against an existing production database unless its deliberate cleanup behavior has been reviewed.

## Validation

```powershell
cd frontend
npm ci
npm run lint
npm test
npm run build

cd ../backend
composer install --no-interaction --prefer-dist
php artisan config:clear
php artisan route:clear
php artisan test
php artisan route:list
php artisan migrate:status
```

## Rollback

1. In Vercel, promote the previously verified production deployment.
2. In Render, roll back the web service to the previous successful deploy/image.
3. Restore the previous environment-variable values if configuration changed.
4. Database migrations are not automatically reversed. Use only a reviewed, migration-specific `php artisan migrate:rollback --step=1 --force` when that migration's `down()` method is known to preserve required data; otherwise restore a managed database backup into a replacement database and repoint `DATABASE_URL`.
