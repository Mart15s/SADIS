# Yava Stage 1 user testing guide

This guide starts a disposable local PostgreSQL database, loads deterministic Stage 1 data, and runs the Laravel API and React application for manual product testing. Do not use the demo seeder against production or a database containing data you need.

## Prerequisites

- Git
- PHP 8.3 and Composer 2
- Node.js 22 and npm
- Docker Desktop or OrbStack

Install dependencies once from the repository root:

```bash
cd /Users/armanda/Documents/YAVA/backend
composer install
cd ../frontend
npm ci
```

## First-time setup and demo seed

From the repository root, start the disposable PostgreSQL service and create a local environment file only if one does not already exist:

```bash
cd /Users/armanda/Documents/YAVA
docker compose -f docker/compose.postgres-test.yml up -d --wait
test -e backend/.env || cp backend/.env.user-testing.example backend/.env
cd backend
php artisan key:generate
php artisan config:clear
php artisan migrate:fresh --force
php artisan yava:stage1-demo
```

`migrate:fresh` deletes all tables in the configured database. The supplied user-testing environment points to the disposable `yava_test` database on `127.0.0.1:55432`; check `DB_DATABASE` before running it.

Start the API in the first terminal:

```bash
cd /Users/armanda/Documents/YAVA/backend
php artisan serve --host=127.0.0.1 --port=8000
```

Start the frontend in a second terminal:

```bash
cd /Users/armanda/Documents/YAVA/frontend
npm run dev -- --host 127.0.0.1
```

Open `http://127.0.0.1:5173`. Use the same hostname (`127.0.0.1`) for the whole session so browser cookies remain consistent.

## Demo accounts

Every account uses the password `YavaDemo!2026`.

| Account | Product role | Recommended checks |
| --- | --- | --- |
| `yava.owner@example.com` | Farm Owner; member of Green Village Cooperative; owns two farms | Full farm workflow, context switching, community links, reservation requests, analytics |
| `yava.admin@example.com` | Farm Admin of Sunrise Organic Farm | Farm member, field, crop, task, inventory, and analytics administration |
| `yava.manager@example.com` | Farm Manager; Community Resource Manager | Farm operations and community reservation/resource controls; no farm member administration |
| `yava.community@example.com` | Community Admin | Member/invitation/join-request management, farm-link decisions, shared inventory/resources, reservation approval |
| `yava.viewer@example.com` | Restricted Farm Viewer; Community Member | Read-only farm/community checks and direct unauthorized API denial |
| `yava.applicant@example.com` | Applicant with a pending community join request | Join-request state and limited pre-membership access |

Local OTP testing is deliberately deterministic: request an OTP for one of the seeded phone numbers and enter `246810`. Never configure the development OTP provider or code in production.

The pending invitation for `invited.grower@example.com` uses the one-time demo code `YAVA-DEMO-INVITE-2026`. Register that email in a private window before accepting it; the code is stored only as a hash.

## Seeded scenarios

The demo contains Green Village Cooperative; Sunrise Organic Farm and Riverside Market Garden; active and pending farm-community links; invitations and a pending join request; fields with GeoJSON boundaries; an optional zone; a whole-field historical season; crop variety, current/historical seasons, condition and harvest records; planning and rotation history; pending/completed tasks; farm/community stock and receipt movements; a weather-aware recommendation; shared machinery; one approved reservation; one valid back-to-back pending request; and one conflicting pending request.

## Recommended manual flow

1. While signed out, open `http://127.0.0.1:5173/fields`, sign in as the Farm Owner, and confirm Yava returns to Fields rather than the generic dashboard.
2. Switch between Sunrise Organic Farm, Riverside Market Garden, and Green Village Cooperative. Confirm every list follows the active context.
3. In Sunrise Organic Farm, edit North Field in the Field Editor, save its boundary, add or edit Block A, and reload to confirm both polygons remain.
4. Create a crop season using a whole field or the optional zone. Record a crop condition and harvest; inspect rotation warnings and the dashboard recommendation.
5. Create and complete a task. Create an inventory issue or consumption movement and confirm the balance and movement history update without a page refresh. Attempt an excessive issue and expect validation without negative stock.
6. In the Community context, request a non-overlapping reservation as the Farm Owner. Sign out and sign in as the Community Admin to approve it.
7. As Community Admin, try approving the seeded conflicting request and expect a conflict error. The seeded back-to-back request beginning exactly when the approved booking ends must remain valid.
8. Open Members as Farm Owner and Community Admin. Review or change a Farm-Community link and its explicit analytics scopes. Confirm a Community Admin does not automatically receive private Farm administration.
9. Compare Farm Analytics with Community Analytics. Community Analytics must show only explicitly shared aggregates—not private stock, task notes, harvest notes, member email addresses, or farm activity details.
10. Sign in as the Viewer. Confirm create/edit/delete controls are unavailable. In browser developer tools, issue `fetch('/api/v1/farms/1/members', {credentials: 'include', headers: {Accept: 'application/json'}}).then(async r => [r.status, await r.json()])` and expect an authorization response rather than private member administration data.
11. Sign out, sign back in, reload the browser, and confirm saved data and the restored cookie session behave consistently.

Also test registration, Forgot password, Reset password, onboarding skip/resume, and development OTP from a private/incognito window. Reset links are written to `backend/storage/logs/laravel.log` because local mail uses the log driver.

## Responsive pass

Use browser responsive mode at 360, 390, and 412 px, a tablet width (768 px), and desktop (1440 px). Check login, onboarding, navigation, both dashboards, Fields, Field Editor, Crop seasons, Tasks, Inventory, Reservations, Members, and Analytics. There should be no document-level horizontal overflow, clipped dialogs, hidden save/cancel actions, or map controls covering the primary editor actions.

## Reset and reseed

Confirm the disposable database name, then rebuild it deterministically:

```bash
cd /Users/armanda/Documents/YAVA
grep '^DB_DATABASE=yava_test$' backend/.env
cd backend
php artisan migrate:fresh --force
php artisan yava:stage1-demo
```

Stop and remove the disposable database when finished:

```bash
cd /Users/armanda/Documents/YAVA
docker compose -f docker/compose.postgres-test.yml down
```

## Genuine limitations

- Drone imagery, WebODM, and NDVI are Stage 2 and are intentionally absent.
- Production SMS OTP, real provider credentials, durable file storage, production HTTPS, backup ownership, and deployment observation require operator-managed services.
- External weather and reverse-geocoding providers may be unavailable; Yava should show degraded guidance rather than fail the core workflow.
- The client-only React Router dependency currently retains the documented `GHSA-qwww-vcr4-c8h2` RSC advisory. Yava does not use the affected RSC/server-action path; see `docs/SECURITY.md` for the upgrade gate.

## Reproducible feedback

For each issue, provide the tested commit (`git rev-parse HEAD`), account, active Farm or Community, browser/device width, exact URL, steps, expected result, actual result, screenshot or console/network error, and whether the issue persists after reset/reseed. Never include session cookies, reset codes, personal tokens, or real credentials.
