# Known limitations and Stage 2 exclusions

This file records deliberate Stage 1 boundaries. It is not a waiver for a failing acceptance check.

## Stage 1 boundaries

- Drone ingestion, imagery processing, and WebODM integration are Stage 2 work.
- Legacy SADiS tables and routes remain during the compatibility period. Their removal is gated by the API transition matrix and migration evidence; they must not leak obsolete branding or untranslated copy into primary Yava screens.
- Ambiguous legacy Plant markers are classified and reported, not guessed into Crop Seasons.
- No production SMS provider is bundled. Password authentication remains available; production OTP must remain `unconfigured` until an `OtpProvider` implementation and credentials are deployed and tested.
- External weather, crop-care, and reverse-geocoding services may be unavailable. The application must expose degradation without treating external data as authoritative.
- The dependency audit retains GHSA-qwww-vcr4-c8h2 through React Router 7.18.2. Its vulnerable RSC server-action mode is not present in this BrowserRouter-only SPA; `SECURITY.md` records the rejected downgrade and upgrade gate.

## Deployment constraints

- The supplied Render blueprint is a single web service. It uses encrypted cookie sessions, a local file cache, and synchronous queues. Horizontal scaling requires a shared cache/queue design and a dedicated regression test before rollout.
- The container filesystem is ephemeral. Do not use `FILESYSTEM_DISK=local` for durable user uploads. Stage 1 stores image references as URLs; add managed object storage before accepting uploaded binaries.
- Schema migration is an explicit deployment operation. `RUN_SCHEMA_MIGRATIONS` is an escape hatch for small reviewed changes, not a replacement for a pre-deploy job. Legacy conversion never runs at container boot.
- The included PostgreSQL Compose service is disposable, binds only to localhost, and destroys its `tmpfs` data when stopped.

## Compatibility retirement

Cookie-authenticated `/api/v1` endpoints are canonical. Legacy bearer-token emission is disabled by default, but the old endpoint surface remains for verified legacy consumers. Do not remove compatibility behavior until runtime consumers, mapping coverage, authorization parity, and rollback have been verified.
