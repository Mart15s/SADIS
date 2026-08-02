# Yava Stage 1 internal verification record

- Date: 2026-08-02
- Operator: Codex lead agent
- Requested baseline: `9b83f7b57fb619dc85edc6fdcfb06617ae60bde0`
- Actual local starting HEAD: `030b158`
- Interrupted-work preservation commit: `43a5761`
- Branch: `yava`

This record covers the final regression run against the source that is included
in the Stage 1 release commit. The release commit identifier is reported in the
handoff after the commit and push complete.

## Automated results

### Backend

- `php artisan test`: 277 tests, 2,037 assertions, all passed on SQLite.
- `php vendor/bin/phpunit --configuration phpunit.pgsql.xml`: 277 tests, 2,037
  assertions, all passed on disposable PostgreSQL.
- `php vendor/bin/pint --test`: 307 files passed.
- `composer validate --strict`: valid.
- `composer audit --locked`: no security vulnerability advisories found.
- `php artisan migrate:fresh --force` completed against clean PostgreSQL, then
  `php artisan yava:stage1-demo` completed successfully.

### Frontend

- `npm test`: 46 test files, 253 tests, all passed.
- `npm run lint`: passed.
- `npm run format:check`: passed.
- `npm run english:scan`: 116 source checks passed.
- `npm run build`: 136 modules transformed; production build passed (247.21 kB
  CSS and 412.79 kB JavaScript before compression).
- `npm audit --omit=dev`: ran and returned exit 1 for two high-severity package
  entries associated with the single React Router RSC advisory
  `GHSA-qwww-vcr4-c8h2`. Yava is a client-only `BrowserRouter` SPA and has no
  RSC, SSR, server loader, or server-action path. The available forced fix is a
  breaking downgrade, so it was not applied. The audit is not clean.

## Container verification

The production image was rebuilt from the final source as
`yava-stage1:stage1-final-20260802`.

- Image digest: `sha256:1fe78c42c56918e80ac40f0b7372079199a84b6430f53d8e6efbe7487e1962db`.
- A new container using the image and PostgreSQL became healthy.
- `/up` and `/` returned HTTP 200; unauthenticated `/api/me` returned HTTP 401.
- Clean PostgreSQL migration and deterministic demo seeding completed before
  the runtime acceptance flow.

## Browser, authorization, privacy, and workflow acceptance

The browser workflow used the deterministic demo accounts and the rebuilt
container at `http://127.0.0.1:10000`.

- A guest deep link to `/fields` redirected to
  `/login?redirect=%2Ffields`; Farm Owner login restored `/fields`.
- Stateful-cookie logout returned to sign-in, and subsequent logins worked.
- Farm and Community contexts switched without a reload and loaded the correct
  scoped data.
- A four-point Field boundary and optional Zone saved and survived reload.
- A Crop Condition was recorded; the season count updated immediately.
- A Task was created, related to a Field, and completed; state updated without
  a manual refresh.
- An Inventory receipt was recorded; available stock changed from 25 to 27
  litres and the movement appeared immediately.
- The Farm membership screen rendered `FarmCommunityLinksPanel`, its scoped
  analytics/permissions, and active-link management.
- Farm Analytics displayed area, seasons, tasks, harvest-by-unit, and planning
  history. Community Analytics displayed only explicitly shared aggregates.
- Community Admin membership, invitation, join-request, and Farm-link controls
  rendered with privacy-safe member representations for non-managers.
- Community Admin approved a valid back-to-back reservation. Approval of the
  overlapping pending reservation was rejected and the record remained pending.
- A restricted viewer saw no create/edit/delete controls, received the explicit
  no-permission Field Editor state on a direct URL, and saw member names/statuses
  without email addresses or management controls. Direct identifier and API
  authorization failures are also covered by the backend regression suite.
- Reloaded views retained the saved Field, Crop Condition, Task, Inventory, and
  reservation state in PostgreSQL.

## Responsive verification

The final image was checked at 360, 390, 412, 768, and 1440 px widths.

- Login was checked at every width: no document overflow, both fields present,
  and the sign-in action remained in the viewport.
- A 65-page/viewport sweep covered navigation, onboarding, Farm and Community
  lists/dashboards, Fields, Field Editor, Crop Seasons, Tasks, Calendar,
  Inventory, Reservations, Members, and Analytics.
- Every checked route rendered its main landmark and page heading without
  document-width overflow.
- The Field Editor keeps its primary save/cancel actions visible on mobile. Its
  map tool row is a deliberate touch-scrollable toolbar, while the details sheet
  remains off-canvas until opened; neither expands the document width.

These checks are targeted acceptance checks, not certification against every
WCAG success criterion or an external assistive-technology audit.

## Genuine remaining limitations

1. Monitor React Router for a compatible release that fixes
   `GHSA-qwww-vcr4-c8h2`, then upgrade and repeat the routing/browser matrix. Do
   not report npm audit as clean before that.
2. Production TLS termination, deployed environment values, real provider
   credentials, backup/restore ownership, and post-deploy observation require a
   deployment operator and were not exercised by this local run.
3. Real SMS OTP delivery is intentionally unconfigured in Stage 1. The safe
   development OTP is local-only; external weather/geocoding availability
   remains degradable by design.
4. Drone imagery, WebODM, and NDVI remain Stage 2 work.
