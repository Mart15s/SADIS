# SAD System — Agent Instructions

## CRITICAL: READ BEFORE EVERY TASK

This project MUST strictly follow the specification defined in:

- garden_system_spec.docx (PRIMARY SOURCE OF TRUTH)
- BBP project (Česnauskas M. Bachelor Thesis, 2026)

If ANY conflict occurs:
→ garden_system_spec.docx ALWAYS overrides this file

This file provides implementation guidance ONLY where it does NOT contradict the spec.

---

# 1. SYSTEM OVERVIEW

The system is a web-based Personal Garden Information System designed to:

- manage garden plots, zones, and plants
- generate recommendation-based action calendars
- track plant condition and history
- support multi-user collaboration
- integrate external APIs (weather + plant care)
- maintain planning history and analytics

All functionality MUST align with the BBP-defined use cases and system models.

---

# 2. TECHNOLOGY STACK (MANDATORY)

Backend:
- Laravel (PHP)
- MVC architecture
- REST API

Frontend:
- React (SPA)

Database:
- PostgreSQL (REQUIRED — no SQLite in production)

ORM:
- Laravel Eloquent

Authentication:
- Laravel Sanctum

External APIs:
- Meteo.lt (weather) → https://api.meteo.lt/
- Perenual (plant care) → https://www.perenual.com/docs/plant-open-api
- Email server (password reset)

Other:
- Server-side PDF generation
- Responsive design (desktop + mobile)

---

# 3. ARCHITECTURE

Strict layered architecture:

1. UI Layer (React)
2. Controller Layer (Laravel controllers + services)
3. Data Model Layer (Eloquent models + PostgreSQL)

Communication:
- React ↔ Laravel via REST API (/api/*)

All business logic MUST be implemented server-side.

---

# 4. USER ROLES (STRICT)

Defined roles:

- Guest
  - Can ONLY access:
    - register
    - login

- Garden Owner (role = "owner")
  - Full system usage:
    - plots
    - plants
    - calendar
    - inventory
    - community
    - analytics

- Administrator (role = "admin")
  - All owner capabilities
  - PLUS:
    - manage all users

DO NOT introduce additional roles.

---

# 5. DATABASE RULES (POSTGRESQL ONLY)

- Must use PostgreSQL relational database
- All relationships MUST use foreign keys
- N:M → junction tables
- ACID compliance required

Key constraints:
- users.role ∈ {owner, admin}
- access_rights.role ∈ {viewer, editor}

Important:
- No schema deviation unless explicitly allowed by spec
- All changes via Laravel migrations ONLY

---

# 6. CORE DOMAIN RULES

## Plant Care
- plant_care is a reusable knowledge base
- plants.fk_plant_care_id → plant_care.id (nullable)
- NEVER duplicate care logic manually

## Weather Integration
- MUST use Meteo.lt API (NOT OpenWeatherMap)
- Used in calendar generation
- If API fails → fallback to stored weather_forecasts

## Calendar Generation (CRITICAL)

Algorithm MUST follow spec exactly:

For each date:
- fetch weather
- detect extreme conditions

For each plant:
- identify condition (auto)
- load plant_care
- evaluate intervals:
  - watering
  - fertilizing
  - pest checks
- apply weather rules:
  - skip watering if rain > threshold
  - frost protection
  - heat → extra watering
  - wind → protection

Then:
- generate tasks
- check inventory availability
- save:
  - task_calendar
  - tasks
  - weather_forecasts

:contentReference[oaicite:0]{index=0}

---

# 7. FUNCTIONAL MODULES (MANDATORY)

System MUST implement ALL use cases:

## User Management
- Register
- Login / Logout
- Password reset
- Edit profile
- Admin user management

## Plot Management
- Create / edit / delete plots
- Manage plant zones
- Manage plants
- Rotation tracking
- Planning history (REQUIRED)
- Condition logging + history
- Sharing + access control
- PDF export
- Community sharing
- Harvest tracking + history
- Analytics

## Actions & Maintenance
- Recommendation calendar
- Weather integration (Meteo.lt)
- Plant care integration (Perenual)
- Inventory management
- Used materials tracking
- Automatic plant condition detection

---

# 8. PLANNING HISTORY (CRITICAL)

System MUST support:

- versioning of plot changes
- historical tracking of planning decisions

Rule:
- Every significant modification → snapshot

:contentReference[oaicite:1]{index=1}

---

# 9. ACCESS CONTROL

- Enforced via access_rights table
- Roles:
  - viewer → read only
  - editor → modify

Rules:
- Every plot action MUST validate access
- Admin is global, not plot-level

---

# 10. GEOMETRY HANDLING

- plots.geometry → JSON
- plant_zones.geometry → JSON

Rules:
- Stores visual layout
- Used in:
  - UI rendering
  - PDF export
  - community

Backend:
- MUST NOT interpret geometry
- Only store/retrieve

---

# 11. EXTERNAL INTEGRATIONS

## Meteo.lt
- Fetch forecast
- Used in calendar logic
- Fallback to stored data

## Perenual
- Fetch plant care profiles
- Cache in plant_care

## Email
- Password reset flow

---

# 12. NON-FUNCTIONAL REQUIREMENTS

System MUST satisfy:

- Load time ≤ 3 seconds
- Responsive UI
- Secure (auth required)
- Validation enforced server-side
- Clear user feedback
- Reliable persistence

:contentReference[oaicite:2]{index=2}

---

# 13. IMPLEMENTATION RULES

DO:
- Follow spec exactly
- Use Eloquent relationships
- Keep business logic in backend
- Maintain clean separation of layers

DO NOT:
- Change architecture
- Replace required technologies
- Introduce alternative APIs
- Hardcode logic that belongs to DB or external APIs

---

# 14. VERIFICATION CHECKLIST

After any change:

- php artisan migrate → must pass
- php artisan test → must pass
- npm run build → must pass

Additionally:
- All FKs enforced
- No spec violations
- Weather = Meteo.lt
- DB = PostgreSQL

---

# FINAL RULE

If unsure:
→ DO NOT GUESS
→ CHECK garden_system_spec.docx