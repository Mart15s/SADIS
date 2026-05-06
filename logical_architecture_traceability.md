# Loginės architektūros traceability

## Diagramos elementai

| Diagramos elementas | Tipas | Repo vieta | Pagrindimas | Pastabos |
|---|---|---|---|---|
| Frontend SPA | package | `frontend/package.json`, `frontend/vite.config.js`, `frontend/src` | React, React DOM, React Router, Vite priklausomybės ir SPA įėjimo failai. | VERIFIED |
| Routing / Auth | subpackage | `frontend/src/App.jsx`, `frontend/src/context/AuthContext.jsx`, `frontend/src/components/shared/ProtectedRoute.jsx`, `frontend/src/components/shared/AdminRoute.jsx` | `App.jsx` deklaruoja React routes; auth state ir role tikrinimas realizuotas context/route guard komponentuose. | VERIFIED |
| Client API | subpackage | `frontend/src/lib/api.js`, `frontend/src/lib/auth.js` | Centralizuotas Axios klientas su `baseURL: '/api'`, auth tokenu, API metodais ir blob download. | VERIFIED |
| Pages | subpackage | `frontend/src/pages/admin`, `frontend/src/pages/calendar`, `frontend/src/pages/community`, `frontend/src/pages/dev`, `frontend/src/pages/inventory`, `frontend/src/pages/plant`, `frontend/src/pages/plot`, `frontend/src/pages/user` | Domeniniai puslapių katalogai atitinka realias UI sritis ir `App.jsx` route importus. | VERIFIED |
| Components | subpackage | `frontend/src/components/layout`, `frontend/src/components/shared`, `frontend/src/components/ui`, `frontend/src/components/plot` | Bendri layout, shared, UI ir plot komponentai naudojami puslapiuose. | VERIFIED |
| Frontend Plot Logic | subpackage | `frontend/src/lib/plotDesigner.js`, `frontend/src/lib/plotGeometry.js`, `frontend/src/lib/plotRender.js`, `frontend/src/lib/plotWorkspaceDraft.js` | Failai sudaro plot designer/geometrijos/renderinimo/draft logikos grupę. | INFERRED loginis grupavimas pagal failų paskirtį |
| UI Data Consumers | subpackage | `frontend/src/pages/**/*.jsx`, `frontend/src/components/plot/PlotPlantingDrawer.jsx` | Puslapiai ir plot komponentas kviečia `api.*` metodus. | VERIFIED |
| Backend API | package | `backend/composer.json`, `backend/routes/api.php`, `backend/app` | Laravel framework, Sanctum ir REST API struktūra. | VERIFIED |
| Routes / Middleware | subpackage | `backend/routes/api.php`, `backend/bootstrap/app.php`, `backend/app/Http/Middleware/AdminMiddleware.php`, `backend/app/Http/Middleware/DevOnlyMiddleware.php` | API maršrutai, `auth:sanctum`, `admin` ir `dev.only` middleware aliasai. | VERIFIED |
| HTTP Controllers | subpackage | `backend/app/Http/Controllers` | Controlleriai grupuojami pagal realius namespace/katalogus: `User`, `Plot`, `Plant`, `Api/Admin`, `Api/Calendar`, `Api/Inventory`, `Api/Community`, `Api/Dev`. | VERIFIED |
| Requests / Resources | subpackage | `backend/app/Http/Requests`, `backend/app/Http/Resources` | FormRequest validavimas ir JSON Resource serializavimas naudojami controlleriuose. | VERIFIED |
| Application Services | subpackage | `backend/app/Services` | Verslo logikos klasės: `CalendarGenerationService`, `InventoryService`, `PlotWorkspaceService`, `PlantCareService`, `AnalyticsService`, `AccessService` ir kt. | VERIFIED |
| Support Types | subpackage | `backend/app/Enums`, `backend/app/Support`, `backend/app/ValueObjects`, `backend/app/Exceptions` | Tipai ir pagalbiniai objektai importuojami modeliuose, requestuose, controlleriuose ir servisuose. | VERIFIED |
| Laravel Entry Points | subpackage | `backend/bootstrap/app.php`, `backend/config/services.php`, `backend/config/database.php` | Konfigūruoja routes, middleware aliasus, exception renderinimą, external service URL ir DB default. | VERIFIED |
| Server-side Views | subpackage | `backend/resources/views/welcome.blade.php`, `backend/resources/views/pdf/plot-report.blade.php` | Repo turi Laravel Blade failus, bet jie nėra pagrindinis UI sluoksnis; PDF report view naudojamas eksportui. | VERIFIED |
| Data Model | package | `backend/app/Models`, `backend/app/Enums`, `backend/database/migrations`, `backend/config/database.php` | Eloquent modeliai, enumai ir migracijos su PostgreSQL default ryšiu. | VERIFIED |
| Models | subpackage | `backend/app/Models` | Modeliai aprašo user/profile/owner, plot/zone/plant, calendar/task, inventory, access, community, audit, harvest sritis. | VERIFIED |
| Schema | subpackage | `backend/database/migrations` | Migracijos kuria lenteles, FK, check constraints, JSON/JSONB laukus, snapshot/audit/usage lenteles. | VERIFIED |
| Domain Enumerations | subpackage | `backend/app/Enums` | `UserRole`, `AccessRole`, `TaskType`, `TaskState`, `PlantType`, `ConditionType`, `InventoryUnit` ir kt. naudojami kode. | VERIFIED |
| External Adapters | package | `backend/app/Services/MeteoLtClient.php`, `backend/app/Services/PerenualService.php`, `backend/app/Services/EmailServerBoundary.php`, `backend/app/Services/PdfExportService.php`, `backend/config/services.php` | Boundary/client klasės jungia sistemą su orų, augalų priežiūros, el. pašto ir PDF generavimo mechanizmais. | INFERRED kaip loginis paketas, VERIFIED klasės |

## Ryšiai

| Ryšys | Tipas | Pagrindimas | Pastabos |
|---|---|---|---|
| Frontend SPA -> Backend API | dependency / usage | `frontend/src/lib/api.js` naudoja Axios `baseURL: '/api'`; `frontend/vite.config.js` proxy'ina `/api` ir `/sanctum`; `backend/routes/api.php` deklaruoja endpointus. | VERIFIED |
| Routing / Auth -> Client API | dependency / usage | `AuthContext.jsx` importuoja `api` ir kviečia `getMe`, `login`, `register`, `updateMe`, `logout`. | VERIFIED |
| Pages -> Client API | dependency / usage | `frontend/src/pages/*/*.jsx` importuoja `api` ir kviečia domeninius API metodus. | VERIFIED |
| Client API -> Routes / Middleware | dependency / usage | `api.js` metodų keliai atitinka `routes/api.php` endpointus: `/plots`, `/plants`, `/inventory`, `/community`, `/admin/users`, `/plots/{plot}/calendars` ir kt. | VERIFIED |
| Routes / Middleware -> HTTP Controllers | dependency / usage | `routes/api.php` importuoja controllerių klases ir mapina route'us į controllerių metodus. | VERIFIED |
| HTTP Controllers -> Requests / Resources | dependency / usage | Controlleriai importuoja FormRequest ir Resource klases, pvz. `GenerateCalendarRequest`, `TaskCalendarResource`, `InventoryItemResource`, `CommunityPostResource`. | VERIFIED |
| HTTP Controllers -> Application Services | dependency / usage | Controlleriai importuoja ir kviečia servisus, pvz. `TaskCalendarService`, `InventoryService`, `AnalyticsService`, `HarvestService`, `PlotSnapshotService`. | VERIFIED |
| HTTP Controllers -> Data Model | dependency / usage | Controlleriai importuoja Eloquent modelius, pvz. `Plot`, `Plant`, `TaskCalendar`, `InventoryItem`, `CommunityPost`, `User`. | VERIFIED |
| Application Services -> Data Model | dependency / usage | Servisai importuoja Eloquent modelius ir enumus, pvz. `CalendarGenerationService` naudoja `Plant`, `PlantCare`, `Task`, `TaskCalendar`, `WeatherForecast`; `InventoryService` naudoja `InventoryItem`, `TaskResourceRequirement`. | VERIFIED |
| Models -> Schema | realization / persistence | Modeliai atitinka migracijose kuriamas lenteles; migracijos aprašo FK ir constraints, modeliai aprašo Eloquent ryšius. | VERIFIED |
| Application Services -> External Adapters | dependency / usage | `WeatherService` konstruojamas su `MeteoLtClient`; `PlantCareService` ir `CatalogPlantService` konstruojami su `PerenualService`; `PasswordResetController` naudoja `EmailServerBoundary`; `ExportController` naudoja `PdfExportService`. | VERIFIED |
| External Adapters -> External APIs / libraries | dependency / usage | `MeteoLtClient` skaito `services.meteo_lt.base_url` ir daro HTTP užklausas; `PerenualService` skaito `services.perenual.*` ir daro HTTP užklausas; `composer.json` turi `dompdf/dompdf`; `EmailServerBoundary` naudoja el. pašto reset funkciją. | VERIFIED |
| Calendar services -> Weather / Inventory / Plant care services | dependency / usage | `CalendarGenerationService` konstruktoriuje turi `InventoryService`, `TaskInventoryCoverageService`, `PlantCareService`, `PlantLifecyclePhaseService`, `PlantLifecycleService`, `WeatherService`. | VERIFIED |
| Plot package -> Analytics / Export / Harvest / Sharing / History | composition / containment | Šios sritys fiziškai realizuotos `backend/app/Http/Controllers/Api/Plot/*Controller.php` ir atitinkamuose servisuose. | VERIFIED, bet diagramoje jos nerodomos kaip atskiri top-level paketai |
