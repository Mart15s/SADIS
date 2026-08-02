# Security operations

## Authentication and authorization

Laravel Sanctum protects authenticated API routes. Same-origin HttpOnly cookie authentication is the default, with `AUTH_EMIT_LEGACY_TOKEN=false`. Farm and community authorization must be enforced server-side through policies, middleware, and permission services; React visibility is not an authorization boundary. Community administration never implies farm administration. Account deletion must not cascade-delete domain records, and a sole farm owner must transfer ownership or remain archived.

The bearer-token response is a compatibility switch only. If `AUTH_EMIT_LEGACY_TOKEN=true` is temporarily required by an older client, browser storage remains an XSS credential-exposure risk. Time-box the flag, never emit tokens to logs or error reporting, minimize token lifetime/scope, and remove the compatibility client after telemetry confirms cookie-authenticated use.

## Browser and transport controls

The production nginx template sets CSP, clickjacking protection, MIME sniffing protection, a strict referrer policy, HSTS, Permissions Policy, and cross-origin isolation headers compatible with the SPA. CSP permits same-origin application resources, the checked-in Google Fonts stylesheet/font origins, and OpenStreetMap tiles; weather, crop-care, and geocoding calls are server-side and therefore need no browser CSP grant. Only `/index.php` can execute PHP through nginx, and the container validates both service configurations before accepting traffic. Add any new external origin deliberately and narrowly; do not add `*` or `unsafe-eval`.

TLS terminates at the hosting platform. `Strict-Transport-Security` is ignored by browsers over plain HTTP, but production `APP_URL` and all user traffic must use HTTPS.

## Proxy, CORS, and rate-limit checklist

- Configure `TRUSTED_PROXIES` from authoritative platform ingress ranges. The Render inventory uses Laravel's `REMOTE_ADDR` sentinel so only the immediate managed edge hop is trusted. Do not use `*` where the application port is directly reachable.
- Set `CORS_ALLOWED_ORIGINS` to exact origins including scheme. Set `SANCTUM_STATEFUL_DOMAINS` to hostnames (and non-default ports where applicable) without schemes. Wildcards are forbidden with credentialed cookies.
- Keep `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and `SESSION_SAME_SITE=lax` for the supplied same-origin deployment. The production image uses encrypted cookie sessions; do not place sensitive or large application payloads in the session.
- Rate-limit registration, login, password reset, OTP send/verify, invitation acceptance, join requests, and expensive exports.
- Ensure rate-limit keys combine normalized identity and source address without storing raw secrets.
- Return generic authentication/OTP responses where account enumeration is possible.

## Dependency policy

Run both audits on every release:

```powershell
cd backend
composer audit --locked

cd ../frontend
npm audit
```

Laravel, Sanctum, Dompdf, Guzzle, and Symfony packages are kept within compatible supported release lines. `react-router-dom` is pinned to 7.18.2. `npm audit --omit=dev` reports two high-severity entries for GHSA-qwww-vcr4-c8h2 because the affected `react-router` range is 7.12.0 through 8.2.0. The issue is an RSC server-action CSRF path; this application is a client-only `BrowserRouter` SPA with no RSC, SSR, server loader, or server action path. No patched current v7 release is published. A trial downgrade to 7.11.0 reintroduced several older browser/SSR advisories, so it was rejected. Reassess each Router release and upgrade to a fixed compatible release after dedicated routing tests; do not claim the current audit is clean.

## Secrets and sensitive data

- Use the platform secret store and least-privilege database/API credentials.
- Never commit `.env`, database dumps, runtime logs, tokens, passwords, OTPs, or production exports.
- The production access-log format omits query strings and referrers and redacts invitation codes in canonical acceptance paths. Do not replace it with nginx's combined format or add request bodies/authorization headers to logs.
- Hash OTP values at rest and enforce expiry, cooldown, attempt limits, and audit events.
- Do not put private farm details into community queries and then filter them in the browser.
- Treat task notes, detailed harvests, condition history, inventory quantities, and user activity as denied-by-default community analytics.

Run a tracked-files scan before release and inspect every match rather than relying on filenames alone:

```bash
git ls-files | rg '(^|/)(\.env$|.*\.(sql|dump)$|node_modules|vendor|dist|build)(/|$)'
```

## Incident response

Revoke affected credentials, invalidate sessions/tokens, preserve logs without secrets, snapshot the database, deploy the patched image, and review audit trails. If integrity is uncertain, follow the snapshot restoration procedure in `OPERATIONS.md`.
