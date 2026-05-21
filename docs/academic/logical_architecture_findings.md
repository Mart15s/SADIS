# Loginės architektūros BCE findings

## VERIFIED pagal repo

- `frontend/src/pages/**` yra realus React SPA puslapių sluoksnis. Rasti puslapių katalogai: `admin`, `calendar`, `community`, `dev`, `inventory`, `plant`, `plot`, `user`.
- React pages yra pagrindiniai naudotojo sąsajos `«boundary»` elementai. Jie importuojami iš `frontend/src/App.jsx` ir mapinami į React Router route'us.
- `frontend/src/lib/api.js` yra pagrindinis Client API / API Gateway `«boundary»` elementas. Jis kuria Axios klientą su `baseURL: '/api'`, prideda Bearer tokeną iš `frontend/src/lib/auth.js` ir eksportuoja `api.*` metodus.
- `frontend/src/context/AuthContext.jsx` yra auth boundary/context sluoksnis. Jis kviečia `api.getMe`, `api.login`, `api.register`, `api.updateMe`, `api.logout` ir dėl to login/register/account puslapiai API pasiekia per `useAuth()`.
- Testiniai frontend failai, pvz. `PlotCalendarPage.test.jsx`, `InventoryPage.test.jsx`, `PlantDetailPage.test.jsx`, `PlotAnalyticsPage.test.jsx`, `PlotDetailPage.test.jsx`, nėra įtraukti kaip architektūros `«boundary»` puslapiai.
- `backend/routes/api.php` yra realus REST API maršrutų sluoksnis. Jame VERIFIED endpoint -> controller atsekamumas.
- `auth:sanctum`, `admin` ir `dev.only` middleware naudojimas VERIFIED pagal `backend/routes/api.php`; `admin` ir `dev.only` aliasai VERIFIED pagal `backend/bootstrap/app.php`.
- `backend/app/Http/Middleware/AdminMiddleware.php` tikrina `UserRole::Admin`; `backend/app/Http/Middleware/DevOnlyMiddleware.php` blokuoja dev endpointus production aplinkoje.
- Laravel controllers ir services yra `«control»` elementai. Controlleriai rasti `backend/app/Http/Controllers/**`, services rasti `backend/app/Services/**`.
- Eloquent models yra `«entity»` elementai. Modeliai rasti `backend/app/Models/**`.
- Requests / Resources sluoksnis realiai naudojamas controlleriuose: `backend/app/Http/Requests/**` ir `backend/app/Http/Resources/**`.
- Domain enumerations realiai yra `backend/app/Enums/**`: `AccessRole`, `AnalysisType`, `ConditionType`, `InventoryItemType`, `InventoryUnit`, `PlantType`, `SoilType`, `TaskPriority`, `TaskState`, `TaskType`, `UserRole`.
- Modeliai yra pagrįsti migracijomis iš `backend/database/migrations/**`. Migracijose rasti modelių lentelių kūrimai / keitimai, foreign keys, check constraints ir geometry JSON laukai.
- PostgreSQL kaip numatytas DB ryšys VERIFIED pagal backend konfigūraciją ankstesniame architektūros audite; šiame BCE atnaujinime migracijos rodomos kaip `PostgreSQL schema / migrations`.
- `resources/views` nėra pagrindinis UI sluoksnis. Reali naudotojo UI yra React SPA. Blade view gali būti rodomas tik kaip server-side pagalbinis sluoksnis PDF eksportui, nes repo turi `backend/resources/views/pdf/plot-report.blade.php`.

## VERIFIED ryšiai

- `Pages «boundary»` -> `Client API «boundary»`: nustatyta pagal `api.*` kvietimus puslapiuose ir `useAuth()` kvietimus auth puslapiuose.
- `Client API «boundary»` -> `Routes / Middleware`: nustatyta pagal `frontend/src/lib/api.js` endpointus ir `backend/routes/api.php`.
- `Routes / Middleware` -> `Controllers «control»`: nustatyta pagal `routes/api.php` controller klasių importus ir route deklaracijas.
- `Controllers «control»` -> `Services «control»`: nustatyta pagal controllerių `use App\Services\...` importus, constructor injection ir tiesioginį naudojimą.
- `Controllers «control»` -> `Models «entity»`: nustatyta pagal controllerių `use App\Models\...` importus ir route model binding tipus.
- `Services «control»` -> `Models «entity»`: nustatyta pagal service klasių `use App\Models\...` importus.
- `Services «control»` -> `External Boundaries / Adapters`: VERIFIED pagal `WeatherService` -> `MeteoLtClient`, plant/catalog care servisus -> `PerenualService`, `PasswordResetController` -> `EmailServerBoundary`, `ExportController` -> `PdfExportService`.
- `Models «entity»` -> `PostgreSQL schema / migrations`: nustatyta pagal modelių lentelių pavadinimus ir migracijų `Schema::create/table(...)` deklaracijas.

## INFERRED loginis grupavimas

- Services sugrupuoti į sritis pagal realų naudojimą, klasių importus ir domeninius pavadinimus: Access / Auth / Account, Plot / Workspace / Rotation / Snapshot, Plant / Catalog / PlantCare / Lifecycle, Calendar / Tasks / Weather, Inventory, Community, Analytics / Harvest / Export, External integrations.
- `External Boundaries / Adapters` kaip atskiras diagramoje rodomas paketas yra loginis grupavimas. Fiziškai šios klasės yra `backend/app/Services`, bet jų vaidmuo sekų diagramose yra integracinė riba: `MeteoLtClient`, `PerenualService`, `EmailServerBoundary`, `PdfExportService`.
- `Components «boundary»` diagramoje rodo tik svarbius realius UI boundary pagalbininkus (`AppShell`, `ProtectedRoute`, `AdminRoute`, `AuthContext`, plot designer komponentus). Tai nėra pilnas visų komponentų sąrašas.
- Flow mapping lentelėje kai kurie vidiniai service chain ryšiai pažymėti pagal importus ir service-to-service tipų naudojimą; kai kelias eina per tarpinį service, jis pateiktas kaip loginis flow pagrindas sekų diagramoms.

## NOT VERIFIED / neįtraukta

- Atskiro Repository sluoksnio repo nerasta, todėl diagramoje jo nėra.
- Atskiro Laravel Policy sluoksnio repo nerasta. Prieiga įgyvendinama per `AccessService`, `AuthorizesPlotAccess`, `AdminMiddleware` ir route middleware.
- Nėra įtraukta jokių išgalvotų page, controller, service ar model pavadinimų. Tušti ar tik paveldintys failai pažymėti kaip tokie, pvz. `ConditionHistoryController` paveldi iš `PlantConditionController` ir neturi direct route.
- `frontend/src/pages/**/*.test.jsx` ir kiti testiniai failai nėra laikomi realiais BCE boundary elementais.
- Blade `welcome.blade.php` nelaikomas pagrindiniu naudotojo UI, nes realus flow eina per React SPA.

## Stereotype taisyklės šiam projektui

- React pages yra pagrindiniai naudotojo sąsajos `«boundary»` elementai.
- `frontend/src/lib/api.js`, `frontend/src/lib/auth.js` ir `AuthContext.jsx` yra kliento API / auth boundary elementai.
- Laravel controllers yra `«control»` elementai, nes priima HTTP requestus, validuoja per FormRequest / Request ir nukreipia į services arba modelius.
- Laravel services yra `«control»` / business logic elementai, nes realizuoja prieigos, planavimo, kalendoriaus, inventoriaus, augalų priežiūros, analitikos, PDF ir integracijų logiką.
- Eloquent models yra `«entity»` elementai, nes reprezentuoja persistuojamus domeno objektus ir ryšius su DB schema.

## Sukurti / atnaujinti artefaktai

- `logical_architecture_diagram.html` atnaujintas į BCE UML paketų diagramą su realiais React pages, API klientu, Laravel routes/controllers/services, Eloquent modeliais, enumais, migracijomis ir external adapters.
- `logical_architecture_bce_traceability.md` sukurtas kaip pagrindinis sekų diagramų / PA realizacijos flow pagrindas.
- `logical_architecture_findings.md` atnaujintas su VERIFIED / INFERRED / NOT VERIFIED paaiškinimais.
