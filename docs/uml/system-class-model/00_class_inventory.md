# Sistemos klasių inventorius

Šis inventorius sudarytas iš dabartinės repozitorijos failų, o ne iš senų diagramų. Analizuoti šaltiniai:

- `backend/app/Http/Controllers`
- `backend/app/Services`
- `backend/app/Models`
- `backend/app/Http/Requests`
- `backend/app/Http/Resources`
- `backend/routes/api.php`, `backend/routes/web.php`
- `backend/database/migrations`
- `frontend/src/App.jsx`, `frontend/src/pages`, `frontend/src/components/plot`, `frontend/src/lib/api.js`

Nerasta `backend/app/Policies`, `backend/app/Jobs`, `backend/app/Console`, `backend/app/Observers` PHP klasių, todėl jos į diagramas neįtrauktos.

## Posistemės

| Posistemė | Rastos klasės / komponentai | Įtraukimas į diagramas |
|---|---|---|
| Autentifikavimas, naudotojai ir prieigos teisės | `LoginPage`, `RegisterPage`, `ForgotPasswordPage`, `ResetPasswordPage`, `AccountPage`, `AdminUsersPage`, `AuthProvider`, `ProtectedRoute`, `AdminRoute`, `SignUpController`, `LoginController`, `LogoutController`, `PasswordResetController`, `CurrentUserController`, `User\AccountController`, `Admin\AccountController`, `AuditLogController`, `AdminMiddleware`, `AccountService`, `AdminService`, `EmailServerBoundary`, `AccessService`, `User`, `Profile`, `GardenOwner`, `AccessRight`, `AuditLog` | Įtraukta į `02_auth_access_class_model.puml`; prieigos dalis kartojama ten, kur ji būtina kitoms posistemėms. |
| Sklypo plano redaktorius, ribos ir zonos | `PlotsPage`, `PlotCreatePage`, `PlotDetailPage`, `PlotEditPage`, `PlotDesignerCanvas`, `PlotLocationMap`, `PlotPlantingDrawer`, `PlotController`, `SchemeController`, `WorkspaceController`, `ReverseGeocodeController`, `PlotWorkspaceService`, `PlotSnapshotService`, `ReverseGeocodingService`, `AccessService`, `Plot`, `PlantZone`, `Plant`, `PlotSnapshot` | Įtraukta į `03_plot_editor_class_model.puml`. |
| Augalų valdymas ir katalogas | `PlantsPage`, `PlantFormPage`, `PlantDetailPage`, `CatalogPlantsPage`, `CatalogPlantFormPage`, `CatalogPlantDetailPage`, `PlantController`, `CatalogPlantController`, `CatalogPlantService`, `PlantCareService`, `PlantCareNormalizer`, `PlantCareDefaults`, `PerenualService`, `Plant`, `CatalogPlant`, `PlantCare` | Įtraukta į `04_plant_catalog_class_model.puml`. |
| Augalo būklės istorija | `PlantDetailPage`, `PlantConditionController`, `ConditionHistoryController`, `PlantConditionHistoryService`, `PlantLifecycleService`, `PlantLifecyclePhaseService`, `PlantStateService`, `Plant`, `PlantCare`, `PlantConditionHistory`, `HarvestRecord`, `Task` | Įtraukta į `05_plant_condition_class_model.puml`. `ConditionHistoryController` egzistuoja, bet tik paveldi `PlantConditionController`; atskiro maršruto nerasta. |
| Rekomendacinis kalendorius ir Meteo.lt orai | `PlotCalendarPage`, `CalendarController`, `TaskController`, `TaskCalendarService`, `CalendarGenerationService`, `TaskWorkflowService`, `WeatherService`, `MeteoLtClient`, `TaskInventoryCoverageService`, `InventoryService`, `PlantLifecycleService`, `TaskCalendar`, `Task`, `TaskResourceRequirement`, `WeatherForecast`, `Plot`, `Plant`, `PlantCare` | Įtraukta į `06_calendar_weather_class_model.puml`. |
| Inventorius ir sunaudotos medžiagos | `InventoryPage`, `InventoryController`, `InventoryService`, `TaskInventoryCoverageService`, `InventoryPlanningRepairService`, `InventoryItem`, `InventoryUsageLog`, `TaskResourceRequirement`, `Task`, `GardenOwner` | Įtraukta į `07_inventory_class_model.puml`. |
| Sėjomaina ir planavimo istorija | `PlotRotationPage`, `PlotHistoryPage`, `RotationController`, `HistoryController`, `RotationPlannerService`, `CropRotationClassifier`, `PlotSnapshotService`, `RotationHistory`, `RotationPlanDraft`, `PlotSnapshot`, `Plot`, `PlantZone`, `Plant` | Įtraukta į `08_rotation_history_class_model.puml`. |
| Derlius ir analitika | `PlotHarvestsPage`, `PlotAnalyticsPage`, `HarvestController`, `AnalyticsController`, `HarvestService`, `AnalyticsService`, `HarvestRecord`, `Plot`, `Plant`, `Task`, `PlantConditionHistory`, `RotationHistory`, `PlotSnapshot` | Įtraukta į `09_harvest_analytics_class_model.puml`. |
| Bendruomenė | `CommunityPage`, `CommunityController`, `CommunityService`, `CommunityPost`, `Plot`, `PlantZone`, `GardenOwner`, `Profile`, `AccessService` | Įtraukta į `10_community_class_model.puml`. |
| Sklypo PDF eksportas | `PlotDetailPage`, `ExportController`, `PdfExportService`, `AnalyticsService`, `Plot`, `PlantZone`, `Plant`, `TaskCalendar`, `Task`, `RotationHistory`, `PlantConditionHistory`, `resources/views/pdf/plot-report.blade.php`, `Dompdf` | Įtraukta į `11_pdf_export_class_model.puml`. |

## Modeliai ir ryšiai

| Modelis | Pagrindiniai atributai iš `$fillable` / `casts` | Ryšiai iš Eloquent metodų |
|---|---|---|
| `User` | `email`, `password`, `reset_code`, `role` | `gardenOwner(): HasOne`, `profile(): HasOne`, `grantedAccessRights(): HasMany`, `receivedAccessRights(): HasMany`, `communityPosts(): HasMany` |
| `Profile` | `user_id`, `name`, `surname`, `last_login` | `user(): BelongsTo`, `gardenOwner(): HasOne`, `grantedAccessRights(): HasMany`, `receivedAccessRights(): HasMany`, `communityPosts(): HasMany`, `plotLinks(): HasMany`, `inventoryLinks(): HasMany` |
| `GardenOwner` | `id`, `user_id`, `id_user`, `fk_profile_id` | `user(): BelongsTo`, `profile(): BelongsTo`, `ownedPlots(): HasMany`, `ownedInventoryItems(): HasMany`, `grantedAccessRights(): HasMany`, `receivedAccessRights(): HasMany`, `communityPosts(): HasMany`, `harvestRecords(): HasMany`, `plotSnapshots(): HasMany` |
| `AccessRight` | `granted_at`, `role`, `garden_owner_id`, `plot_id`, `fk_plot_id`, grantor/recipient FK laukai | `plot(): BelongsTo`, `grantor(): BelongsTo`, `recipient(): BelongsTo`, `grantorGardenOwner(): BelongsTo`, `recipientGardenOwner(): BelongsTo`, `grantorUser(): BelongsTo`, `recipientUser(): BelongsTo` |
| `AuditLog` | `admin_user_id`, `action`, `target_user_id`, `context`, `created_at` | `admin(): BelongsTo`, `targetUser(): BelongsTo` |
| `Plot` | `garden_owner_id`, `name`, `city`, `plot_size`, `creation_date`, `description`, `share`, `geometry` | `plantZones(): HasMany`, `plants(): HasMany`, `rotationHistory(): HasMany`, `rotationPlanDrafts(): HasMany`, `taskCalendars(): HasMany`, `harvestRecords(): HasMany`, `snapshots(): HasMany`, `accessRights(): HasMany`, `communityPosts(): HasMany`, `gardenOwner(): BelongsTo` |
| `PlantZone` | `plot_id`, `name`, `zone_size`, `soil_type`, `rotation_stage`, `last_planting_date`, `geometry` | `plot(): BelongsTo`, `plants(): HasMany`, `rotationHistory(): HasMany`, `tasks(): BelongsTo`, `usedOn(): HasMany` |
| `Plant` | `name`, `plant_date`, `disease`, `disease_notes`, `plant_size`, `photo_url`, `reusable`, `type`, `condition`, `fk_catalog_plant_id`, `plant_zone_id`, `fk_plot_id` | `plantZone(): BelongsTo`, `plot(): BelongsTo`, `catalogPlant(): BelongsTo`, `effectivePlantCare()`, `conditionHistory(): HasMany`, `rotationHistory(): HasMany`, `tasks(): HasMany`, `harvestRecords(): HasMany` |
| `CatalogPlant` | `name`, `canonical_name`, `plant_type`, `fk_plant_care_id`, source fields, `metadata` | `plantCare(): BelongsTo`, `plants(): HasMany` |
| `PlantCare` | `plant_name`, `canonical_name`, lifecycle duration fields, task interval fields, weather thresholds, source metadata | `plants(): HasManyThrough`, `catalogPlants(): HasMany` |
| `PlantConditionHistory` | `plant_id`, `measured_at`, `notes`, `photo_url`, `condition`, `condition_type` | `plant(): BelongsTo` |
| `TaskCalendar` | `creation_date`, `start_date`, `end_date`, `plot_id`, `fk_plot_id` | `plot(): BelongsTo`, `tasks(): HasMany`, `weatherForecasts(): HasMany` |
| `Task` | `date`, `name`, `task_type`, `priority`, `reason`, `comment`, `weather_context`, `inventory_context`, `simulated_state`, `workflow_context`, `state`, `status`, FK į kalendorių, augalą ir zoną | `taskCalendar(): BelongsTo`, `plant(): BelongsTo`, `plantZone(): BelongsTo`, `usedOn(): HasMany`, `harvestRecords(): HasMany`, `requiredResources(): HasMany`, `inventoryUsageLogs(): HasMany` |
| `TaskResourceRequirement` | `task_id`, `resource_name`, `normalized_name`, `inventory_item_type`, `unit`, `required_quantity`, `shortage_quantity`, `is_consumed` | `task(): BelongsTo`, `usageLogs(): HasMany` |
| `WeatherForecast` | `task_calendar_id`, `date`, `temperature`, `temp_min`, `temp_max`, `precipitation`, `humidity`, `wind_kmh`, `condition_code`, source fields | `taskCalendar(): BelongsTo` |
| `InventoryItem` | `garden_owner_id`, `name`, `normalized_name`, `quantity`, `inventory_item_type`, `type`, `unit` | `owner(): BelongsTo`, `inventoryLinks(): HasMany`, `usageLogs(): HasMany` |
| `InventoryUsageLog` | `inventory_item_id`, `task_id`, `task_resource_requirement_id`, `garden_owner_id`, `change_type`, quantity fields, `unit`, `metadata`, `created_at` | `inventoryItem(): BelongsTo`, `task(): BelongsTo`, `taskResourceRequirement(): BelongsTo`, `owner(): BelongsTo` |
| `RotationHistory` | `plant_zone_id`, `from_plant_zone_id`, zone snapshot names, `decision_status`, `decision_note`, `from_date`, `to_date`, plot/plant FK fields | `plot(): BelongsTo`, `plantZone(): BelongsTo`, `fromPlantZone(): BelongsTo`, `plant(): BelongsTo` |
| `RotationPlanDraft` | `plot_id`, `garden_owner_id`, `planning_date`, `plan` | `plot(): BelongsTo`, `gardenOwner(): BelongsTo` |
| `PlotSnapshot` | `plot_id`, `garden_owner_id`, `action`, `snapshot`, `created_at` | `plot(): BelongsTo`, `gardenOwner(): BelongsTo` |
| `HarvestRecord` | `plot_id`, `plant_id`, `task_id`, `garden_owner_id`, `quantity`, `harvested_on`, `notes` | `plot(): BelongsTo`, `plant(): BelongsTo`, `task(): BelongsTo`, `gardenOwner(): BelongsTo` |
| `CommunityPost` | `garden_owner_id`, `plot_id`, `name`, `text`, `share`, `created_at`, owner/profile/plot FK fields | `owner(): BelongsTo`, `ownerUser(): BelongsTo`, `profile(): BelongsTo`, `ownerProfile(): BelongsTo`, `plot(): BelongsTo` |
| `HasPlot`, `HasInventory`, `UsedOn` | Jungiamųjų / legacy lentelių FK laukai | Naudojami nuosavybės ir užduoties-plot/zona ryšiams; detaliose diagramose rodomi tik kai reikalingi. |

## Kontroleriai ir vieši metodai

| Kontroleris | Vieši metodai |
|---|---|
| `SignUpController` | `store()` |
| `LoginController` | `store()` |
| `LogoutController` | `destroy()` |
| `PasswordResetController` | `forgot()`, `reset()` |
| `CurrentUserController` | `show()` |
| `User\AccountController` | `update()` |
| `Admin\AccountController` | `index()`, `show()`, `updateRole()`, `destroy()` |
| `AuditLogController` | `index()` |
| `PlotController` | `index()`, `store()`, `show()`, `update()`, `destroy()` |
| `SchemeController` | `index()`, `store()`, `update()`, `destroy()` |
| `WorkspaceController` | `update()` |
| `ShareController` | `store()`, `destroy()`, `destroyById()`, `index()` |
| `PlantController` | `listAll()`, `index()`, `search()`, `storeGlobal()`, `store()`, `showGlobal()`, `show()`, `updateGlobal()`, `update()`, `destroyGlobal()`, `destroy()` |
| `CatalogPlantController` | `searchPerenual()`, `previewPerenualSpecies()`, `index()`, `show()`, `store()`, `update()`, `destroy()` |
| `PlantConditionController` | `store()`, `index()` |
| `CalendarController` | `index()`, `store()`, `show()` |
| `TaskController` | `index()`, `complete()`, `reject()` |
| `InventoryController` | `index()`, `store()`, `show()`, `update()`, `destroy()` |
| `RotationController` | `index()`, `recommendations()`, `store()`, `plan()`, `updateDraftItem()`, `confirm()`, `reject()` |
| `HistoryController` | `index()` |
| `HarvestController` | `index()`, `store()` |
| `AnalyticsController` | `show()`, `store()` |
| `CommunityController` | `index()`, `plotFeed()`, `store()`, `update()`, `destroy()` |
| `ExportController` | `pdf()` |
| `ReverseGeocodeController` | `show()` |

## Paslaugos ir vieši metodai

| Paslauga | Vieši metodai |
|---|---|
| `AccountService` | `updateAccount()` |
| `AdminService` | `listUsers()`, `getUser()`, `updateUserRole()`, `deleteUser()` |
| `EmailServerBoundary` | `sendPasswordResetLink()` |
| `AccessService` | `sharePlot()`, `revokeAccess()`, `revokeAccessRight()`, `getUserRoleForPlot()`, `userHasAccess()`, `userCanEdit()`, `userIsOwner()`, `accessiblePlotIds()` |
| `PlotWorkspaceService` | `commitDraft()` |
| `PlotSnapshotService` | `capture()`, `captureCommittedVersion()`, `listForPlot()`, `listHistoryForPlot()` |
| `ReverseGeocodingService` | `resolveCity()` |
| `CatalogPlantService` | `saveCatalogPlant()`, `assignCatalogPlantToPlant()`, `syncPlantsFromCatalog()`, `canonicalName()`, `buildPerenualDraft()` |
| `PlantCareService` | `syncPlantCareConfiguration()`, `resolveEffectivePlantCare()`, `ensureLinkedCareProfile()`, `previewLinkedCareProfile()` |
| `PlantCareNormalizer` | `normalize()`, `normalizeWithTrace()` |
| `PlantCareDefaults` | `forPlant()` |
| `PerenualService` | `searchPlants()`, `debugSearchPlants()`, `fetchSpeciesSeed()`, `debugLoadSpecies()` |
| `PlantConditionHistoryService` | `listForPlant()`, `listForPlot()`, `record()` |
| `PlantLifecycleService` | `buildSummary()`, `buildActionsForDate()`, `completeReviewTask()`, `resolvePostHarvestCondition()`, `recordPostHarvestCondition()` |
| `PlantLifecyclePhaseService` | `resolveSimulatedPhase()`, `buildTimeline()`, `resolvePhaseFromTimeline()`, `isExceptionalManualCondition()`, `phaseLabel()` |
| `PlantStateService` | `simulatePlantState()` |
| `TaskCalendarService` | `generate()` |
| `CalendarGenerationService` | `generateCalendar()` |
| `WeatherService` | `getForecastRange()`, `debugForecast()` |
| `MeteoLtClient` | `findPlaceByCity()`, `getLongTermForecast()` |
| `TaskWorkflowService` | `complete()`, `reject()` |
| `TaskInventoryCoverageService` | `buildPlanningLedger()`, `reserveRequirementForPlan()`, `summarizeTasksByDate()`, `buildDayRequirementSummaries()`, `buildTaskInventoryContext()`, `matchingItems()`, `stockKey()` |
| `InventoryService` | `listForOwner()`, `getForOwner()`, `createForOwner()`, `updateForOwner()`, `deleteForOwner()`, `deductMaterialForOwner()`, `checkIfEnough()`, `describeTaskInventory()`, `attachLiveTaskInventory()`, `summarizeTasksByDate()`, `assertTaskCanBeCompletedForDay()`, `consumeTaskRequirements()`, `replenishFromTask()`, `buildPlanningLedger()`, `reserveRequirementForPlan()` |
| `RotationPlannerService` | `evaluatePlot()`, `evaluatePlacement()`, `createDraft()`, `updateDraftItem()`, `confirmDraft()`, `rejectDraft()` |
| `CropRotationClassifier` | `profileForPlant()` |
| `HarvestService` | `listForPlot()`, `registerForPlot()`, `registerForTask()` |
| `AnalyticsService` | `analyzePlot()`, `getPlotSummaryMetrics()`, `getTaskMetrics()` |
| `CommunityService` | `listPublicFeed()`, `listFeed()`, `listByPlot()`, `createPost()`, `updatePost()`, `deletePost()` |
| `PdfExportService` | `exportPlotReport()`, `renderPlotReportHtml()` |

## Į diagramas sąmoningai neįtraukti elementai

- Maži bendri UI komponentai iš `frontend/src/components/ui`, nes jie nepakeičia dalykinio sistemos klasių modelio.
- Testų failai.
- Migracijos, seeders ir factories kaip klasės; migracijos naudotos tik atributams ir ryšiams patikrinti.
- `PlantCareDebugController` ir `dev` maršrutai, nes jie yra derinimo pagalbiniai endpointai, o ne pagrindinė BBP funkcija.
- `HasPlot`, `HasInventory`, `UsedOn` detaliose diagramose rodomi tik kai ryšys svarbus; tai labiau jungiamųjų lentelių modeliai.
