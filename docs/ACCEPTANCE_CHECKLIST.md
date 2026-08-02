# Yava Stage 1 acceptance checklist

Record the tested commit, date, browser/device, database engine, and operator. A checkbox is evidence only when the scenario was actually executed.

## Automated gates

- [ ] `php artisan test` passes on the default test database.
- [ ] `vendor/bin/phpunit --configuration phpunit.pgsql.xml` passes against disposable PostgreSQL without duplicate-configuration warnings.
- [ ] `vendor/bin/pint --test` passes.
- [ ] `composer audit --locked` has no unresolved applicable advisory.
- [ ] `npm test` passes.
- [ ] `npm run lint` passes.
- [ ] `npm run build` passes.
- [ ] `npm audit` findings are resolved or documented with applicability and upgrade plan.
- [ ] Fresh PostgreSQL migration succeeds.
- [ ] Production Docker image builds from the final lock files and reaches a healthy state.
- [ ] Container smoke checks cover `/up`, `/`, deep-link SPA fallback, unauthenticated API JSON, and nginx/PHP-FPM failure handling.
- [ ] Legacy dry-run, execution rehearsal, rerun/idempotency, count, and orphan reports are captured.
- [ ] Automated source scan finds no hardcoded Lithuanian primary-UI text.

## Identity and onboarding

- [ ] Register, log in, log out, reset password, and restore a session.
- [ ] Email/password continues to work with OTP disabled.
- [ ] OTP normalization, expiry, cooldown, attempt limit, throttling, and hashed storage are tested.
- [ ] Production OTP enabled without a real provider fails clearly and safely.
- [ ] Onboarding resumes after interruption and never creates duplicate memberships/farms.

## Communities, farms, and authorization

- [ ] Create two communities and assign different Community Admins.
- [ ] Invite by supported token/code; submit and approve/reject join requests.
- [ ] One user switches among multiple communities and farms.
- [ ] One farm links to multiple communities without cross-community access leakage.
- [ ] Unlinking a farm preserves the farm and all farm data.
- [ ] Community Admin without explicit farm permission cannot edit fields, seasons, harvest, inventory, private analytics, or farm deletion.
- [ ] Sole farm owner cannot be hard-deleted; ownership transfer/archive flow preserves history.
- [ ] Direct API/URL access is denied consistently, not merely hidden in React.

## Farm operations

- [ ] Create a farm, multiple fields, an optional field zone, global/custom crop, and crop season.
- [ ] Record condition history, harvest, rotation warning, planning history, and scoped tasks.
- [ ] Issue/consume inventory through movement records and verify balances.
- [ ] Create a shared resource; request, approve, reject, cancel, and complete reservations.
- [ ] Approved `[start,end)` reservations cannot overlap transactionally; back-to-back reservations succeed; pending requests may overlap.
- [ ] Weather degradation is visible and recommendations remain traceable.
- [ ] Farm dashboards show authorized current data after mutations without reload.
- [ ] Community dashboards expose only allowed minimum data until explicit analytics scopes are granted and revoke immediately.

## UI, accessibility, and resilience

- [ ] Yava logo, name, palette, and tokens appear across auth, onboarding, navigation, forms, tables, dialogs, dashboards, empty/error/loading states, editor, and mobile navigation.
- [ ] English is the default and locale/date/number/unit formatting is centralized.
- [ ] Keyboard navigation, visible focus, labels, dialog focus/escape, landmarks, and color contrast are checked.
- [ ] At 320, 360, 390, and 412 px, tablet, and desktop there is no full-page horizontal overflow or clipped/overlapping control.
- [ ] Field Editor prioritizes map/canvas and supports boundary/zone create-edit, marker move, save, cancel, error recovery, and valid return navigation.
- [ ] Every mutation prevents duplicate submission, shows pending/success/error state, preserves recoverable input, refreshes relevant state, and keeps the shell mounted.
- [ ] Error-boundary retry can recover or navigate safely; deterministic failures do not cause a blank retry loop.

## Deployment

- [ ] Environment inventory is complete and contains no committed secrets.
- [ ] Security headers are verified over HTTPS.
- [ ] Render health check targets `/up`; same-origin CORS, Sanctum domains, secure cookies, trusted proxies, and PostgreSQL TLS values are set explicitly.
- [ ] Schema migration and legacy data conversion run as separate explicit operations.
- [ ] Previous image, verified database snapshot, restore permissions, and rollback owner are identified.
- [ ] Post-deploy `/up` and critical smoke checks pass.
