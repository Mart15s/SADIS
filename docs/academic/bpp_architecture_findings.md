# BPP architekturos audito isvados

## Realus stack

| Sritis | VERIFIED is codebase |
|---|---|
| Backend | Laravel project, PHP `^8.3`, `laravel/framework ^13.0`, REST API routes `backend/routes/api.php`. |
| Auth | Laravel Sanctum `^4.3`; `auth:sanctum` route group; login/register sukuria `auth-token`; frontend saugo Bearer token localStorage. |
| Frontend | React `^19.2.4`, Vite `^8.0.1`, `react-router-dom ^7.13.2`, Axios `^1.13.6`. |
| UI geometry/editor | `konva` ir `react-konva`; plot UI komponentai `PlotDesignerCanvas`, `PlotPlantingDrawer`, geometry helperiai. |
| Database | PostgreSQL kaip default: `.env.example` `DB_CONNECTION=pgsql`, `DB_PORT=5432`, `DB_DATABASE=sad_system`; migrations turi FK ir PostgreSQL check constraints. |
| PDF | `dompdf/dompdf 3.1`, `PdfExportService`, `resources/views/pdf/plot-report.blade.php`. |
| Weather | Meteo.lt per `MeteoLtClient` ir `WeatherService`, base URL `https://api.meteo.lt/v1`. |
| Plant care external API | Perenual per `PerenualService`, base URL `https://perenual.com/api`, API key per `PERENUAL_API_KEY`. |
| Mail | Password reset naudoja Laravel `Mail::raw`; `.env.example` default `MAIL_MAILER=log`. SMTP serveris nepatvirtintas. |
| Local runtime | `start.bat` paleidzia Laravel `php artisan serve` ir React `npm run dev`. |

## Realus pagrindiniai moduliai

- Frontend: user/auth pages, plot pages, plant/catalog pages, calendar, inventory, community, admin, shared route guards, API client.
- Backend API: auth/user, admin/audit, plot/workspace/share/history/export/analytics/harvest, plant/catalog/conditions, calendar/tasks, inventory, community, dev debug endpoints.
- Service layer: access/account/admin, plot workspace/snapshots/rotation, plant care/catalog/lifecycle/condition, calendar/weather/task workflow, inventory planning, community/analytics/harvest/PDF.
- Data layer: Eloquent models and migrations for users, profiles, garden owners, plots, plant zones, plants, plant care, task calendars, tasks, weather forecasts, inventory, access rights, community posts, snapshots, harvests, audit logs, rotation plan drafts, task resource requirements, usage logs.

## Realios isorines integracijos

- Meteo.lt yra VERIFIED: `MeteoLtClient` naudoja Laravel HTTP client ir `config('services.meteo_lt.base_url')`.
- Perenual yra VERIFIED: `PerenualService` naudoja Laravel HTTP client ir `config('services.perenual.*')`.
- El. pasto isorinis serveris yra NOT VERIFIED: yra `EmailServerBoundary` ir Laravel mail config, bet `.env.example` rodo `MAIL_MAILER=log`, todel deployment diagramoje nera Mail serverio mazgo.

## Realus duomenu sluoksnis

PostgreSQL yra pagrindinis VERIFIED duomenu sluoksnis. `config/database.php` default yra `env('DB_CONNECTION', 'pgsql')`, `.env.example` pateikia pgsql parametrus, o migrations kuria foreign keys, JSON/JSONB laukus ir PostgreSQL check constraints. SQLite egzistuoja tik Laravel standartineje config parinktyje, bet pagal repo default nera production ar local paleidimo tiesa.

## BPP prielaidos: sutampa / nesutampa

| Prielaida | Codebase rezultatas |
|---|---|
| Laravel + React | Sutampa. Atskiri `backend` ir `frontend` projektai. |
| REST API `/api/*` | Sutampa. `routes/api.php` ir Axios `/api`. |
| PostgreSQL | Sutampa pagal `.env.example` ir config default. |
| Laravel Sanctum | Sutampa. Sanctum dependency, token generation, `auth:sanctum`. |
| Meteo.lt | Sutampa. Yra realus `MeteoLtClient`. |
| Perenual | Sutampa. Yra realus `PerenualService`. |
| Email server | Dalinai. Password reset mail boundary yra, bet realus isorinis SMTP serveris nepatvirtintas. |
| Server-side PDF | Sutampa. `PdfExportService` su Dompdf. |
| Docker/container/reverse proxy | Nesutampa arba nepatvirtinta. Repo neturi tokiu deployment failu. |
| Production frontend serving | Neaisku. Yra `npm run build`, bet nera production serving/deploy config. |

## Diagramu pavadinimu pasirinkimas

Pavadinimai pasirinkti pagal realias kodo ribas, ne pagal teorine BPP sluoksniu schema:

- `React_Vite_SPA` remiasi `frontend/package.json`, `App.jsx`, `vite.config.js`.
- `Laravel_REST_API` remiasi `routes/api.php` ir controllers katalogais.
- `Application_Services` remiasi realiu `app/Services` katalogu.
- `Integration_Boundaries` pavadinimas naudotas todel, kad integraciju klases yra bendrame `app/Services` kataloge, ne atskirame `Integrations` kataloge.
- `Eloquent_Domain_Model` remiasi `app/Models` ir migrations; diagramoje nerodomas atskiras repository layer, nes codebase jo neturi.
- Deployment diagramoje naudojamas `Local_development_workstation`, nes tai vienintele pilnai patvirtinta paleidimo architektura (`start.bat`).

## Skirtumai nuo reference diagramu

- Pasalinti teoriniai ar nepatvirtinti elementai, pvz. atskiras production web serveris, Mail serveris, generic hosting mazgai.
- Vietoje abstraktaus `Frontend` parodytas faktinis React/Vite SPA su realiomis page grupemis.
- Vietoje bendru `AuthController` ar `PlantCareController` pavadinimu naudoti realus controllers/services pedsakai: atskiri User controllers, `CatalogPlantController`, `PlantController`, `PlantConditionController`.
- Integracijos pavadintos pagal realias klases: `MeteoLtClient`, `PerenualService`, `EmailServerBoundary`.
- Deployment diagrama rodo local Vite dev server + Laravel artisan server + PostgreSQL, nes butent tai patvirtina repo. Production deployment nepiestas kaip faktas.

## Savikontrole

- Komponentu diagramoje nera Docker, nginx, hosting provider, Redis runtime ar SMTP serverio kaip fakto.
- Deployment diagramoje rodomi tik `start.bat`, `.env.example`, Vite proxy, Laravel API, PostgreSQL, Meteo.lt ir Perenual pagrindziami elementai.
- Mail pavaizduotas kaip `Mail_log_transport`, ne kaip isorinis SMTP mazgas.
- Diagramu ryšiai turi atsekamuma `bpp_diagram_traceability.md`.
- HTML diagramos naudoja stabilu SVG isdestyma ir UML stereotipus, ne UI mockup stiliu.
