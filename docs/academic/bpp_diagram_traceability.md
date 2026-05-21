# BPP diagramu atsekamumas

Saltinis: tik `Realizacija_v2` codebase, konfiguracija ir reference HTML naudotas tik stiliui. Busenos:

- VERIFIED: tiesiogiai matoma faile, route deklaracijoje, dependency arba konfiguracijoje.
- INFERRED: stipriai pagrista keliu failu rysiais, bet neirasytas atskiras deployment dokumentas.
- NOT VERIFIED: repo to nepatvirtina; diagramose nerodyta kaip faktas.

## Elementai

| Diagramos elementas | Diagramos tipas | Repo vieta | Irodymas / paaiskinimas | Pastabos / neapibreztumai |
|---|---|---|---|---|
| React_Vite_SPA | Komponentu | `frontend/package.json`, `frontend/src/App.jsx`, `frontend/vite.config.js` | `package.json` turi `react`, `react-dom`, `vite`; `App.jsx` apraso SPA routes; Vite config apibreztas dev serveris. | VERIFIED |
| Auth_Profile_UI | Komponentu | `frontend/src/pages/user/*`, `frontend/src/context/AuthContext.jsx`, `frontend/src/lib/auth.js` | Yra login, register, forgot/reset password, account puslapiai; auth busena saugoma localStorage. | VERIFIED |
| Plot_Workspace_UI | Komponentu | `frontend/src/pages/plot/*`, `frontend/src/components/plot/*` | Yra plot detail/edit/history/rotation/sharing/harvests/analytics puslapiai ir plot designer komponentai. | VERIFIED |
| Plant_Catalog_UI | Komponentu | `frontend/src/pages/plant/*` | Yra `PlantsPage`, `PlantDetailPage`, `PlantFormPage`, `CatalogPlantsPage`, catalog detail/form puslapiai. | VERIFIED |
| Calendar_UI | Komponentu | `frontend/src/pages/calendar/PlotCalendarPage.jsx` | Puslapis naudoja API kalendoriu ir uzduociu perziurai/generavimui. | VERIFIED |
| Inventory_UI | Komponentu | `frontend/src/pages/inventory/InventoryPage.jsx` | Inventory UI realizuotas viename puslapyje su testu. | VERIFIED |
| Community_UI | Komponentu | `frontend/src/pages/community/CommunityPage.jsx` | Bendruomenes puslapis egzistuoja ir kviecia `/community` API per `api.js`. | VERIFIED |
| Admin_UI | Komponentu | `frontend/src/pages/admin/AdminUsersPage.jsx`, `frontend/src/components/shared/AdminRoute.jsx` | Admin naudotoju puslapis saugomas `AdminRoute`, tikrinamas `user.role === 'admin'`. | VERIFIED |
| API_Client_Auth_State | Komponentu | `frontend/src/lib/api.js`, `frontend/src/lib/auth.js`, `frontend/src/context/AuthContext.jsx` | `axios.create({ baseURL: '/api' })`, Authorization Bearer token is localStorage. | VERIFIED |
| Laravel_REST_API | Komponentu | `backend/routes/api.php`, `backend/app/Http/Controllers/**` | REST API routes deklaruotos `routes/api.php`, naudojami Laravel controllers/resources/requests. | VERIFIED |
| Public_Auth_API | Komponentu | `backend/routes/api.php`, `backend/app/Http/Controllers/User/*` | `/register`, `/login`, `/forgot-password`, `/reset-password`; login/register sukuria Sanctum token. | VERIFIED |
| Plot_API | Komponentu | `backend/routes/api.php`, `backend/app/Http/Controllers/Plot/*`, `backend/app/Http/Controllers/Api/Plot/*` | `Route::apiResource('plots')`, plant-zones, workspace, export, analytics, sharing, history, rotation, harvest routes. | VERIFIED |
| Plant_Catalog_API | Komponentu | `backend/routes/api.php`, `backend/app/Http/Controllers/Plant/*` | Routes `/plants`, `/catalog-plants`, `/catalog-plants/perenual/*`, condition history endpoints. | VERIFIED |
| Calendar_Task_API | Komponentu | `backend/app/Http/Controllers/Api/Calendar/*`, `backend/routes/api.php` | Routes `/plots/{plot}/calendars`, `/calendars/{calendar}/tasks`, `/tasks/{task}/complete|reject`. | VERIFIED |
| Inventory_API | Komponentu | `backend/app/Http/Controllers/Api/Inventory/InventoryController.php`, `backend/routes/api.php` | Routes `/inventory` CRUD. | VERIFIED |
| Community_API | Komponentu | `backend/app/Http/Controllers/Api/Community/CommunityController.php`, `backend/routes/api.php` | Routes `/community` ir `/plots/{plot}/community`. | VERIFIED |
| Admin_Export_Analytics_API | Komponentu | `backend/app/Http/Controllers/Api/Admin/*`, `backend/app/Http/Controllers/Api/Plot/ExportController.php`, `AnalyticsController.php` | Admin users/audit routes, plot analytics, PDF export endpoint. | VERIFIED |
| Sanctum_And_Middleware | Komponentu | `backend/composer.json`, `backend/bootstrap/app.php`, `backend/config/sanctum.php`, `backend/app/Http/Middleware/*`, `backend/database/migrations/2026_03_20_112109_create_personal_access_tokens_table.php` | `laravel/sanctum` dependency; `statefulApi()`; `auth:sanctum` route group; personal access tokens table. | VERIFIED |
| Application_Services | Komponentu | `backend/app/Services/**` | 30 service klasiu su business logic: access, calendar, inventory, weather, plant care, export, analytics. | VERIFIED |
| Access_Account_Admin_Services | Komponentu | `AccessService.php`, `AccountService.php`, `AdminService.php` | Controllers injectuoja siuos servisus; `AccessService` tikrina plot roles ir share access. | VERIFIED |
| Plot_Planning_Services | Komponentu | `PlotWorkspaceService.php`, `PlotSnapshotService.php`, `RotationPlannerService.php`, `CropRotationClassifier.php` | Workspace commit, plot snapshots ir rotation plan logic atskirti servisuose. | VERIFIED |
| Plant_Care_Lifecycle_Services | Komponentu | `PlantCareService.php`, `CatalogPlantService.php`, `PlantLifecycleService.php`, `PlantLifecyclePhaseService.php`, `PlantConditionHistoryService.php` | Plant CRUD ir calendar generation injectuoja plant care/lifecycle servisus. | VERIFIED |
| Calendar_Task_Workflow_Services | Komponentu | `CalendarGenerationService.php`, `TaskCalendarService.php`, `TaskWorkflowService.php`, `WeatherService.php`, `TaskInventoryCoverageService.php` | Calendar generation kuria `TaskCalendar`, `Task`, `WeatherForecast`, taiko weather/inventory workflow. | VERIFIED |
| Inventory_Services | Komponentu | `InventoryService.php`, `InventoryPlanningRepairService.php` | Inventory CRUD ir calendar planning naudoja inventory ledger/coverage. | VERIFIED |
| Community_Analytics_Export | Komponentu | `CommunityService.php`, `AnalyticsService.php`, `HarvestService.php`, `PdfExportService.php` | Community CRUD, plot analytics, harvest records ir PDF export realizuoti servisuose. | VERIFIED |
| Integration_Boundaries | Komponentu | `MeteoLtClient.php`, `PerenualService.php`, `EmailServerBoundary.php`, `config/services.php`, `config/mail.php` | Atskiros klases isoriniams HTTP/Mail ribiniams veiksmams. | VERIFIED |
| MeteoLtClient | Komponentu/Diegimo rysys | `backend/app/Services/MeteoLtClient.php`, `backend/config/services.php`, `.env.example` | Naudoja `Http::acceptJson()->get($baseUrl...)`; base URL `https://api.meteo.lt/v1`. | VERIFIED |
| PerenualService | Komponentu/Diegimo rysys | `backend/app/Services/PerenualService.php`, `backend/config/services.php` | Naudoja `Http::timeout()->get("{$baseUrl}/species-list")`, details ir care-guide endpoints; base URL `https://perenual.com/api`. | VERIFIED |
| EmailServerBoundary | Komponentu | `backend/app/Services/EmailServerBoundary.php`, `backend/config/mail.php`, `.env.example` | `Mail::raw(...)` siuncia password reset code; `.env.example` default `MAIL_MAILER=log`. | VERIFIED; isorinis SMTP serveris NOT VERIFIED |
| Eloquent_Domain_Model | Komponentu | `backend/app/Models/**`, `backend/database/migrations/**`, `backend/config/database.php` | Modeliai extends Eloquent Model/Authenticatable; migrations kuria lenteles ir FK. | VERIFIED |
| PostgreSQL_DB | Komponentu/Diegimo | `backend/.env.example`, `backend/config/database.php`, migrations | Default `DB_CONNECTION=pgsql`, `DB_PORT=5432`, migrations turi PostgreSQL check constraints ir JSONB. | VERIFIED |
| dompdf/dompdf | Komponentu | `backend/composer.json`, `backend/app/Services/PdfExportService.php`, `backend/resources/views/pdf/plot-report.blade.php` | Composer dependency; `new Dompdf`, `loadHtml`, `render`, response PDF. | VERIFIED |
| Local_development_workstation | Diegimo | `start.bat`, `backend/.env.example`, `frontend/vite.config.js` | `start.bat` paleidzia Laravel ir React atskiruose lokaliuose procesuose. | VERIFIED local runtime; production hosting NOT VERIFIED |
| Client_device / Web_browser | Diegimo | `frontend/src/main.jsx`, `frontend/src/App.jsx`, `start.bat` | SPA skirta naršyklei; `start.bat` nurodo atidaryti `http://localhost:5173`. | INFERRED but strong |
| Vite_dev_server | Diegimo | `start.bat`, `frontend/package.json`, `frontend/vite.config.js` | `npm run dev` paleidzia Vite; Vite proxy `target: http://127.0.0.1:8000`. | VERIFIED |
| Laravel_artisan_server | Diegimo | `start.bat`, `backend/artisan`, `backend/public/index.php`, `backend/routes/api.php` | `php artisan serve` ant `http://127.0.0.1:8000`; Laravel front controller `public/index.php`. | VERIFIED |
| Local_file_services | Diegimo | `backend/.env.example`, `backend/config/cache.php`, `backend/config/session.php`, `backend/config/queue.php`, `backend/config/mail.php` | `.env.example`: `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`. | VERIFIED |
| Meteo.lt_API | Komponentu/Diegimo | `config/services.php`, `.env.example`, `MeteoLtClient.php` | Base URL ir HTTP client usage patvirtinti. | VERIFIED |
| Perenual_API | Komponentu/Diegimo | `config/services.php`, `PerenualService.php` | Base URL, API key env ir endpoints patvirtinti. | VERIFIED |

## Rysiai

| Rysys diagramoje | Diagramos tipas | Repo vieta | Irodymas / paaiskinimas | Pastabos |
|---|---|---|---|---|
| React_Vite_SPA -> Laravel_REST_API | Komponentu/Diegimo | `frontend/src/lib/api.js`, `frontend/vite.config.js`, `backend/routes/api.php` | `axios` baseURL `/api`; Vite proxy `/api` i Laravel; Laravel apibrėzia `/api/*` routes. | VERIFIED |
| Laravel_REST_API -> Application_Services | Komponentu | Controllers po `backend/app/Http/Controllers/**` | Controllers injectuoja `AccessService`, `CalendarGenerationService`, `InventoryService`, `PdfExportService`, ir kt. | VERIFIED |
| Application_Services -> Eloquent_Domain_Model | Komponentu | `backend/app/Services/*.php`, `backend/app/Models/*.php` | Servisai kviecia `Model::query()`, `DB::transaction()`, relationships. | VERIFIED |
| Eloquent_Domain_Model -> PostgreSQL_DB | Komponentu/Diegimo | `backend/config/database.php`, `.env.example`, migrations | Eloquent naudoja default `pgsql`; migrations kuria lenteles/FK. | VERIFIED |
| Calendar_Task_Workflow_Services -> MeteoLtClient -> Meteo.lt_API | Komponentu/Diegimo | `CalendarGenerationService.php`, `WeatherService.php`, `MeteoLtClient.php` | Calendar generation injectuoja `WeatherService`, `WeatherService` injectuoja `MeteoLtClient`, client kviecia Meteo.lt HTTP. | VERIFIED |
| Plant_Care_Lifecycle_Services -> PerenualService -> Perenual_API | Komponentu/Diegimo | `PlantCareService.php`, `CatalogPlantService.php`, `PerenualService.php` | Plant care/catalog services injectuoja PerenualService; service kviecia species endpoints. | VERIFIED |
| Community_Analytics_Export -> dompdf/dompdf | Komponentu | `PdfExportService.php`, `composer.json` | `PdfExportService` importuoja `Dompdf\Dompdf`; dependency composer faile. | VERIFIED |
| EmailServerBoundary -> Laravel_Mail / Mail_log_transport | Komponentu/Diegimo | `EmailServerBoundary.php`, `config/mail.php`, `.env.example` | `Mail::raw` naudojamas password reset code; default transport `log`. | VERIFIED default |
| Browser -> Vite_dev_server | Diegimo | `start.bat` | `start.bat` nurodo frontend `http://localhost:5173`. | VERIFIED |
| Vite_dev_server -> Laravel_artisan_server | Diegimo | `frontend/vite.config.js`, `start.bat` | Proxy target `http://127.0.0.1:8000`; backend paleidziamas tuo adresu. | VERIFIED |
| Laravel_artisan_server -> PostgreSQL | Diegimo | `.env.example`, `config/database.php` | `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_CONNECTION=pgsql`. | VERIFIED |

## Nerodyta diagramose kaip faktas

| Tema | Statusas | Pagrindimas |
|---|---|---|
| Docker / docker compose | NOT VERIFIED | Repo paieskoje nerasta `Dockerfile` ar `docker-compose*.yml`. |
| Nginx / Apache / reverse proxy | NOT VERIFIED | Repo nera nginx/apache konfigu; production web serveris nenustatytas. |
| Konkretus hosting provider arba OS | NOT VERIFIED | Nera deployment/CI/hosting failu. |
| SMTP/TLS isorinis mail serveris | NOT VERIFIED | `config/mail.php` palaiko SMTP, bet `.env.example` default yra `MAIL_MAILER=log`; realus SMTP host nepatvirtintas. |
| Production static frontend serving | NOT VERIFIED | Yra Vite build script, bet nera repo deployment config, nurodancios kaip `dist` servinamas production aplinkoje. |
| Redis kaip naudojama runtime priklausomybe | NOT VERIFIED | Laravel config turi Redis defaults, bet `.env.example` naudoja file cache/session ir sync queue. |
