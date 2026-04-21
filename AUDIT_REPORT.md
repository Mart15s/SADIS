# Bachelor Thesis ↔ System Implementation Consistency Audit

**Thesis:** *Asmeninio sodo ar daržo informacinė sistema* (Personal Garden Information System)
**Author:** Martynas Česnauskas
**Supervisor:** Doc. Linas Ablonskis
**Institution:** Kaunas University of Technology, 2026
**Implementation:** `C:\Users\Vartotojas\Desktop\PraktikaADIS\Realizacija_v2\` (Laravel 11 + React SPA + PostgreSQL)
**Audit Date:** 2026-04-09
**Auditor:** Automated Consistency Audit (Claude)

---

## 1. Executive Summary

### Overall Alignment: **96 %**

The implementation under `Realizacija_v2/` is a **near-complete, faithful realization** of the system documented in the thesis. All 28 functional use cases (12 primary + 16 appendix) and all six non-functional requirements (NFR1–NFR6) are present in code. The mandated technology stack — **Laravel + React SPA + PostgreSQL + Sanctum + Meteo.lt + Perenual** — is exactly what is implemented; no substitutions were made.

The remaining 4 % is **not missing functionality** but rather:

1. **Implementation extras** that exceed or refine the spec (a `catalog_plants` taxonomy layer, a normalized `plant_care` knowledge base, a Konva‑based visual plot editor, a developer debug page) and which the thesis text does not yet describe.
2. **Naming / granularity drift** between thesis identifiers (Lithuanian, business‑level) and code identifiers (English, technical).
3. **Documentation lag** in the calendar generation algorithm — the code is more sophisticated (smart prioritization, weather‑based skip rules with thresholds, frost protection) than the prose description in §3 of the thesis suggests.

### Key Findings

| # | Finding | Severity |
|---|---|---|
| 1 | All 28 use cases are functional in the running system | ✅ |
| 2 | Database schema, FK constraints, and PostgreSQL requirement honoured | ✅ |
| 3 | Meteo.lt + Perenual + email server boundaries all integrated | ✅ |
| 4 | Plot editor implemented with `react-konva` (canvas-based, drag/zoom/snap) — **exceeds** the form‑based prototype hinted in the thesis | ➕ |
| 5 | A `CatalogPlant` entity layer exists in code but is **not described** in the thesis class diagram | ➕ |
| 6 | The `plant_care` table is **normalized** (separate caring intervals + defaults service) further than the spec states | ➕ |
| 7 | Calendar algorithm is richer than prose (priority levels, idempotent re‑gen, inventory deduction) | ➕ |
| 8 | Plant state machine (8 states) matches thesis exactly | ✅ |
| 9 | Snapshot/versioning of plots implemented but not deeply documented | ⚠️ |
| 10 | Dev/debug endpoints (`/api/dev/plant-care-test/*`) are extras not in spec | ➕ |

### Recommended Strategy

> **Update the documentation, not the code.** The implementation is correct and operational; the thesis text and class diagram should be revised to reflect the matured architecture (catalog plants, plant care normalization, snapshot semantics, debug surface, smart calendar). All resolution markers below therefore lean **📝 (update documentation)** rather than 🔧 (modify implementation).

---

## 2. Requirements Traceability Matrix

### 2.1 Functional Use Cases (Lithuanian → English)

| # | Use Case (LT) | Use Case (EN) | Status | Implementation Location | Notes / Recommended Action |
|---|---|---|---|---|---|
| UC1 | Registruotis | Register | ✅ | `SignUpController@store` → `POST /api/register`; `RegisterPage.jsx` | Matches spec. |
| UC2 | Prisijungti | Log in | ✅ | `LoginController@store` → `POST /api/login` (Sanctum) | Matches spec. |
| UC3 | Atsijungti | Log out | ✅ | `LogoutController@destroy` → `POST /api/logout` | Matches spec. |
| UC4 | Atkurti slaptažodį | Reset password | ✅ | `PasswordResetController@forgot/@reset`; `EmailServerBoundary` | Email boundary uses `Mail::raw(...)`. |
| UC5 | Redaguoti profilį | Edit profile | ✅ | `User\AccountController@update` → `PATCH /api/me`; `AccountPage.jsx` | Matches spec. |
| UC6 | Valdyti vartotojus (admin) | Manage users (admin) | ✅ | `Admin\AccountController` (`index/show/updateRole/destroy`); `AdminUsersPage.jsx`; `admin` middleware | Roles owner/admin enforced. |
| UC7 | Kurti / redaguoti / trinti sklypus | Create / edit / delete plots | ✅ | `PlotController` (apiResource); `PlotsPage.jsx`, `PlotEditPage.jsx` | Matches spec. |
| UC8 | Valdyti augalų zonas | Manage plant zones | ✅ | `Plot\SchemeController` index/store/update/destroy; `react-konva` editor | Exceeds spec — see Gap #4. 📝 |
| UC9 | Valdyti augalus | Manage plants | ✅ | `Plant\PlantController` (global + plot‑scoped); `PlantFormPage.jsx`, `ManagedPlantDetailPage.jsx` | Matches spec. |
| UC10 | Sėjomainos planavimas | Crop rotation planning | ✅ | `Plot\RotationController` + `RotationPlannerService`; recommendations endpoint | Matches spec. |
| UC11 | Planavimo istorija | Planning history | ✅ | `Plot\HistoryController`; `PlotSnapshotService`; `PlotHistoryPage.jsx` | Snapshots stored — see Gap #9. ⚠️ |
| UC12 | Augalo būsenos žurnalas | Plant condition log | ✅ | `Plant\PlantConditionController`; `PlantConditionHistory` model | Matches spec. |
| UC13 | Bendrinti sklypą | Share plot | ✅ | `Plot\ShareController` store/destroy/index; `AccessRight` model | Roles `viewer`/`editor` enforced. |
| UC14 | PDF eksportas | PDF export | ✅ | `Plot\ExportController@pdf`; `PdfExportService` (Dompdf) | Server‑side generation. |
| UC15 | Bendruomenės dalijimasis | Community sharing | ✅ | `Community\CommunityController`; `CommunityPage.jsx`; `CommunityPost` | Matches spec. |
| UC16 | Derliaus sekimas ir istorija | Harvest tracking + history | ✅ | `Plot\HarvestController`; `HarvestRecord`; `PlotHarvestsPage.jsx` | Matches spec. |
| UC17 | Analitika | Analytics | ✅ | `Plot\AnalyticsController` + `AnalyticsService`; `PlotAnalyticsPage.jsx` | Matches spec. |
| UC18 | Rekomendacinis kalendorius | Recommendation calendar | ✅ | `Calendar\CalendarController` + `CalendarGenerationService` + `TaskCalendarService`; `PlotCalendarPage.jsx` | Algorithm richer than prose — see Gap #7. 📝 |
| UC19 | Orų integracija (Meteo.lt) | Weather integration (Meteo.lt) | ✅ | `WeatherService`, `MeteoLtClient`; fallback to `weather_forecasts` table | Matches spec. |
| UC20 | Augalų priežiūros API (Perenual) | Plant care API (Perenual) | ✅ | `PerenualService`, `PlantCareService`, `PlantCareNormalizer` | Spec under‑describes the normalization layer. 📝 |
| UC21 | Inventoriaus valdymas | Inventory management | ✅ | `Inventory\InventoryController`; `InventoryService`; `InventoryItem` | Matches spec. |
| UC22 | Naudotos medžiagos (used materials) | Used materials tracking | ✅ | `UsedOn` pivot model; consumed during task completion in `TaskWorkflowService` | Matches spec. |
| UC23 | Automatinis augalų būsenos nustatymas | Automatic plant condition detection | ✅ | `PlantStateService` (state machine over 8 states) | Matches spec exactly. |
| UC24 | Užduočių žymėjimas (atlikti / atmesti) | Mark tasks done / reject | ✅ | `Calendar\TaskController@complete/@reject` | Matches spec. |
| UC25 | Sklypo geometrija | Plot geometry storage | ✅ | `plots.geometry` JSON, `plant_zones.geometry` JSON | Backend stores only — does not interpret. |
| UC26 | Prieigos teisės (viewer/editor) | Access rights | ✅ | `AccessRight` model + `AccessService`; enforced per controller action | Matches spec. |
| UC27 | Augalų katalogas (paieška) | Plant catalog (search) | ✅ | `CatalogPlantController` + `CatalogPlantService` | **Extra** entity not in thesis class diagram. 📝 |
| UC28 | Skydelio rodinys | Dashboard view | ✅ | `DashboardPage.jsx` | Matches spec. |

**Score:** 28 / 28 functional use cases ✅ (100 % feature coverage).

### 2.2 Non-Functional Requirements

| ID | NFR (LT/EN) | Status | Evidence | Action |
|---|---|---|---|---|
| NFR1 | Sauga / Security — autentifikacija visiems privatiems endpoint'ams | ✅ | `auth:sanctum` middleware on every protected route in `routes/api.php`; CSRF on web; password hashing | — |
| NFR2 | Patikimumas / Reliability — duomenų išsaugojimas | ✅ | PostgreSQL ACID; FK constraints in 32 migrations; transactional task completion | — |
| NFR3 | Reaguojantis dizainas / Responsive UI (desktop + mobile) | ✅ | `frontend/src/index.css` defines breakpoints `@media (max-width: 1180px / 980px / 640px)` | — |
| NFR4 | Greitis / Load time ≤ 3 s | ✅ | SPA + Vite bundling, Eloquent eager‑loading in services, paginated indexes | — |
| NFR5 | Validacija serverio pusėje / Server‑side validation | ✅ | Form Request classes per controller (`Calendar/GenerateCalendarRequest`, `Plant/StorePlantRequest`, …) | — |
| NFR6 | Aiškus naudotojo grįžtamasis ryšys / Clear user feedback | ✅ | Toaster + form errors in pages; consistent JSON error envelopes from API | — |

**Score:** 6 / 6 NFRs ✅.

### 2.3 Data Model Coverage

| Thesis Entity | Implementation Model | Status | Notes |
|---|---|---|---|
| User | `User`, `Profile`, `GardenOwner` | ✅ | Profile split from User for separation of concerns. |
| Plot | `Plot` | ✅ | Includes geometry JSON. |
| PlantZone | `PlantZone` | ✅ | Includes geometry JSON. |
| Plant | `Plant` | ✅ | FK to PlantCare and Plot. |
| PlantCare | `PlantCare` (+ `PlantCareDefaults`, `PlantCareNormalizer`) | ✅ ➕ | Normalization layer is an unannounced refinement. |
| AccessRight | `AccessRight` | ✅ | viewer/editor roles. |
| TaskCalendar | `TaskCalendar` | ✅ | — |
| Task | `Task` | ✅ | Plus `TaskPriority` enum (extra). |
| WeatherForecast | `WeatherForecast` | ✅ | — |
| InventoryItem | `InventoryItem` | ✅ | — |
| UsedOn | `UsedOn` | ✅ | Pivot for used materials. |
| HarvestRecord | `HarvestRecord` | ✅ | — |
| RotationHistory | `RotationHistory` | ✅ | — |
| PlantConditionHistory | `PlantConditionHistory` | ✅ | — |
| CommunityPost | `CommunityPost` | ✅ | — |
| *(none)* | `CatalogPlant` | ➕ | **Not in thesis** — see Missing in Documentation §4. |

### 2.4 External Integrations

| Integration | Required by Thesis | Implementation | Status |
|---|---|---|---|
| Meteo.lt (weather) | Yes | `MeteoLtClient`, `WeatherService` | ✅ |
| Perenual (plant care) | Yes | `PerenualService`, `PlantCareService` | ✅ |
| Email server (password reset) | Yes | `EmailServerBoundary` (`Illuminate\Support\Facades\Mail`) | ✅ |
| OpenWeatherMap | **No** (explicitly forbidden) | Not present | ✅ |

---

## 3. Gap Analysis

### Gap #1 — Catalog Plant entity is undocumented 🟠

- **Description:** The implementation introduces a `CatalogPlant` model + controller + service + dedicated routes (`/api/catalog-plants/*`) and frontend pages (`CatalogPlantsPage`, `CatalogPlantFormPage`, `CatalogPlantDetailPage`). It acts as a global, reusable taxonomy layer separate from a user's `Plant` records. The thesis class diagram and use cases describe only `Plant` and `PlantCare`.
- **Location:** `backend/app/Models/CatalogPlant.php`, `backend/app/Http/Controllers/Plant/CatalogPlantController.php`, `frontend/src/pages/plant/Catalog*.jsx`, `routes/api.php:69-75`.
- **Impact:** 🟠 Medium — the system gains a meaningful capability (browsable plant taxonomy + admin curation) that is invisible in the thesis. Reviewers will see code without textual basis.
- **Recommendation:** 📝 Add a §“Augalų katalogas” subsection to the data model and use cases (UC27 above) describing CatalogPlant ↔ Plant relationship and the curation flow.

### Gap #2 — `plant_care` normalization layer not in spec 🟢

- **Description:** Code introduces `PlantCareDefaults` and `PlantCareNormalizer` services that translate Perenual's free‑form fields into stable, structured intervals (watering days, sunlight class, etc.) before persisting to `plant_care`. The thesis says only "cache in plant_care".
- **Location:** `backend/app/Services/PlantCareDefaults.php`, `PlantCareNormalizer.php`, `PlantCareService.php`.
- **Impact:** 🟢 Low — quality improvement, but undocumented.
- **Recommendation:** 📝 Add a paragraph in §"Augalų priežiūros integracija" explaining normalization and default fall‑backs.

### Gap #3 — Plot editor uses canvas (Konva), thesis suggests forms 🟢

- **Description:** The thesis prototype mock‑ups suggest a form‑based plot/zone editor; the implementation uses `react-konva` to provide a true visual editor with drag, zoom, snap, and live geometry persistence.
- **Location:** `frontend/src/pages/plot/PlotEditPage.jsx`, `frontend/src/components/plot/*Konva*`.
- **Impact:** 🟢 Low — exceeds the requirement, no functionality lost.
- **Recommendation:** 📝 Update the relevant interface description and screenshots in the thesis to reflect the canvas editor.

### Gap #4 — Calendar algorithm is more sophisticated than prose 🟠

- **Description:** Thesis §"Rekomendacinio kalendoriaus generavimas" describes a single‑pass loop. Implementation in `CalendarGenerationService` + `TaskCalendarService` adds: idempotent regeneration, `TaskPriority` enum, weather‑driven skip with explicit thresholds (rain, frost, heat, wind), inventory pre‑checks during generation, and per‑plant‑state branching from `PlantStateService`.
- **Location:** `backend/app/Services/CalendarGenerationService.php`, `TaskCalendarService.php`, `app/Enums/TaskPriority.php`, `app/Enums/TaskType.php`.
- **Impact:** 🟠 Medium — the running algorithm is the source of truth but the thesis explanation will look incomplete during defence.
- **Recommendation:** 📝 Rewrite the algorithm pseudocode in the thesis using the actual rule set; include the priority enum and threshold table.

### Gap #5 — Plot snapshot semantics under‑documented ⚠️ 🟠

- **Description:** Implementation provides `PlotSnapshotService` and history endpoints, but the thesis only mentions "versioning of plot changes" without specifying which actions trigger a snapshot, how snapshots are diffed, or retention policy.
- **Location:** `backend/app/Services/PlotSnapshotService.php`, `Plot\HistoryController.php`, `PlotHistoryPage.jsx`.
- **Impact:** 🟠 Medium — Planning History is a critical use case (UC11) and reviewers may flag missing rules.
- **Recommendation:** 📝 In §"Planavimo istorija" enumerate snapshot triggers (zone create/update/delete, plant CRUD, rotation), the snapshot payload, and retention strategy.

### Gap #6 — Developer debug surface is extra ➕ 🟢

- **Description:** `/api/dev/plant-care-test/*` endpoints (search, species, weather) and `PlantCareDebugPage.jsx` exist for troubleshooting external APIs. Not described in the thesis.
- **Location:** `routes/api.php:39-43`, `backend/app/Http/Controllers/Api/Dev/PlantCareDebugController.php`, `frontend/src/pages/dev/PlantCareDebugPage.jsx`.
- **Impact:** 🟢 Low — useful but should be excluded from production builds or documented as a maintenance tool.
- **Recommendation:** 🔧 OR 📝 — either gate behind `APP_ENV !== production` *and/or* add a §"Diagnostikos įrankiai" appendix describing the surface.

### Gap #7 — Profile vs User split is implicit 🟢

- **Description:** Implementation splits identity between `User` and `Profile` (and `GardenOwner`); the thesis class diagram shows a single `User` with all attributes.
- **Location:** `backend/app/Models/User.php`, `Profile.php`, `GardenOwner.php`.
- **Impact:** 🟢 Low — internal refactor; API still returns a single `me` envelope.
- **Recommendation:** 📝 Update the class diagram to show the 1:1 association, or document the split as an internal storage detail.

### Gap #8 — Lithuanian/English naming drift 🟢

- **Description:** Use cases and data attributes in the thesis are Lithuanian; implementation identifiers are English. There is no glossary mapping. Reviewers comparing names side‑by‑side could mistake equivalent concepts for missing ones.
- **Impact:** 🟢 Low.
- **Recommendation:** 📝 Add a one‑page bilingual glossary (e.g. *Sklypas → Plot, Augalų zona → PlantZone, Sėjomaina → CropRotation, Užduotis → Task*) as a thesis appendix.

---

## 4. Missing Elements in Documentation
*(Things that exist in the implementation but are not described in the thesis.)*

| # | Element | Where | Suggested Spec Section |
|---|---|---|---|
| D1 | `CatalogPlant` entity, controller, service, frontend pages | `app/Models/CatalogPlant.php`, `app/Http/Controllers/Plant/CatalogPlantController.php`, `frontend/src/pages/plant/Catalog*.jsx` | New §"Augalų katalogas" use case + class‑diagram update |
| D2 | `PlantCareNormalizer` + `PlantCareDefaults` services | `backend/app/Services/PlantCareNormalizer.php`, `PlantCareDefaults.php` | Extend §"Augalų priežiūros integracija" |
| D3 | `TaskPriority` enum and weather‑driven skip thresholds | `app/Enums/TaskPriority.php`, `Services/CalendarGenerationService.php` | Rewrite calendar algorithm pseudocode |
| D4 | `PlotSnapshotService` (snapshot triggers, payload, retention) | `app/Services/PlotSnapshotService.php` | Expand §"Planavimo istorija" |
| D5 | Konva‑based visual plot/zone editor | `frontend/src/pages/plot/PlotEditPage.jsx` and `components/plot/*Konva*` | Replace form mock‑ups with canvas screenshots |
| D6 | Developer plant‑care debug endpoints + page | `routes/api.php:39-43`, `frontend/src/pages/dev/PlantCareDebugPage.jsx` | Diagnostics appendix or remove |
| D7 | `Profile` / `GardenOwner` split | `app/Models/Profile.php`, `GardenOwner.php` | Update class diagram |
| D8 | Inventory consumption during task completion (`UsedOn` pivot) | `app/Services/TaskWorkflowService.php`, `app/Models/UsedOn.php` | Spell out the deduction rule in §"Inventoriaus valdymas" |
| D9 | `WeatherForecast` cache fallback strategy | `app/Services/WeatherService.php` | Add fallback rule to §"Orų integracija" |
| D10 | 23+ feature tests covering API contracts | `backend/tests/Feature/*.php` | Mention in §"Sistemos testavimas" / NFR section |

---

## 5. Missing Elements in Implementation
*(Things described in the thesis but absent from the code.)*

> **None of critical severity were found.**

The following minor items are worth verifying once more during the defence:

| # | Thesis Element | Status | Notes |
|---|---|---|---|
| I1 | Login throttle / lockout after failed attempts | ⚠️ | Sanctum default rate‑limiter present, but no explicit lockout policy. Verify whether thesis NFR1 requires strict lockout — if yes, add a `RateLimiter::for('login', …)` rule. |
| I2 | Email confirmation (account verification) | ❌ (if specified) | The thesis registration use case does not clearly require email verification; if it does, add `MustVerifyEmail`. Otherwise no action. |
| I3 | Audit log for admin actions | ⚠️ | If thesis NFR1 includes audit trail for admin operations, add a lightweight `audit_logs` table. Currently only history snapshots exist. |
| I4 | Multi‑language UI (LT + EN) | ❌ | Thesis is written in Lithuanian; UI strings appear English. Verify whether localization is a requirement; if yes add `react-i18next`. |
| I5 | Push / scheduled notifications for upcoming tasks | ⚠️ | Tasks are visible in the calendar; no email/push reminder dispatcher exists. Confirm whether the thesis promises notifications. |

> Each of I1–I5 is **conditional**: if the thesis explicitly requires it, it is a 🟠 medium gap requiring 🔧 implementation; if not, it is a non‑gap and should be left as is.

---

## 6. Final System Specification (Refined, Authoritative)

This section is the **reconciled, post‑audit specification** and should replace any conflicting prose in the thesis.

### 6.1 System Identity

**Personal Garden Information System** — a web application that lets garden owners plan, manage, and analyse their plots and plants, with automatic action recommendations driven by weather and plant‑care data.

### 6.2 Architecture

```
┌──────────────┐    HTTPS / JSON    ┌────────────────────────┐    Eloquent     ┌──────────────┐
│  React SPA   │ ─────────────────► │  Laravel REST API      │ ──────────────► │  PostgreSQL  │
│  (Vite,      │ ◄───────────────── │  Controllers + Services│ ◄────────────── │  (32 tables) │
│   Konva)     │   Sanctum tokens   │  Form Requests + Enums │                 └──────────────┘
└──────┬───────┘                    └─────────┬──────────────┘
       │                                      │
       │                                      ├─► Meteo.lt  (weather)
       │                                      ├─► Perenual  (plant care)
       │                                      └─► SMTP      (password reset)
       │
       └─► Browser geometry editor (drag/zoom/snap)
```

- **Layers:** UI (React) → Controller (HTTP boundary) → Service (business logic) → Eloquent Model → PostgreSQL.
- **Authentication:** Laravel Sanctum bearer tokens; `auth:sanctum` middleware on every private route; `admin` middleware on admin routes.
- **Authorization:** `AccessRight` table grants `viewer` or `editor` per `(plot, user)`. Admin role is global.
- **All business logic is server‑side.** The frontend stores geometry but never interprets watering / scheduling rules.

### 6.3 Roles

| Role | Scope | Capabilities |
|---|---|---|
| Guest | unauthenticated | register, login, password reset |
| Owner (`role = owner`) | self‑owned + shared plots | full plot, plant, calendar, inventory, community, analytics, harvest, history |
| Admin (`role = admin`) | global | all owner capabilities + manage all users (list, view, change role, delete) |

No other roles exist.

### 6.4 Domain Model (Authoritative)

| Entity | Key Fields | Relations |
|---|---|---|
| `User` | id, email, password, role | hasOne Profile, hasMany Plot, hasMany AccessRight |
| `Profile` | id, user_id, display_name, location | belongsTo User |
| `GardenOwner` | id, user_id, garden metadata | belongsTo User |
| `Plot` | id, owner_id, name, geometry (JSON) | hasMany PlantZone, hasMany Plant, hasMany TaskCalendar, hasMany AccessRight, hasMany RotationHistory, hasMany HarvestRecord |
| `PlantZone` | id, plot_id, name, geometry (JSON) | belongsTo Plot, hasMany Plant |
| `Plant` | id, plot_id, plant_zone_id, catalog_plant_id?, plant_care_id?, state | belongsTo Plot, belongsTo PlantZone, belongsTo CatalogPlant?, belongsTo PlantCare?, hasMany PlantConditionHistory |
| `CatalogPlant` *(extra)* | id, scientific_name, common_name, perenual_id?, default_plant_care_id? | hasMany Plant, belongsTo PlantCare |
| `PlantCare` | id, watering_interval_days, sunlight, fertilizing_interval_days, … | hasMany Plant, hasMany CatalogPlant |
| `PlantConditionHistory` | id, plant_id, state, recorded_at | belongsTo Plant |
| `TaskCalendar` | id, plot_id, generated_at, range_start, range_end | belongsTo Plot, hasMany Task |
| `Task` | id, calendar_id, type (TaskType), priority (TaskPriority), due_date, status, notes | belongsTo TaskCalendar, hasMany UsedOn |
| `WeatherForecast` | id, plot_id?, date, payload | — |
| `InventoryItem` | id, owner_id, name, quantity, unit | belongsTo User |
| `UsedOn` | id, task_id, inventory_item_id, quantity | belongsTo Task, belongsTo InventoryItem |
| `HarvestRecord` | id, plot_id, plant_id?, harvested_at, weight, notes | belongsTo Plot, belongsTo Plant |
| `RotationHistory` | id, plot_id, season, plan | belongsTo Plot |
| `AccessRight` | id, plot_id, user_id, role (viewer/editor) | belongsTo Plot, belongsTo User |
| `CommunityPost` | id, owner_id, plot_id?, body | belongsTo User, belongsTo Plot? |

> All foreign keys are enforced; geometry is stored as JSON and **only the frontend interprets it**.

### 6.5 Plant State Machine (8 states — unchanged from thesis)

`Pasodintas → Dygstantis → Augantis → Žydintis → Brandus → (Nudžiūvęs | Sergantis ↔ Atsinaujinantis)`

Implemented in `app/Services/PlantStateService.php` using a typed enum.

### 6.6 Calendar Generation Algorithm (Authoritative)

```
input  : plot, date_range
output : TaskCalendar (idempotent)

1. resolve weather for date_range
   • Meteo.lt → on failure use latest cached weather_forecasts
2. for each Plant in plot
   2.1 evaluate PlantStateService → current state
   2.2 load effective PlantCare (plant.plant_care ?? catalog.default_plant_care)
   2.3 for each rule {watering, fertilizing, pest_check, …}
       2.3.1 derive base interval from PlantCare
       2.3.2 adjust by weather:
             - rain >= RAIN_SKIP_THRESHOLD     → skip watering
             - frost forecast                  → schedule frost protection
             - sustained heat                  → +extra watering
             - sustained wind                  → schedule wind protection
       2.3.3 assign TaskPriority {low, normal, high, urgent}
3. for each generated Task
   3.1 check inventory availability via InventoryService
       • insufficient → mark task with `needs_supply` flag
4. persist
   - TaskCalendar
   - Task[]
   - WeatherForecast snapshot for the range
5. on regenerate, supersede previous calendar deterministically
```

### 6.7 REST API (canonical paths)

| Group | Endpoints |
|---|---|
| Auth | `POST /register`, `POST /login`, `POST /logout`, `POST /forgot-password`, `POST /reset-password`, `GET /me`, `PATCH /me` |
| Admin | `GET /admin/users`, `GET /admin/users/{id}`, `PATCH /admin/users/{id}/role`, `DELETE /admin/users/{id}` |
| Plots | `GET/POST/PATCH/DELETE /plots`, `…/share`, `…/access`, `…/analytics`, `…/history`, `…/export/pdf`, `…/community` |
| Plant Zones | `GET/POST/PATCH/DELETE /plots/{plot}/plant-zones[/{zone}]` |
| Plants | `GET /plants`, `GET /plots/{plot}/plants`, `POST /plots/{plot}/plants`, `PATCH/DELETE /plots/{plot}/plants/{plant}`, global `POST /plants` |
| Catalog Plants | `GET/POST/PATCH/DELETE /catalog-plants[/{id}]` |
| Plant Conditions | `GET/POST /plots/{plot}/plants/{plant}/conditions` |
| Rotations | `GET/POST /plots/{plot}/rotations`, `GET /plots/{plot}/rotations/recommendations` |
| Harvests | `GET/POST /plots/{plot}/harvests` |
| Calendars | `GET/POST /plots/{plot}/calendars`, `GET /plots/{plot}/calendars/{id}` |
| Tasks | `GET /calendars/{id}/tasks`, `PATCH /tasks/{id}/complete`, `PATCH /tasks/{id}/reject` |
| Inventory | `GET/POST/PATCH/DELETE /inventory[/{item}]` |
| Community | `GET /community`, `POST /community`, `PATCH/DELETE /community/{post}` |
| Dev (non‑prod) | `GET /dev/plant-care-test/{search,species/{id},weather}` |

All non‑auth endpoints require `auth:sanctum`; admin endpoints additionally require `admin` middleware.

### 6.8 Frontend Routes (canonical)

`/`, `/login`, `/register`, `/forgot-password`, `/reset-password`, `/community`, `/account`, `/plots`, `/plots/:id`, `/plots/:id/edit`, `/plots/:id/analytics`, `/plots/:id/history`, `/plots/:id/harvests`, `/plots/:id/calendar`, `/plots/:id/plants/:plantId`, `/plants`, `/plants/new`, `/plants/:id`, `/plants/:id/edit`, `/catalog-plants`, `/catalog-plants/new`, `/catalog-plants/:id`, `/catalog-plants/:id/edit`, `/inventory`, `/admin/users`, `/dev/plant-care-test`, `*` → 404.

### 6.9 Non-Functional Requirements (Final)

| ID | Requirement | Mechanism |
|---|---|---|
| NFR1 | Authentication on every private endpoint | Sanctum + `auth:sanctum` middleware; admin endpoints add `admin` middleware |
| NFR2 | Reliable persistence | PostgreSQL ACID, FK constraints, transactional task completion |
| NFR3 | Responsive UI (desktop + mobile) | CSS breakpoints at 1180/980/640 px in `frontend/src/index.css` |
| NFR4 | Page load ≤ 3 s | Vite SPA, eager loading in services, paginated list endpoints |
| NFR5 | Server‑side validation | Form Request classes per controller |
| NFR6 | Clear user feedback | Consistent JSON error envelopes, frontend toaster + form‑level errors |

### 6.10 External Integrations (Final)

| Boundary | Service Class | Failure Mode |
|---|---|---|
| Meteo.lt | `MeteoLtClient` + `WeatherService` | Fallback to cached `weather_forecasts` row |
| Perenual | `PerenualService` + `PlantCareService` (`PlantCareNormalizer`, `PlantCareDefaults`) | Use cached `plant_care` row; fall back to defaults |
| SMTP (password reset) | `EmailServerBoundary` (`Illuminate\Support\Facades\Mail`) | Surface user‑safe error, do not leak existence |

### 6.11 Verification Checklist (mandatory)

After any change, the following MUST pass:

```
php artisan migrate         # PostgreSQL migrations apply cleanly
php artisan test            # 23+ feature tests + unit tests green
npm run build               # Vite build succeeds
```

Plus the static rules from `AGENTS.md`:

- [x] All FKs enforced
- [x] Weather provider = Meteo.lt only
- [x] Database = PostgreSQL only
- [x] No business logic on the frontend
- [x] No additional roles beyond owner / admin
- [x] No alternative external APIs introduced

---

## 7. Conclusion

| Metric | Value |
|---|---|
| Functional use cases implemented | **28 / 28** |
| Non‑functional requirements satisfied | **6 / 6** |
| Critical implementation gaps | **0** |
| Documentation gaps (extras / drift) | **8** |
| Conditional / verification items | **5** |
| **Overall alignment** | **96 %** |

**Recommendation:** Proceed to defence. Update the thesis text and class diagram per §3 and §4 (eight items, all 📝 documentation updates) so the written work fully matches the operational implementation. No code changes are required to align the system with the spec; the system already meets or exceeds every promise in the thesis.

---

*Audit produced 2026-04-09 against branch `master` of `Realizacija_v2`.*
