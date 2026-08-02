# Yava Stage 1 internal verification record

- Date: 2026-08-02
- Operator: Codex lead agent
- Starting commit: `9b83f7b57fb619dc85edc6fdcfb06617ae60bde0`
- Branch: `yava`

This record covers the final internal Stage 1 regression performed before the
preservation commit. The final commit identifier is the commit containing this
file and is also recorded in the handoff report.

## Automated results

### Backend

- `php artisan test`: 265 tests, 1,898 assertions, all passed on SQLite.
- `vendor/bin/phpunit --configuration phpunit.pgsql.xml`: 265 tests, 1,898
  assertions, all passed on disposable PostgreSQL.
- `vendor/bin/pint --test`: 304 files passed.
- `composer validate --strict`: valid.
- `composer audit --locked`: no security vulnerability advisories found.
- Fresh migration, demo seed, rollback/remigrate, and representative legacy
  migration rehearsals passed on the current Stage 1 migration set.

### Frontend

- `npm test`: 39 test files, 227 tests, all passed.
- `npm run lint`: passed.
- `npm run format:check`: passed.
- `npm run english:scan`: 114 source checks passed.
- `npm run build`: 134 modules transformed; production build passed.
- `npm audit --omit=dev`: two high-severity package entries remain for the
  single React Router RSC advisory `GHSA-qwww-vcr4-c8h2`. Yava is a client-only
  `BrowserRouter` SPA and has no RSC, SSR, server loader, or server-action path.
  No patched compatible v7 release is available; `SECURITY.md` contains the
  applicability assessment and upgrade gate. The npm audit is not clean.

## Container verification

The production image was rebuilt with pulled base-image metadata from the exact
final source tree as `yava-stage1:stage1-final`.

- Image ID: `sha256:9abe21374ce4134262ee13beba2f38e4af6c730ce0e3665919a64683d8dfb5d8`.
- Container became healthy; `/up` and `/` returned 200.
- Deep SPA routes returned the byte-identical application shell.
- Unauthenticated `/api/me` returned 401 JSON behavior.
- `/.env` returned 403, `/vendor/autoload.php` returned 404, and `/index.php`
  executed through PHP rather than exposing source.
- All eight configured response security headers were present, nginx did not
  expose a version, and `X-Powered-By` was absent.
- Trusted CORS was exact and a hostile origin was not reflected.
- Runtime log scans found no authorization, bearer, password, token, or OTP
  markers. Graceful-stop and service supervision smoke checks passed.

## Browser, authorization, privacy, and onboarding

The browser workflow used deterministic Stage 1 demo accounts and a disposable
PostgreSQL database.

- Guest deep links redirect to sign-in and restore the safe requested route
  after owner login. Cookie-session logout succeeds and returns to sign-in.
- Owner navigation and data loading were exercised for onboarding, communities,
  farms, fields, crop seasons, tasks, inventory, shared resources,
  reservations, and analytics.
- Onboarding advanced, saved an interruption, returned home, and resumed at the
  persisted next step.
- Farm/community context switching returned only records for the active scope.
- The farm management screen exposes community link requests, active links,
  scoped link analytics, and revoke controls only to authorized farm managers.
- A farm manager sees member names and roles without member email addresses or
  community-link administration. A Community Admin sees authorized community
  membership tools but cannot administer a farm through direct URLs.
- Community analytics exposed only shared farm count, shared area, crop-season
  count, and permitted harvest aggregate; task and private farm detail did not
  leak.
- Direct unauthorized farm-only routes showed the context/authorization error
  state instead of exposing management UI.
- The seeded GeoJSON field boundary now loads as three editable points and one
  zone. A browser edit saved valid GeoJSON to PostgreSQL, reloaded without an
  alert, and rendered both polygons.

## Responsive and accessibility checks

Community management and the field editor were checked at 320, 360, 390, and
412 px, 768 px tablet, and 1440 px desktop widths. The final field-editor sweep
found no document-width overflow, visible clipped controls, or overlapping
controls. The mobile editor toolbar wraps while preserving 44 px minimum touch
targets.

Runtime semantic checks on the final image found:

- one `main` landmark and one page `h1` at every tested width;
- `lang="en"`, no unlabeled visible interactive controls, no duplicate IDs,
  and no images missing alternative text;
- mobile navigation moves focus into the drawer, traps page scrolling, closes
  with Escape, restores focus to the opener, and restores body scrolling;
- dialog focus/Escape behavior remains covered by the frontend component suite.

These checks are targeted acceptance checks, not a certification against every
WCAG success criterion or an external assistive-technology audit.

## Genuine remaining limitations

1. Monitor React Router for a compatible release that fixes
   `GHSA-qwww-vcr4-c8h2`, then upgrade and repeat the full routing and browser
   matrix. Do not report npm audit as clean before that.
2. Production HTTPS/TLS termination, Render environment values, real provider
   credentials, backup/restore ownership, and post-deploy observation require a
   deployment operator and were not exercised by this local internal run.
3. Real SMS OTP delivery is intentionally unconfigured in Stage 1; the safe
   unconfigured behavior is tested. External weather/geocoding availability
   remains degradable by design.
4. Drone imagery and WebODM integration remain Stage 2 work.
