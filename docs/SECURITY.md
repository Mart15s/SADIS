# Security operations

## Authentication and authorization

Laravel Sanctum protects authenticated API routes. Same-origin HttpOnly cookie authentication is the default, with `AUTH_EMIT_LEGACY_TOKEN=false`. Farm and community authorization must be enforced server-side through policies, middleware, and permission services; React visibility is not an authorization boundary. Community administration never implies farm administration. Account deletion must not cascade-delete domain records, and a sole farm owner must transfer ownership or remain archived.

The bearer-token response is a compatibility switch only. If `AUTH_EMIT_LEGACY_TOKEN=true` is temporarily required by an older client, browser storage remains an XSS credential-exposure risk. Time-box the flag, never emit tokens to logs or error reporting, minimize token lifetime/scope, and remove the compatibility client after telemetry confirms cookie-authenticated use.

## Browser and transport controls

The production nginx template sets CSP, clickjacking protection, MIME sniffing protection, a strict referrer policy, HSTS, Permissions Policy, and cross-origin isolation headers compatible with the SPA. CSP permits same-origin application resources and OpenStreetMap tiles. Add any new external origin deliberately and narrowly; do not add `*` or `unsafe-eval`.

TLS terminates at the hosting platform. `Strict-Transport-Security` is ignored by browsers over plain HTTP, but production `APP_URL` and all user traffic must use HTTPS.

## Proxy, CORS, and rate-limit checklist

- Configure `TRUSTED_PROXIES` from authoritative platform ingress ranges rather than trusting arbitrary forwarded headers.
- Allow exact frontend origins and credentials only when Sanctum cookie authentication needs them.
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

Laravel, Sanctum, Dompdf, Guzzle, and Symfony packages are kept within compatible supported release lines. React Router's remaining RSC-only advisory is documented because the application is a client-only `BrowserRouter` SPA with no RSC, SSR, server loader, or server action path. No fixed `8.3.0` package is published at the time of this review. Reassess on every Router release and upgrade after a dedicated routing compatibility test.

## Secrets and sensitive data

- Use the platform secret store and least-privilege database/API credentials.
- Never commit `.env`, database dumps, runtime logs, tokens, passwords, OTPs, or production exports.
- Hash OTP values at rest and enforce expiry, cooldown, attempt limits, and audit events.
- Do not put private farm details into community queries and then filter them in the browser.
- Treat task notes, detailed harvests, condition history, inventory quantities, and user activity as denied-by-default community analytics.

## Incident response

Revoke affected credentials, invalidate sessions/tokens, preserve logs without secrets, snapshot the database, deploy the patched image, and review audit trails. If integrity is uncertain, follow the snapshot restoration procedure in `OPERATIONS.md`.
