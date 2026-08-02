# Environment variables

Copy `backend/.env.example` for local development or `.env.render.example` for a production inventory. Values shown as placeholders must be supplied through the deployment platform's secret store.

## Required production values

| Variable | Purpose | Production guidance |
| --- | --- | --- |
| `APP_KEY` | Laravel encryption/signing key | Generate once and store as a secret; retain as `APP_PREVIOUS_KEYS` during planned rotation. |
| `APP_URL` | Canonical backend/public URL | HTTPS URL only. |
| `FRONTEND_URL` | Allowed application frontend origin | Exact HTTPS origin; do not use a wildcard. |
| `CORS_ALLOWED_ORIGINS` | Credentialed browser origins | Exact origin(s), comma separated, including scheme; do not use a wildcard. For the supplied same-origin image this equals `APP_URL`. |
| `DATABASE_URL` or `DB_*` | PostgreSQL connection | TLS-capable provider secret; use a least-privilege application role. |
| `DB_SSLMODE` | PostgreSQL TLS policy | Use `require` (or a stronger provider-supported verification mode) in production. |
| `SANCTUM_STATEFUL_DOMAINS` | Cookie-authenticated SPA domains | Hostnames only, comma separated, no wildcard. |
| `TRUSTED_PROXIES` | Reverse proxy IPs/CIDRs | Use provider ranges when available. For the managed Render edge use Laravel's `REMOTE_ADDR` sentinel; avoid `*` unless the network boundary is verified. |
| `MAIL_*` | Password reset and invitation email | Use a real authenticated provider or explicitly use `log` only outside production. |

## Runtime safety

| Variable | Default | Notes |
| --- | --- | --- |
| `APP_ENV` | `production` | Use `local` or `testing` only in those environments. |
| `APP_DEBUG` | `false` | Must remain false in production. |
| `APP_LOCALE` | `en` | English is the Stage 1 default. |
| `APP_FALLBACK_LOCALE` | `en` | Must be a shipped locale. |
| `APP_FAKER_LOCALE` | `en_IN` | India-oriented demo data. |
| `RUN_SCHEMA_MIGRATIONS` | `false` | Enables small reviewed Laravel schema migrations at boot only where a pre-deploy job is impossible. Render uses `render-predeploy` instead. |
| `RUN_DEMO_SEEDER` | `false` | Never enable in production. |
| `RUN_DEMO1_RICH_SEEDER` | `false` | Legacy demo enrichment; never enable in production. |
| `AUTH_EMIT_LEGACY_TOKEN` | `false` | Cookie-first Sanctum is authoritative. Enable bearer-token emission only for a time-bounded legacy-client transition. |
| `SESSION_DRIVER` | `cookie` in the image | Avoids an undeclared sessions table and an ephemeral server-side session directory. Keep session payloads small. |
| `CACHE_STORE` | `file` in the image | Suitable only for one web instance; use a shared store before horizontal scaling. |
| `QUEUE_CONNECTION` | `sync` in the image | Stage 1 executes jobs inline. Add a worker and durable backend before selecting an asynchronous driver. |
| `FILESYSTEM_DISK` | `local` | Container-local files are ephemeral; configure managed object storage before accepting durable uploads. |

## Integrations

| Variable | Purpose |
| --- | --- |
| `PERENUAL_API_KEY`, `PERENUAL_BASE_URL` | Optional crop-care source. |
| `METEO_LT_BASE_URL`, `METEO_LT_FORECAST_TTL_MINUTES` | Existing weather adapter configuration. |
| `NOMINATIM_BASE_URL`, `NOMINATIM_USER_AGENT` | Reverse geocoding; set a descriptive user agent with an operational contact. |

## OTP

| Variable | Purpose |
| --- | --- |
| `OTP_PROVIDER` | `development` locally; use `unconfigured` in production until a real provider implementation is installed. |
| `OTP_EXPIRES_SECONDS` | Challenge lifetime; default 300. |
| `OTP_RESEND_COOLDOWN_SECONDS` | Minimum delay before resend; default 60. |
| `OTP_MAX_ATTEMPTS` | Verification attempts before lockout; default 5. |
| `OTP_DEVELOPMENT_CODE` | Optional deterministic local/test code; never set in production. |

No production SMS provider or credentials are bundled. The `development` provider refuses to send in production, and any unknown provider fails with a clear configuration error when OTP is used. A production integration must implement the `OtpProvider` contract, register it through configuration, store credentials in the platform secret store, handle provider errors without logging OTPs, and pass send/verify/rate-limit tests.

## Session and cookies

For same-origin production deployment, set `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and `SESSION_SAME_SITE=lax`. The supplied container uses encrypted cookie sessions because no sessions-table migration is included and Render's filesystem is ephemeral. The SPA uses Sanctum cookie authentication first; `AUTH_EMIT_LEGACY_TOKEN=false` prevents new browser sessions from receiving a compatibility bearer token. If frontend and API use distinct sites, perform a dedicated Sanctum/CORS review before selecting `SameSite=None`; it also requires `Secure` and CSRF protection.

The image refuses to start in production with an empty/example `APP_KEY`, debug mode enabled, an invalid `PORT`, or either demo-seeder flag enabled. Use the documented lower-case `true` and `false` values for clarity.

Do not commit real `.env` files. Repository ignore rules also exclude dumps, logs, `vendor`, `node_modules`, coverage, and generated build output.
