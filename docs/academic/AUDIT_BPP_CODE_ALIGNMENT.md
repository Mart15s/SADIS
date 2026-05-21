# BPP ir kodo atitikties auditas

Audito data: 2026-05-20  
Pirminis audito saltinis: `C:\Users\Vartotojas\Desktop\PraktikaADIS\BPP\done\done3\Cesnauskas_IS_baigiamasis projektas_2026.pdf`  
Kodo baze: `C:\Users\Vartotojas\Desktop\PraktikaADIS\Realizacija_v2`

Pastaba del metodo: PDF buvo istrauktas su `pypdf`, nes `pdftotext` siame kompiuteryje nerastas. Kodo nekeista; patikra atlikta statiniu budu pagal realius route'us, migracijas, modelius, servisus, frontend puslapius, testus ir deployment failus. `php artisan migrate`, `php artisan test` ir `npm run build` audito metu nepaleisti, kad nebutu rizikuojama realia DB ar aplinka.

## 1. Santrauka

- Bendras ivertinimas: apie 88 % atitikties BPP PDF aprasytai sistemai.
- FR 1.1-1.30: 30 ATITINKA, 0 DALINAI ATITINKA, 0 NEATITINKA, 0 NEPATIKRINTA.
- NFR 2.1-2.6: 4 ATITINKA, 2 DALINAI ATITINKA, 0 NEATITINKA, 0 NEPATIKRINTA.
- Testavimo teiginiai 3.1-3.21: dauguma turi automatinius arba UI/API irodymus, taciau nasumo, mobilios UI ir PostgreSQL migraciju patikra siame audite nebuvo realiai vykdyta.
- Pagrindine isvada: sistema labai arti rasto darbo. Didziausia rizika nera architekturine, o irodomumo/demonstravimo: kai kurios BPP aprasytos funkcijos yra realizuotos, bet priklauso nuo duomenu, demo seederiu, isoriniu API arba rankinio demonstravimo scenarijaus.

Top 10 svarbiausiu riziku:

1. Testu aplinka `backend/phpunit.xml` naudoja SQLite `:memory:`, nors BPP ir projektines taisykles akcentuoja PostgreSQL.
2. NFR2, puslapiu ikelimas <= 3 s, kode turi tik salygas, bet nera realiu matavimo irodymu.
3. NFR3, responsive UI yra CSS/media query lygmeniu, bet nera automatizuotu mobiliu viewport testu.
4. `garden_system_spec.docx` ir naudotojo instrukcijos akcentuoja tik roles `owner/admin`, o naudotojo dokumentacija mini "registruota naudotoja" ir "bendradarbi"; tai labiau dokumentacijos/naming rizika, nes DB roles nera papildytos.
5. Kalendoriaus generavimas stiprus, bet demonstracijoje privaloma tureti augalus su `plant_care`, miesta, inventoriu ir prognoziu fallback duomenis.
6. Perenual integracijai reikia `PERENUAL_API_KEY`; be rakto automatinis katalogo pildymas veiks tik lokaliu/fallback duomenu ribose.
7. Meteo.lt priklauso nuo miesto atpazinimo; blogas miestu ivedimas gali sukelti fallback scenariju.
8. Planavimo istorija veikia per `plot_snapshots`, bet UI tekste teigiama, kad istorija pradedama nuo aiskaus workspace issaugojimo; gynime reikia parodyti explicit save, ne vien automatini redagavima.
9. Yra senu jungiamuju lenteliu/modeliu `has_plot`, `has_inventory`, `used_on`; BPP 3 priedo finaline lenteliu lentele ju nemini, todel recenzentas gali klausti del schemos pertekliaus.
10. Audito metu komandos `php artisan migrate`, `php artisan test`, `npm run build` nebuvo paleistos; pries pridavima butina jas saugiai paleisti testineje PostgreSQL aplinkoje.

## 2. Kritiniai neatitikimai

| Nr. | Sritis | Rasto darbo teiginys / reikalavimas | Kodo realybe | Irodymai faile | Rizika | Rekomenduojamas veiksmas |
|---|---|---|---|---|---|---|
| 1 | Testu DB | BPP ir uzduotis remiasi PostgreSQL; sistema turi naudoti PostgreSQL. | Produkcijos/local config naudoja `pgsql`, bet automatiniu testu config perjungia i SQLite. | `backend/phpunit.xml`, `backend/config/database.php`, `backend/.env.example` | AUKSTA | Pries pridavima paleisti atskira CI/local profili su PostgreSQL, pvz. `.env.testing.pgsql`, ir ataskaitoje/gynime nemaisyti unit testu su PostgreSQL diegimo patikra. |
| 2 | Nasumo NFR | Pagrindiniai puslapiai turi isikelti <= 3 s, nevertinant pirmojo serverio paleidimo. | Nera realiu performance testu ar matavimo artefaktu repo. | `frontend/src/index.css`, `frontend/package.json`, nera Lighthouse/Playwright perf testu | VIDUTINE | Atlikti rankini Chrome DevTools/Lighthouse matavima demo aplinkoje ir prideti rezultata prie gynimo medziagos. |
| 3 | Responsive NFR | UI turi buti pritaikyta desktop ir mobiliam ekranui. | CSS turi daug breakpointu, bet testai tik komponentiniai, be realios mobile screenshot patikros. | `frontend/src/index.css`, `frontend/src/components/ui/ResponsiveTable.jsx`, frontend testai | VIDUTINE | Pries gynima patikrinti 390px ir 1366px viewportus su narsykle; uzfiksuoti 3-5 screenshotus. |
| 4 | Isorines integracijos demonstravimas | Perenual ir Meteo.lt naudojami realiems duomenims gauti. | Abu klientai yra, bet Perenual priklauso nuo API rakto; Meteo.lt nuo miesto kodo. | `backend/app/Services/Integrations/PerenualService.php`, `MeteoLtClient.php`, `.env.render.example` | VIDUTINE | Demo aplinkoje nustatyti `PERENUAL_API_KEY` ir is anksto tureti viena katalogo irasa is Perenual bei viena miesta, pvz. Vilnius. |
| 5 | DB schemos dokumentacija | 3 priede pateiktas finalinis lenteliu sarasas. | Realiuose migracijose yra papildomos lenteles `has_plot`, `has_inventory`, `used_on`, `personal_access_tokens`, `password_reset_tokens`, kuriu 3 priede nera. | `backend/database/migrations/0001_...015_create_has_plot_table.php`, `0001_...016_create_has_inventory_table.php`, `0001_...017_create_used_on_table.php`, `2026_03_20_112109_create_personal_access_tokens_table.php` | ZEMA | Gynime paaiskinti kaip technines/jungiamasias Laravel lenteles; jei dar galima, dokumentacijoje trumpai pamineti technines lenteles. |
| 6 | Role naming | BPP naudotojo dokumentacija mini neprisijungusi, registruota naudotoja, darzo savininka, bendradarbi, administratoriu. | DB roles apribotos `owner/admin`; bendradarbis realizuotas per `access_rights.role`, ne `users.role`. | `backend/database/migrations/2026_04_03_120000_final_schema_compliance_cleanup.php`, `backend/app/Enums/UserRole.php`, `backend/app/Enums/AccessRole.php` | VIDUTINE | Gynime sakyti: sistemos paskyros roles yra `owner/admin`, o bendradarbio teise yra sklypo prieigos role `viewer/editor`. |

## 3. Funkciniu reikalavimu atsekamumo matrica

| FR | Reikalavimas | Backend irodymai | Frontend irodymai | DB irodymai | Testai | Statusas | Rizika | Taisyti |
|---|---|---|---|---|---|---|---|---|
| 1.1 | Registruotis | `POST /api/register`, `SignUpController@store` | `RegisterPage.jsx`, `AuthContext.register` | `users`, `profiles`, `garden_owners`, `personal_access_tokens` | `AuthenticationTest` | ATITINKA | ZEMA | Nereikia. |
| 1.2 | Prisijungti | `POST /api/login`, `LoginController@store` | `LoginPage.jsx`, `AuthContext.login` | `users`, `personal_access_tokens` | `AuthenticationTest` | ATITINKA | ZEMA | Nereikia. |
| 1.3 | Atsijungti | `POST /api/logout`, `LogoutController@destroy` | `Sidebar.jsx`, `AuthContext.logout` | Sanctum token revoke | `AuthenticationTest` | ATITINKA | ZEMA | Nereikia. |
| 1.4 | Slaptazodzio priminimas | `PasswordResetController@forgot/reset`, `PasswordResetLinkMail` | `ForgotPasswordPage.jsx`, `ResetPasswordPage.jsx` | `password_reset_tokens` | `AuthenticationTest` su `Mail::fake()` | ATITINKA | ZEMA | Demo aplinkoje patikrinti SMTP arba log mailer. |
| 1.5 | Redaguoti paskyros duomenis | `PATCH /api/me`, `AccountController`, `AccountService` | `AccountPage.jsx` | `users`, `profiles` | `AccountProfileTest` | ATITINKA | ZEMA | Nereikia. |
| 1.6 | Admin valdo naudotojus | `/api/admin/users`, `Admin\AccountController`, `AdminMiddleware` | `AdminUsersPage.jsx`, `AdminRoute.jsx` | `users`, `audit_logs` | `AdminTest` | ATITINKA | ZEMA | Nereikia. |
| 1.7 | Valdyti darzo naudotoju prieigas | `ShareController`, `AccessService`, `POST /api/plots/{plot}/share`, `GET /api/plots/{plot}/access` | `PlotSharingPage.jsx`, owner-only nav | `access_rights` su `viewer/editor` | `AccessRightsTest` | ATITINKA | ZEMA | Nereikia. |
| 1.8 | Valdyti sklypo plana | `PlotController`, `SchemeController`, `WorkspaceController` | `PlotCreatePage.jsx`, `PlotDetailPage.jsx`, `PlotDesignerCanvas.jsx` | `plots`, `plant_zones`, `plants`, JSON geometrija | `PlotManagementTest`, `GeometryPersistenceTest`, frontend plot tests | ATITINKA | ZEMA | Nereikia. |
| 1.9 | Eksportuoti plana i PDF | `GET /plots/{plot}/export/pdf`, `ExportController`, `PdfExportService` | `api.downloadPlotPdf`, mygtukas `PlotDetailPage.jsx` | Skaito `plots`, `zones`, `plants` | `PdfExportTest` | ATITINKA | ZEMA | Nereikia. |
| 1.10 | Dalintis sklypo duomenimis | `ShareController`, `CommunityController@plotFeed`, `Plot.share` | `PlotEditPage.jsx` community visibility, `PlotSharingPage.jsx` | `plots.share`, `access_rights`, `community_posts` | `AccessRightsTest`, `CommunityTest` | ATITINKA | ZEMA | Nereikia. |
| 1.11 | Perziureti bendruomene | `GET /api/community`, `CommunityController@index` | `CommunityPage.jsx`, Sidebar | `community_posts` | `CommunityTest` | ATITINKA | ZEMA | Nereikia. |
| 1.12 | Kurti bendruomenes irasus | `POST /api/community`, `CommunityService` | `CommunityPage.jsx` create flow | `community_posts` | `CommunityTest` | ATITINKA | ZEMA | Nereikia. |
| 1.13 | Valdyti augalus | `PlantController` global ir plot-scoped CRUD | `PlantsPage.jsx`, `PlantFormPage.jsx`, `PlantDetailPage.jsx`, `PlotPlantingDrawer.jsx` | `plants`, FK i `plots`, `plant_zones`, `catalog_plants` | `PlantManagementApiTest`, `PlantingSnapshotFlowTest` | ATITINKA | ZEMA | Nereikia. |
| 1.14 | Valdyti augalu kataloga | `CatalogPlantController` CRUD | `CatalogPlantsPage.jsx`, `CatalogPlantFormPage.jsx`, `CatalogPlantDetailPage.jsx` | `catalog_plants`, `plant_care` | `CatalogPlantApiTest`, `PlantCatalogCreateTest` | ATITINKA | ZEMA | Nereikia. |
| 1.15 | Gauti augalu informacija is isorines sistemos | `PerenualService`, `CatalogPlantController@searchPerenual/previewPerenualSpecies` | Perenual paieska `CatalogPlantFormPage.jsx` | Cache/normalizacija i `plant_care` | `PlantSearchTest`, `CatalogPlantApiTest`, `PlantCareLinkingTest` | ATITINKA | VIDUTINE | Uztikrinti `PERENUAL_API_KEY` demo aplinkoje. |
| 1.16 | Gauti oru prognozes | `MeteoLtClient`, `WeatherService`, kalendoriaus generavimas | Oru blokas `PlotCalendarPage.jsx` | `weather_forecasts` | `WeatherFallbackCalendarTest`, `CalendarGenerationTest` | ATITINKA | VIDUTINE | Demo naudoti miesta, kuri Meteo.lt atpazista. |
| 1.17 | Valdyti rekomendacini veiksmu kalendoriu | `CalendarController`, `CalendarGenerationService`, `TaskWorkflowService` | `PlotCalendarPage.jsx` | `task_calendars`, `tasks`, `task_resource_requirements`, `weather_forecasts` | `CalendarGenerationTest`, `TaskTest`, frontend calendar testai | ATITINKA | ZEMA | Nereikia. |
| 1.18 | Pazymeti sunaudotas medziagas | `TaskWorkflowService@complete`, `InventoryService@consumeTaskRequirements/deductMaterialForOwner` | `PlotCalendarPage.jsx` complete flow | `inventory_items`, `inventory_usage_logs`, `task_resource_requirements` | `InventoryCalendarWorkflowTest`, `InventoryManagementTest` | ATITINKA | ZEMA | Nereikia. |
| 1.19 | Identifikuoti augalo bukle | `PlantLifecyclePhaseService`, `PlantLifecycleService`, kalendoriaus `simulated_state` | `PlantDetailPage.jsx` review, `PlotCalendarPage.jsx` lifecycle rendering | `plants.condition`, `plant_care` duration fields | `PlantLifecycleWorkflowTest`, `PlantMonitoringTest` | ATITINKA | ZEMA | Nereikia. |
| 1.20 | Irasyti augalo bukle | `PlantConditionController@store`, `PlantConditionHistoryService@record` | `PlantDetailPage.jsx` condition form | `plant_condition_history`, `plants.condition/disease` | `PlantMonitoringTest` | ATITINKA | ZEMA | Nereikia. |
| 1.21 | Perziureti augalo bukles istorija | `PlantConditionController@index` | `PlantDetailPage.jsx` history list | `plant_condition_history` | `PlantMonitoringTest`, frontend `PlantDetailPage.test.jsx` | ATITINKA | ZEMA | Nereikia. |
| 1.22 | Registruoti derliu | `HarvestController@store`, `HarvestService` | `PlotHarvestsPage.jsx`, task harvest flow | `harvest_records` | `HarvestTest`, `InventoryCalendarWorkflowTest` | ATITINKA | ZEMA | Nereikia. |
| 1.23 | Perziureti derliaus istorija | `HarvestController@index` | `PlotHarvestsPage.jsx`, `PlantDetailPage.jsx` | `harvest_records` | `HarvestTest` | ATITINKA | ZEMA | Nereikia. |
| 1.24 | Pazymeti augalu rotacija | `RotationController`, `RotationPlannerService` | `PlotRotationPage.jsx` | `rotation_history`, `rotation_plan_drafts` | `RotationRecommendationTest`, frontend `PlotRotationPage.test.jsx` | ATITINKA | ZEMA | Nereikia. |
| 1.25 | Perziureti planavimo istorija | `HistoryController`, `PlotSnapshotService@listHistoryForPlot` | `PlotHistoryPage.jsx` | `plot_snapshots` | `PlotWorkspaceCommitTest`, `PlantingSnapshotFlowTest` | ATITINKA | ZEMA | Nereikia. |
| 1.26 | Analizuoti darzo rezultatus | `AnalyticsController`, `AnalyticsService` | `PlotAnalyticsPage.jsx` | Naudoja `plot_snapshots`, `rotation_history`, `condition_history`, `harvest_records`, `tasks` | `AnalyticsTest`, frontend `PlotAnalyticsPage.test.jsx` | ATITINKA | ZEMA | Nereikia. |
| 1.27 | Perziureti inventoriu | `GET /api/inventory`, `InventoryController@index` | `InventoryPage.jsx`, Dashboard | `inventory_items` | `InventoryManagementTest`, frontend `InventoryPage.test.jsx` | ATITINKA | ZEMA | Nereikia. |
| 1.28 | Papildyti inventoriu | `POST /api/inventory`, `StoreInventoryItemRequest` | `InventoryPage.jsx` form | `inventory_items` | `InventoryManagementTest` | ATITINKA | ZEMA | Nereikia. |
| 1.29 | Redaguoti inventoriu | `PATCH /api/inventory/{id}`, `UpdateInventoryItemRequest` | `InventoryPage.jsx` edit flow | `inventory_items` | `InventoryManagementTest` | ATITINKA | ZEMA | Nereikia. |
| 1.30 | Salinti medziagas ir irankius | `DELETE /api/inventory/{id}` | `InventoryPage.jsx` delete action | `inventory_items` | `InventoryManagementTest` | ATITINKA | ZEMA | Nereikia. |

Pastaba: FR lentele vertina funkcines savybes pagal realu koda, todel visi FR turi backend/frontend/DB pagrinda. Daline atitiktis keliama ne FR lygyje, o NFR/testu/irodomumo dalyse: nasumas, responsive patikra, PostgreSQL testu aplinka ir isoriniu API demonstravimas.

## 4. Panaudojimo atveju atitiktis

| PA | Aprasytas scenarijus | Realus naudotojo kelias | API / servisas | Statusas | Pastabos |
|---|---|---|---|---|---|
| Registruotis | Forma, validacija, unikalus el. pastas, paskyros sukurimas. | `/register` | `POST /api/register` | ATITINKA | Galima demonstruoti su nauju el. pastu. |
| Prisijungti | Ivesti credentials, gauti prieiga prie sistemos. | `/login` | `LoginController`, Sanctum token | ATITINKA | Token saugomas SPA auth kontekste. |
| Atsijungti | Baigti sesija/token. | Sidebar logout | `POST /api/logout` | ATITINKA | Aiskus kelias. |
| Priminti slaptazodi | El. pastas, reset token, naujas slaptazodis. | `/forgot-password`, `/reset-password` | `PasswordResetController`, mail boundary | ATITINKA | Demo priklauso nuo mailer/log. |
| Redaguoti paskyra | Keisti varda/pavarde/email/slaptazodi. | `/account` | `PATCH /api/me` | ATITINKA | Testuota unikalaus email klaida. |
| Administruoti naudotojus | Sarasas, perziura, roles keitimas, salinimas. | `/admin/users` | `/api/admin/users`, `AdminService` | ATITINKA | Tik adminui per `AdminRoute` ir middleware. |
| Valdyti darzo naudotoju prieigas | Suteikti/perziureti/atsaukti viewer/editor. | `/plots/:id/sharing` | `ShareController`, `AccessService` | ATITINKA | Bendradarbio role yra plot-level, ne `users.role`. |
| Valdyti sklypo plana | Kurti/redaguoti ribas, zonas, augalus, saugoti. | `/plots/new`, `/plots/:id` | `PlotController`, `WorkspaceController`, `SchemeController` | ATITINKA | Geometrija saugoma, backend jos neinterpretuoja. |
| Eksportuoti plana i PDF | Generuoti ir atsisiusti PDF. | PDF mygtukas sklypo workspace | `GET /api/plots/{plot}/export/pdf` | ATITINKA | Naudoja dompdf. |
| Dalintis sklypo duomenimis | Bendrinimas su kitais arba community visibility. | Sharing ir plot metadata | `ShareController`, `CommunityController` | ATITINKA | BPP turi du artimus dalijimosi kontekstus; kode abu yra. |
| Perziureti bendruomene | Matyti bendruomenes irasus. | `/community` | `GET /api/community` | ATITINKA | Protected route. |
| Kurti bendruomenes irasus | Sukurti irasa. | `/community` forma | `POST /api/community` | ATITINKA | Yra update/delete ir autoriaus kontrole. |
| Valdyti augalus | CRUD augalams sklype/globaliai. | `/plants`, `/plots/:id/plants/:plantId` | `PlantController` | ATITINKA | Care override draudziamas, laikomasi reusable `plant_care`. |
| Valdyti augalu kataloga | Paieska, kurimas, redagavimas, salinimas. | `/catalog-plants` | `CatalogPlantController`, `CatalogPlantService` | ATITINKA | Rankinis ir Perenual draft keliai. |
| Gauti augalu informacija | Perenual + fallback/normalizacija. | Catalog form Perenual search | `PerenualService`, `PlantCareNormalizer` | ATITINKA | API raktas butinas live demonstracijai. |
| Gauti oru prognozes | Meteo.lt + stored fallback. | Kalendoriaus generavimas/oru blokas | `WeatherService`, `MeteoLtClient` | ATITINKA | Stored/seasonal fallback aiskiai realizuotas. |
| Valdyti rekomendacini kalendoriu | Generuoti, perziureti, complete/reject. | `/plots/:id/calendar` | `CalendarGenerationService`, `TaskWorkflowService` | ATITINKA | Pagrindine verte realizuota stipriai. |
| Pazymeti sunaudotas medziagas | Uzduoties complete sumazina likucius. | Calendar complete modal/flow | `InventoryService` | ATITINKA | Neleidzia neigiamo likucio. |
| Identifikuoti augalo bukle | Pagal pasodinimo data ir prieziuros trukmes. | Augalo detail/review/calendar | `PlantLifecyclePhaseService` | ATITINKA | Bukles simuliacija yra server-side. |
| Irasyti augalo bukle | Irasyti condition history. | `PlantDetailPage` forma | `PlantConditionHistoryService` | ATITINKA | Atnaujina ir plant current condition. |
| Perziureti bukles istorija | Sarasas pagal augala. | `PlantDetailPage` | `PlantConditionController@index` | ATITINKA | Blogas atvejis rodomas EmptyState. |
| Registruoti derliu | Ivesti kieki/data/augala. | `/plots/:id/harvests` | `HarvestController`, `HarvestService` | ATITINKA | Susieta su augalu ir sklypu. |
| Perziureti derliaus istorija | Sarasas / plant detail. | `PlotHarvestsPage`, `PlantDetailPage` | `HarvestController@index` | ATITINKA | Duomenys naudojami analitikoje. |
| Pazymeti augalu rotacija | Generuoti juodrasti, koreguoti, patvirtinti. | `/plots/:id/rotation` | `RotationController`, `RotationPlannerService` | ATITINKA | Yra unresolved draft scenarijai. |
| Perziureti planavimo istorija | Rodyti snapshots. | `/plots/:id/history` | `HistoryController` | ATITINKA | Istorija priklauso nuo explicit save. |
| Analizuoti darzo rezultatus | Planning/condition/harvest analizes. | `/plots/:id/analytics` | `AnalyticsService` | ATITINKA | Naudoja realias sukauptas lenteles. |
| Perziureti inventoriu | Sarasas. | `/inventory` | `InventoryController@index` | ATITINKA | Dashboard taip pat rodo summary. |
| Papildyti inventoriu | Sukurti/Restock. | `/inventory` forma | `InventoryService@createForOwner` | ATITINKA | Gali ateiti is shortage task konteksto. |
| Redaguoti inventoriu | Keisti item. | `/inventory` edit | `InventoryService@updateForOwner` | ATITINKA | Validuoja kieki ir tipa. |
| Salinti medziagas/irankius | Patvirtinti ir salinti. | `/inventory` delete | `InventoryService@deleteForOwner` | ATITINKA | Reikia demo patvirtinimo kelio parodymo. |

## 5. DB schemos ir migraciju atitiktis

| Lentele | Rasto darbe | Migracijose | Modelyje | Rysiai | Statusas | Pastabos |
|---|---|---|---|---|---|---|
| `users` | Yra | `0001_...create_users`, role check `owner/admin` | `User` | `profile`, `gardenOwner`, Sanctum tokens | ATITINKA | Testai naudoja SQLite, bet produkcija pgsql. |
| `profiles` | Yra | `0001_...create_profiles`, velesnis `user_id` | `Profile` | `user`, `gardenOwner`, access links | ATITINKA | Atitinka paskyros/profilio skaidyma. |
| `garden_owners` | Yra | `0001_...create_garden_owners` | `GardenOwner` | `user`, `profile`, `ownedPlots`, inventory | ATITINKA | Senas `id_user` ir naujas `user_id` koegzistuoja del suderinamumo. |
| `plots` | Yra | `create_plots`, geometry JSONB, owner FK | `Plot` | zones, plants, snapshots, access, calendars | ATITINKA | JSON geometry pagal BPP. |
| `plant_zones` | Yra | `create_plant_zones`, geometry JSONB | `PlantZone` | plot, plants, rotation, tasks via `used_on` | ATITINKA | Backend tik saugo geometrija. |
| `plot_snapshots` | Yra | `2026_04_02...create plot_snapshots` | `PlotSnapshot` | plot, owner | ATITINKA | Naudojama planavimo istorijai. |
| `catalog_plants` | Yra | `2026_04_08...create_catalog_plants` | `CatalogPlant` | `plantCare`, `plants` | ATITINKA | Katalogas susietas su reusable care. |
| `plants` | Yra | `create_plants`, catalog FK, care override column | `Plant` | plot, zone, catalog, condition, tasks, harvest | ATITINKA | `fk_plant_care_id` pasalintas kaip redundant, care gaunamas per catalog. Tai atitinka "ne dubliuoti care logic", bet skiriasi nuo ankstyvos spec formuluotes. |
| `plant_care` | Yra | `create_plant_care` + threshold/metadata migrations | `PlantCare` | catalog plants, plants through catalog | ATITINKA | Reusable knowledge base. |
| `plant_condition_history` | Yra | `create_plant_condition_history` + aligned FK | `PlantConditionHistory` | plant | ATITINKA | Saugo condition ir condition_type. |
| `task_calendars` | Yra | `create_task_calendars` + remove plant_care FK | `TaskCalendar` | plot, tasks, weather | ATITINKA | Kalendorius susietas su plot. |
| `tasks` | Yra | `create_tasks` + smart metadata | `Task` | calendar, plant, zone, requirements, usage logs | ATITINKA | `state/status`, weather/inventory/simulated context. |
| `task_resource_requirements` | Yra | `2026_04_17...create task_resource_requirements` | `TaskResourceRequirement` | task, usage logs | ATITINKA | Butina inventory-aware calendar logikai. |
| `weather_forecasts` | Yra | `create_weather_forecasts` + source metadata | `WeatherForecast` | taskCalendar | ATITINKA | Turi fallback metadata. |
| `inventory_items` | Yra | `create_inventory_items` + refactor columns | `InventoryItem` | owner, usage logs | ATITINKA | Type check `material/tool`. |
| `inventory_usage_logs` | Yra | `2026_04_17...create inventory_usage_logs` | `InventoryUsageLog` | item, task, requirement, owner | ATITINKA | Pakeite BPP senesni "used_on/sunaudota" semantini poreiki. |
| `harvest_records` | Yra | `2026_04_03...create_harvest_records` | `HarvestRecord` | plot, plant, task, owner | ATITINKA | Derlius susietas su augalais/sklypais. |
| `rotation_history` | Yra | `create_rotation_history` + zone snapshots | `RotationHistory` | plot, zone, plant | ATITINKA | Turi from/to zone snapshot laukus. |
| `rotation_plan_drafts` | Yra | `2026_04_20...create_rotation_plan_drafts` | `RotationPlanDraft` | plot, owner | ATITINKA | Rotacijos juodrasciu workflow. |
| `access_rights` | Yra | `create_access_rights`, role check `viewer/editor` | `AccessRight` | plot, grantor, recipient | ATITINKA | Enforced via service ir middleware. |
| `community_posts` | Yra | `create_community_posts` | `CommunityPost` | owner/profile/plot | ATITINKA | Bendruomenes irasai. |
| `audit_logs` | Yra | `2026_04_10...create_audit_logs` | `AuditLog` | admin, target user | ATITINKA | Admin veiksmu pedsakas. |
| `has_plot` | 3 priede nera | Yra | `HasPlot` | plot-owner junction | DALINAI ATITINKA | Technine/sena N:M lentele. Gynime aiskinti kaip suderinamumo artefakta. |
| `has_inventory` | 3 priede nera | Yra | `HasInventory` | inventory-owner junction | DALINAI ATITINKA | Dabar pagrindinis ownership per `garden_owner_id`. |
| `used_on` | 3 priede nera | Yra | `UsedOn` | task-zone junction | DALINAI ATITINKA | Panasi i uzduoties taikymo zonoms technine lentele. |
| `personal_access_tokens` | Technine | Yra | Sanctum | tokenable | ATITINKA | Laravel Sanctum. |
| `password_reset_tokens` | Technine | Yra | Password broker | email/token | ATITINKA | Password reset flow. |

## 6. Architekturos, komponentu ir diegimo atitiktis

| Teiginys is BPP | Kodo / konfiguracijos irodymai | Statusas | Rizika |
|---|---|---|---|
| React SPA naudotojo sasajos sluoksnis | `frontend/src/main.jsx`, `frontend/src/App.jsx`, `frontend/package.json` | ATITINKA | ZEMA |
| Laravel REST JSON API serverio sluoksnis | `backend/routes/api.php`, controllers po `backend/app/Http/Controllers` | ATITINKA | ZEMA |
| Services sluoksnis sudetingai logikai | `CalendarGenerationService`, `InventoryService`, `AnalyticsService`, `RotationPlannerService`, `PlantCareService` | ATITINKA | ZEMA |
| Eloquent modeliai kaip duomenu sluoksnis | `backend/app/Models/*.php` relationships | ATITINKA | ZEMA |
| PostgreSQL DB | `backend/.env.example DB_CONNECTION=pgsql`, `Dockerfile pdo_pgsql`, `render.yaml` | ATITINKA | VIDUTINE del testu SQLite |
| Laravel Sanctum auth | `laravel/sanctum`, `personal_access_tokens`, `auth:sanctum` route group | ATITINKA | ZEMA |
| Perenual integracija | `PerenualService`, `CatalogPlantController` | ATITINKA | VIDUTINE, jei nera API rakto |
| Meteo.lt integracija | `MeteoLtClient`, `WeatherService`, `METEO_LT_BASE_URL` | ATITINKA | ZEMA |
| PDF generavimas | `dompdf/dompdf`, `PdfExportService`, `resources/views/pdf/plot-report.blade.php` | ATITINKA | ZEMA |
| Render Docker Web Service su Nginx/PHP-FPM | `Dockerfile`, `docker/start.sh`, `docker/nginx.conf.template`, `render.yaml` | ATITINKA | ZEMA |
| Mail server password reset | `PasswordResetController`, `EmailServerBoundary`, `.env.render.example MAIL_*` | ATITINKA | VIDUTINE, jei SMTP nenustatytas |

## 7. Isoriniu integraciju atitiktis

| Integracija | Rasto darbe aprasyta | Kode realizuota | Klaidos / fallback | Statusas |
|---|---|---|---|---|
| Meteo.lt | Gauti prognozes is Meteo.lt, issaugoti, fallback i saugomus duomenis. | `MeteoLtClient` kviecia `/places` ir `/forecasts/long-term`; `WeatherService` agreguoja dienomis. | Live klaidos loguojamos, naudojami `stored_city_date`, `stored_other_city_date`, `seasonal`. | ATITINKA |
| Perenual | Gauti augalu informacija ir normalizuoti i kataloga/prieziura. | `PerenualService` kviecia species-list, species/details, species-care-guide-list; `CatalogPlantService` kuria draft/care. | Cache per `Cache::remember`; trukstant API duomenu normalizer naudoja default/fallback. | ATITINKA |
| Email server | Slaptazodzio atkurimas. | `EmailServerBoundary`, `PasswordResetLinkMail`, Laravel mail config. | Testuose `Mail::fake`; demo reikia SMTP arba log mailer. | DALINAI ATITINKA demonstravimo prasme |
| OpenWeatherMap likuciai | Neturi buti, nes BPP naudoja Meteo.lt. | `rg` pagal OpenWeather rado tik audito uzklausu konteksta, ne kodine integracija. | Netaikoma. | ATITINKA |
| Reverse geocode / Nominatim | BPP pagrindiniame integraciju sarase neakcentuota. | `ReverseGeocodingService`, `ReverseGeocodeController`, `NOMINATIM_*`. | Papildoma funkcija, ne konfliktas. | ATITINKA su zema dokumentacijos rizika |

## 8. Nefunkciniu reikalavimu atitiktis

| NFR | Reikalavimas | Kodo irodymai | Patikros budas | Statusas | Rizika |
|---|---|---|---|---|---|
| 2.1 / NFR1 | Patikimai saugoti duomenis DB ir islaikyti po perkrovimo. | PostgreSQL config, FK migracijos, `RefreshDatabase` testai, CRUD services. | Reikia paleisti PostgreSQL migracijas ir smoke testa. | ATITINKA | ZEMA |
| 2.2 / NFR2 | Pagrindiniai puslapiai <= 3 s po cold start. | Vite build, React SPA, API endpoints. Nera perf matavimo. | Chrome DevTools/Lighthouse demo aplinkoje. | DALINAI ATITINKA | VIDUTINE |
| 2.3 / NFR3 | Desktop ir mobile responsive UI. | `frontend/src/index.css` turi daug `@media`, responsive table/list komponentus. | Reikia realiu viewport screenshotu. | DALINAI ATITINKA | VIDUTINE |
| 2.4 / NFR4 | Protected routes ir authorization pagal teises. | `ProtectedRoute`, `AdminRoute`, `auth:sanctum`, `AdminMiddleware`, `AccessService`, `AuthorizesPlotAccess`. | Feature tests + rankinis bandymas su viewer/editor. | ATITINKA | ZEMA |
| 2.5 / NFR5 | Aiskus success/error/validation pranesimai. | `StatusView.jsx`, `Toast`, API error normalizer, request validation messages. | UI rankinis testas. | ATITINKA | ZEMA |
| 2.6 / NFR6 | Server-side ir frontend validacija. | Laravel FormRequests, controller validation, frontend form required/min/step. | Automatiniai validation feature tests + rankinis UI. | ATITINKA | ZEMA |

## 9. Testavimo skyriaus patikimumas

| Testavimo teiginys | Ar galima realiai patikrinti | Kodo / UI irodymai | Statusas | Pastabos |
|---|---|---|---|---|
| 3.1-3.2 visi PA scenarijai | Taip | 38 backend testu failai, 16 frontend testu failu, 359 test/assert/test-case irasu | ATITINKA | Audito metu nepaleista. |
| 3.3 paskyros valdymas | Taip | `AuthenticationTest`, `AccountProfileTest`, `AdminTest` | ATITINKA | Password reset testuoja mail fake. |
| 3.4 sklypo plano valdymas | Taip | `PlotManagementTest`, `PlotWorkspaceCommitTest`, `GeometryPersistenceTest`, frontend plot tests | ATITINKA | UI canvas patikrintas komponentiskai. |
| 3.5 augalai, katalogas, bukle | Taip | `PlantManagementApiTest`, `CatalogPlantApiTest`, `PlantMonitoringTest`, `PlantLifecycleWorkflowTest` | ATITINKA | Perenual testai fakeina HTTP. |
| 3.6 derlius | Taip | `HarvestTest`, `PlotHarvestsPage.jsx` | ATITINKA | Realus DB modelis. |
| 3.7 rekomendacinis kalendorius | Taip | `CalendarGenerationTest`, `WeatherFallbackCalendarTest`, `InventoryCalendarWorkflowTest` | ATITINKA | Viena stipriausiu sriciu. |
| 3.8 sunaudotos medziagos | Taip | `TaskWorkflowService`, `InventoryService`, `InventoryCalendarWorkflowTest` | ATITINKA | Neigiamas likutis blokuojamas. |
| 3.9 inventorius CRUD | Taip | `InventoryManagementTest`, `InventoryPage.test.jsx` | ATITINKA | Validuoja quantity min ir tool integer. |
| 3.10 rotacija, istorija, analize | Taip | `RotationRecommendationTest`, `PlotWorkspaceCommitTest`, `AnalyticsTest` | ATITINKA | Reikia demo duomenu su istorija. |
| 3.11 sklypo dalijimasis, bendruomene | Taip | `AccessRightsTest`, `CommunityTest` | ATITINKA | Viewer/editor scenarijai patikrinti. |
| 3.12 community post kurimas | Taip | `CommunityTest`, `CommunityPage.jsx` | ATITINKA | Autoriaus update/delete kontrole. |
| 3.13 PDF eksportas | Taip | `PdfExportTest`, `ExportController` | ATITINKA | Reikia rankinio PDF atsidarymo pries gynima. |
| 3.14 validacijos testavimas | Taip | FormRequests, feature tests | ATITINKA | Ne visos formos turi identiska frontend klaidu testa. |
| 3.15 prieigos kontrole neautentifikuotam | Taip | `ProtectedRoute`, `auth:sanctum`, feature tests | ATITINKA | Reikia rankinio UI redirect parodymo. |
| 3.16 matyti tik savo/prieinamus duomenis | Taip | `AccessService::accessiblePlotIds`, `InventoryService::queryForOwner` | ATITINKA | Admin global scope atskiras. |
| 3.17 duomenu islikimas | Taip | `RefreshDatabase` CRUD assertions | ATITINKA | Realus perkrovimas testuojamas daugiausia per API/DB, ne E2E browser. |
| 3.18 desktop/mobile UI | Galima, bet siame audite nematuota | CSS/media queries | DALINAI ATITINKA | Reikia realiu screenshotu. |
| 3.19 ikelimo laikas | Galima, bet siame audite nematuota | Nera perf artefaktu | DALINAI ATITINKA | Butina rankine metrika. |
| 3.20 NFR testavimas | Is dalies | Saugumas/validacija testuoti, perf/mobile neautomatizuota | DALINAI ATITINKA | Pildyti demonstravimo irodymu. |
| 3.21 testiniai duomenys | Taip | `CurrentVersionDemoSeeder`, `FullFlowDemoAccountSeeder`, `DemoDataSeeder` | ATITINKA | Demo seederiai naudingi gynimui. |

## 10. Naudotojo dokumentacijos atitiktis

| Dokumentacijos veiksmas | UI realybe | Statusas | Pastabos |
|---|---|---|---|
| Registracija / prisijungimas / atsijungimas | `/register`, `/login`, sidebar logout | ATITINKA | Guest taip pat mato pradini dashboard su login/register nuorodomis. |
| Slaptazodzio priminimas | `/forgot-password`, `/reset-password` | ATITINKA | Demo priklauso nuo mailer. |
| Paskyros duomenys | `/account` | ATITINKA | Protected route. |
| Sklypu sarasas ir sklypo kurimas | `/plots`, `/plots/new` | ATITINKA | Sidebar ir dashboard nuorodos. |
| Sklypo plano redagavimas, zonos, augalai | `/plots/:id`, canvas, planting drawer | ATITINKA | Reikia pademonstruoti explicit Save. |
| PDF eksportas | `PlotDetailPage` PDF mygtukas | ATITINKA | Failas atsisiunciamas per blob. |
| Augalu katalogas | `/catalog-plants` | ATITINKA | Formoje yra Perenual import. |
| Augalo bukle ir istorija | `PlantDetailPage` | ATITINKA | Turi condition form ir history sarasa. |
| Rekomendacinis kalendorius | `/plots/:id/calendar` | ATITINKA | Rodo orus, resursus, task complete/reject. |
| Inventorius | `/inventory` | ATITINKA | CRUD ir restock kontekstas. |
| Rotacija | `/plots/:id/rotation` | ATITINKA | Draft/edit/confirm. |
| Planavimo istorija | `/plots/:id/history` | ATITINKA | Tik saved snapshots. |
| Derlius | `/plots/:id/harvests` ir `PlantDetailPage` | ATITINKA | Registravimas ir istorija. |
| Analitika | `/plots/:id/analytics` | ATITINKA | Planning, condition, harvest sekcijos. |
| Dalijimasis | `/plots/:id/sharing` | ATITINKA | Owner-only nav. |
| Bendruomene | `/community` | ATITINKA | Irasai ir plot feed. |
| Administratorius | `/admin/users` | ATITINKA | Tik admin route. |
| Bendradarbis kaip naudotojo tipas | UI rodo shared access role, ne atskira account role | DALINAI ATITINKA | Paaiskinti, kad bendradarbis = sklypo prieigos busena. |

## 11. Prioritetinis taisymo planas

### Privaloma sutvarkyti pries pridavima

1. PostgreSQL testine patikra  
Failai: `backend/phpunit.xml`, galimai naujas `.env.testing.pgsql` arba lokali CI instrukcija.  
Ka keisti: nebutinai keisti esama PHPUnit default, bet paleisti `php artisan migrate --env=testing` pries PostgreSQL testine DB ir bent pagrindinius feature testus.  
Tipas: patikros/demonstravimo rizika.  
Poveikis: sumazina didziausia recenzijos rizika del "PostgreSQL tikrai veikia<=" klausimo.

2. Nasumo ir mobile irodymai  
Failai: nereikia kodo; galima prideti atskira demonstravimo pastaba arba screenshotus ne siame audite.  
Ka keisti: Chrome DevTools/Lighthouse pamatuoti login, plots, calendar, catalog, inventory po warm start; padaryti mobile viewport perziura.  
Tipas: dokumentacijos/irodymu rizika.  
Poveikis: uzdaro NFR2/NFR3 spragas.

3. Demo aplinkos env  
Failai: Render dashboard, `.env.render.example`, `DEPLOY_RENDER.md`.  
Ka keisti: nustatyti `APP_KEY`, `DATABASE_URL`, `PERENUAL_API_KEY`, `METEO_LT_BASE_URL`, mailer parametrus arba aiskiai naudoti log mailer demo metu.  
Tipas: konfiguracija.  
Poveikis: leidzia parodyti Perenual, Meteo.lt ir password reset.

4. Demo duomenys  
Failai: `backend/database/seeders/CurrentVersionDemoSeeder.php`, `FullFlowDemoAccountSeeder.php`, `DEMO_ACCOUNT.md`.  
Ka keisti: uztikrinti, kad demo turi sklypa su zonomis, augalais, `plant_care`, inventory shortages, weather forecasts, condition history, harvest, rotation, snapshots, community.  
Tipas: duomenu paruosimas.  
Poveikis: leidzia pademonstruoti visus PA be improvizacijos.

### Gerai butu sutvarkyti

1. Paaiskinti papildomas technines lenteles  
Failai: jei leidziama, BPP 3 priedo arba atskiros gynimo pastabos; kode keisti nereikia.  
Ka keisti: pamineti `personal_access_tokens`, `password_reset_tokens`, `has_plot`, `has_inventory`, `used_on` kaip technines / suderinamumo / junction lenteles.  
Tipas: dokumentacijos rizika.  
Poveikis: maziau klausimu del DB schemos.

2. Role terminu suderinimas  
Failai: naudotojo dokumentacija arba gynimo kalbejimo tekstas.  
Ka keisti: aiskiai atskirti paskyros roles `owner/admin` nuo sklypo prieigos `viewer/editor`.  
Tipas: dokumentacijos/naming rizika.  
Poveikis: isvengiama klausimo, ar "bendradarbis" yra trecia sistemos role.

3. PDF rankinis atidarymas  
Failai: nereikia kodo.  
Ka keisti: pries gynima sugeneruoti viena PDF ir patikrinti, ar jame matosi planas, zonos, augalai, inventorius/istorija pagal BPP lukescius.  
Tipas: demonstravimo rizika.  
Poveikis: PDF eksportas tampa lengvai irodomas.

### Galima palikti, jei nebus laiko

1. Reverse geocoding dokumentacijos skirtumas  
Failai: `ReverseGeocodingService`, `ReverseGeocodeController`, `PlotLocationMap.jsx`.  
Ka keisti: nieko, tai papildoma funkcija.  
Tipas: zema dokumentacijos rizika.  
Poveikis: neturi konfliktuoti su BPP.

2. Senu FK lauku ir nauju alias lauku koegzistavimas  
Failai: migracijos su `fk_*` ir naujais `*_id` laukais, modeliu boot hooks.  
Ka keisti: neliesti pries pridavima, jei migracijos veikia.  
Tipas: technine skolos rizika.  
Poveikis: refaktorius pries pridavima butu rizikingesnis nei palikimas.

## 12. Galutinis verdiktas

Sistema pakankamai atitinka rasto darba ir yra gerai pritempta prie BPP PDF aprasytos architekturos bei funkciniu scenariju. Realus backend route'ai, servisai, migracijos, modeliai, React puslapiai ir testai dengia visas pagrindines funkcines sritis: paskyras, sklypus, zonas, augalus, kataloga, rekomendacini kalendoriu, inventoriu, bukle, derliu, rotacija, istorija, analize, bendruomene, prieigas ir PDF eksporta.

Didziausios 3-5 rizikos:

1. Testavimo ir PostgreSQL irodomumas, nes PHPUnit default naudoja SQLite.
2. NFR nasumo ir responsive irodymai, nes nera realiu matavimo artefaktu.
3. Isoriniu API demonstravimas, ypac Perenual raktas ir Meteo.lt miesto atpazinimas.
4. Terminu rizika del "bendradarbio" ir papildomu techniniu lenteliu.
5. Gynimo scenarijus turi buti paremtas paruostais duomenimis, nes kalendorius/analitika be istorijos ir inventory duomenu atrodys silpniau.

Jei liko mazai laiko, pirmiausia: paruosti demo DB su `CurrentVersionDemoSeeder`, patikrinti Render env, paleisti PostgreSQL migracijas/testu subseta saugioje aplinkoje, sugeneruoti viena PDF ir uzfiksuoti login/plots/calendar/catalog/inventory puslapiu ikelimo laika bei mobile screenshotus.
