# Yava Frontend

React single-page application for the Laravel `/api/*` endpoints.

## Local development

```bash
npm ci
npm run dev
```

## Verification

```bash
npm test
npm run lint
npm run format:check
npm run english:scan
npm run build
npm audit
```

The primary Yava routes cover authentication and resumable onboarding; Farm and Community context switching; members, invitations, join requests, and Farm–Community links; Fields and the mobile Field Editor; Crops, varieties, seasons, conditions, harvests, and rotation warnings; Tasks; inventory movements; shared resources and reservations; and privacy-scoped analytics. Legacy garden routes redirect to canonical Stage 1 workspaces.

The production Docker build runs `npm ci` and `npm run build`, then copies the generated assets into Laravel `public/`. Use the API and frontend through one origin unless a reviewed Sanctum/CORS deployment deliberately separates them.
