# Esybių klasių modelio specifikacija

## 1. Modelio sudarymo pagrindas

Specifikacija sudaryta pagal realią projekto implementaciją kataloge `C:\Users\Vartotojas\Desktop\PraktikaADIS\Realizacija_v2`. Pagrindiniai šaltiniai:

- Laravel Eloquent modeliai: `backend/app/Models`;
- enumeracijos: `backend/app/Enums`;
- migracijos ir jų vėlesni schemos suderinimai: `backend/database/migrations`;
- verslo logikos servisai: `backend/app/Services`;
- API kontroleriai, užklausų validacijos ir resursai: `backend/app/Http/Controllers`, `backend/app/Http/Requests`, `backend/app/Http/Resources`;
- API maršrutai: `backend/routes/api.php`;
- React puslapiai, komponentai, API klientas ir konstantos: `frontend/src`;
- testai ir demonstraciniai seed failai: `backend/tests`, `backend/database/seeders`;
- papildomi projekto dokumentai: `DEMO_DATA.md`, `AUDIT_REPORT.md`, `entity_class_diagram_traceability.md`.

Tai nėra loginė duomenų bazės schema. Modelis interpretuoja dalykinės srities esybes pagal realų kodą ir atskiria jas nuo grynai techninių framework artefaktų, pvz. Sanctum tokenų, slaptažodžio atstatymo tokenų ir audito žurnalų.

## 2. Pagrindinės esybės

| Esybė diagramoje | Kodo atitikmuo | Tipas | Pagrindiniai atributai | Pastabos |
|---|---|---|---|---|
| Naudotojas | `App\Models\User`, `users` | Pagalbinė / prieigos valdymo esybė | `email`, `role` | Slaptažodis, tokenai ir `reset_code` nerodomi. Role valdoma `UserRole`. |
| Profilis | `App\Models\Profile`, `profiles` | Pagalbinė esybė | `name`, `surname`, `last_login` | Dalykinė naudotojo tapatybės dalis. |
| Sodo savininkas | `App\Models\GardenOwner`, `garden_owners` | Pagrindinė dalykinė / aktoriaus esybė | nėra savarankiškų verslo atributų | Susieja naudotoją ir profilį su sodininko funkcijomis. |
| Administratorius | `User.role = admin`, `AdminMiddleware`, admin kontroleriai | Specializacija pagal enum | nėra atskiros lentelės | UML galima rodyti kaip `Naudotojas` specializaciją, bet kode atskiro modelio nėra. |
| Sklypas | `App\Models\Plot`, `plots` | Pagrindinė esybė | `name`, `city`, `plot_size`, `creation_date`, `description`, `share`, `geometry` | Centrinė planavimo srities esybė. |
| Augalų zona | `App\Models\PlantZone`, `plant_zones` | Pagrindinė esybė | `name`, `zone_size`, `soil_type`, `rotation_stage`, `last_planting_date`, `geometry` | Priklauso sklypui, geometriją interpretuoja frontend. |
| Augalas | `App\Models\Plant`, `plants` | Pagrindinė esybė | `name`, `type`, `condition`, `plant_date`, `disease`, `disease_notes`, `growing_time_days`, `recommended_temperature`, `recommended_humidity`, `rest_time_days`, `plant_size`, `photo_url`, `reusable` | Realioje implementacijoje priežiūra gaunama per katalogo augalą. |
| Katalogo augalas | `App\Models\CatalogPlant`, `catalog_plants` | Pagrindinė / katalogo esybė | `name`, `canonical_name`, `plant_type`, `description`, `source_provider`, `source_quality`, `source_scientific_name`, `source_family`, `source_image_url`, `metadata` | Reusable augalų katalogas, naudojamas sodinamiems augalams susieti su priežiūra. |
| Augalo priežiūros profilis | `App\Models\PlantCare`, `plant_care` | Pagrindinė žinių bazės esybė | `plant_name`, `canonical_name`, `description`, `conditions`, trukmės, intervalai, orų slenksčiai, šaltinio metaduomenys | Perenual duomenys normalizuojami ir talpinami čia. |
| Augalo būsenos įrašas | `App\Models\PlantConditionHistory`, `plant_condition_history` | Istorijos esybė | `measured_at`, `condition`, `condition_type`, `notes`, `photo_url` | Įrašas taip pat atnaujina augalo būseną per servisą. |
| Sėjomainos įrašas | `App\Models\RotationHistory`, `rotation_history` | Istorijos esybė | `from_date`, `to_date` | Susieja sklypą, zoną ir augalą. |
| Sėjomainos plano juodraštis | `App\Models\RotationPlanDraft`, `rotation_plan_drafts` | Pagalbinė planavimo esybė | `planning_date`, `plan` | JSON plano juodraštis iki patvirtinimo. |
| Sklypo versija | `plot_snapshots` lentelė, `PlotSnapshotService` | Istorijos / pagalbinė esybė | `action`, `snapshot`, `created_at` | Eloquent modelio nėra, bet dalykinė prasmė aiški: planavimo istorijos momentinė kopija. |
| Rekomendacijų kalendorius | `App\Models\TaskCalendar`, `task_calendars` | Pagrindinė esybė | `creation_date`, `start_date`, `end_date` | Generuojamas sklypui ir turi užduotis bei orų prognozes. |
| Užduotis | `App\Models\Task`, `tasks` | Pagrindinė esybė | `date`, `name`, `task_type`, `priority`, `reason`, `comment`, `item`, `item_quantity`, `state`, kontekstai | Turi dublikuotus suderinimo laukus `type/status`. |
| Užduoties resurso poreikis | `App\Models\TaskResourceRequirement`, `task_resource_requirements` | Pagalbinė / kalendoriaus esybė | `resource_name`, `inventory_item_type`, `unit`, `required_quantity`, `shortage_quantity`, `is_consumed` | Naudojama inventoriaus pakankamumui planuoti. |
| Orų prognozė | `App\Models\WeatherForecast`, `weather_forecasts` | Pagalbinė / integracijos duomenų esybė | `date`, `temperature`, `temp_min`, `temp_max`, `precipitation`, `humidity`, `wind_kmh`, `condition_code`, `source`, `source_date`, `source_city`, `city`, `is_seasonal_fallback` | Saugo Meteo.lt arba fallback prognozes. |
| Inventoriaus elementas | `App\Models\InventoryItem`, `inventory_items` | Pagrindinė esybė | `name`, `normalized_name`, `quantity`, `inventory_item_type`, `unit` | `type` yra senesnis `inventory_item_type` alias. |
| Inventoriaus naudojimo įrašas | `App\Models\InventoryUsageLog`, `inventory_usage_logs` | Istorijos esybė | `change_type`, `quantity_before`, `quantity_delta`, `quantity_after`, `unit`, `metadata`, `created_at` | Fiksuoja sunaudojimą ir papildymą. |
| Derliaus įrašas | `App\Models\HarvestRecord`, `harvest_records` | Istorijos esybė | `quantity`, `harvested_on`, `notes` | Gali būti susietas su derliaus užduotimi. |
| Prieigos teisė | `App\Models\AccessRight`, `access_rights` | Prieigos valdymo esybė | `granted_at`, `role` | Dalykinė bendrinimo teisė, ne tik techninis pivot. |
| Bendruomenės įrašas | `App\Models\CommunityPost`, `community_posts` | Pagrindinė bendruomenės esybė | `name`, `text`, `share`, `created_at` | Gali būti susietas su sklypu. |
| Sklypo savininkystės jungtis | `App\Models\HasPlot`, `has_plot` | Techninė / istorinė jungiamoji esybė | FK laukai | Netraukti į pagrindinę diagramą, nes dabartinė logika naudoja ir `plots.garden_owner_id`. |
| Inventoriaus savininkystės jungtis | `App\Models\HasInventory`, `has_inventory` | Techninė / istorinė jungiamoji esybė | FK laukai | Netraukti į pagrindinę diagramą. |
| Užduoties taikymo jungtis | `App\Models\UsedOn`, `used_on` | Techninė jungiamoji esybė | FK laukai | Neturi papildomų atributų, geriau rodyti kaip asociaciją tarp užduoties ir zonos. |
| Audito žurnalas | `App\Models\AuditLog`, `audit_logs` | Techninė esybė | `action`, `context`, `created_at` | Admin veiksmų auditas, pagrindiniame dalykinės srities modelyje nerodyti. |

## 3. Esybių atributai

### Naudotojas

- `email`: string
- `role`: `UserRole`

Nerodomi techniniai atributai: `id`, `password`, `reset_code`, `created_at`, `updated_at`, Sanctum tokenai.

### Profilis

- `name`: string
- `surname`: string
- `last_login`: datetime

Nerodomi techniniai atributai: `id`, `user_id`.

### Sodo savininkas

- Dalykiškai savarankiškų atributų nėra; klasė žymi naudotoją, kuris gali valdyti sklypus, inventorių, bendrinimus ir bendruomenės įrašus.

Nerodomi techniniai atributai: `id`, `user_id`, `id_user`, `fk_profile_id`.

### Sklypas

- `name`: string
- `city`: string
- `plot_size`: decimal(10,2)
- `creation_date`: date
- `description`: text|null
- `share`: boolean
- `geometry`: JSON|null

Nerodomi techniniai atributai: `id`, `garden_owner_id`.

### Augalų zona

- `name`: string
- `zone_size`: decimal(10,2)
- `soil_type`: `SoilType`
- `rotation_stage`: unsigned integer
- `last_planting_date`: date|null
- `geometry`: JSON|null

Nerodomi techniniai atributai: `id`, `plot_id`, `fk_plot_id`.

### Augalas

- `name`: string
- `growing_time_days`: unsigned integer|null
- `recommended_temperature`: decimal(6,2)|null
- `recommended_humidity`: decimal(6,2)|null
- `plant_date`: date
- `disease`: boolean
- `disease_notes`: string|null
- `rest_time_days`: unsigned integer|null
- `plant_size`: decimal(10,2)|null
- `photo_url`: string|null
- `reusable`: boolean
- `type`: `PlantType`
- `condition`: `ConditionType`

Nerodomi techniniai atributai: `id`, `fk_catalog_plant_id`, `plant_zone_id`, `fk_plant_zone_id`, `fk_plot_id`.

### Katalogo augalas

- `name`: string
- `canonical_name`: string
- `plant_type`: `PlantType`|null
- `description`: text|null
- `source_provider`: string|null
- `source_quality`: string|null
- `source_scientific_name`: string|null
- `source_family`: string|null
- `source_image_url`: text|null
- `metadata`: JSON|null

Nerodomi techniniai atributai: `id`, `fk_plant_care_id`.

### Augalo priežiūros profilis

- `plant_name`: string
- `canonical_name`: string|null
- `description`: text
- `conditions`: text|null
- `plant_type`: `PlantType`
- `condition`: `ConditionType`
- `task_type`: `TaskType`
- `growing_duration_days`: integer|null
- `germinating_duration_days`: integer|null
- `flowering_duration_days`: integer|null
- `mature_duration_days`: integer|null
- `mature_duration_end_days`: integer|null
- `mature_end_duration_days`: integer|null
- `regenerating_duration_days`: integer|null
- `reusable`: boolean
- `watering_interval_days`: integer
- `fertilizing_interval_days`: integer
- `pest_check_interval_days`: integer
- `rain_skip_threshold_mm`: decimal(5,1)
- `frost_temp_threshold_c`: decimal(4,1)
- `heat_extra_water_temp_c`: decimal(4,1)
- `wind_protection_kmh`: decimal(5,1)
- `source_provider`: string|null
- `source_quality`: string|null
- `source_perenual_species_id`: unsigned integer|null
- `source_common_name`: string|null
- `source_scientific_name`: string|null
- `source_family`: string|null
- `source_image_url`: text|null

Nerodomi techniniai atributai: `id`.

### Augalo būsenos įrašas

- `measured_at`: datetime
- `condition`: `ConditionType`
- `condition_type`: `ConditionType`
- `notes`: text|null
- `photo_url`: string|null

Nerodomi techniniai atributai: `id`, `plant_id`, `fk_plant_id`.

### Sėjomainos įrašas

- `from_date`: date
- `to_date`: date|null

Nerodomi techniniai atributai: `id`, `plant_zone_id`, `fk_plot_id`, `fk_plant_zone_id`, `fk_plot_via_zone`, `fk_plant_id`.

### Sėjomainos plano juodraštis

- `planning_date`: date
- `plan`: JSON

Nerodomi techniniai atributai: `id`, `plot_id`, `garden_owner_id`, `created_at`, `updated_at`.

### Sklypo versija

- `action`: string
- `snapshot`: JSON
- `created_at`: datetime

Nerodomi techniniai atributai: `id`, `plot_id`, `garden_owner_id`.

### Rekomendacijų kalendorius

- `creation_date`: datetime
- `start_date`: date
- `end_date`: date

Nerodomi techniniai atributai: `id`, `plot_id`, `fk_plot_id`.

### Užduotis

- `date`: date
- `name`: string
- `task_type`: `TaskType`
- `priority`: `TaskPriority`
- `reason`: text|null
- `comment`: text|null
- `item`: string|null
- `item_quantity`: decimal(10,2)|null
- `state`: `TaskState`
- `weather_context`: JSON|null
- `inventory_context`: JSON|null
- `simulated_state`: JSON|null
- `workflow_context`: JSON|null

Nerodomi techniniai atributai: `id`, `type`, `status`, `task_calendar_id`, `fk_task_calendar_id`, `plant_id`, `fk_plant_id`, `plant_zone_id`.

### Užduoties resurso poreikis

- `resource_name`: string
- `inventory_item_type`: `InventoryItemType`
- `unit`: `InventoryUnit`
- `required_quantity`: decimal(10,2)
- `shortage_quantity`: decimal(10,2)
- `is_consumed`: boolean

Nerodomi techniniai atributai: `id`, `task_id`, `normalized_name`.

### Orų prognozė

- `date`: date
- `temperature`: decimal(6,2)
- `temp_min`: decimal(6,2)|null
- `temp_max`: decimal(6,2)|null
- `precipitation`: decimal(6,2)
- `humidity`: decimal(6,2)
- `wind_kmh`: decimal(6,2)|null
- `condition_code`: string|null
- `is_seasonal_fallback`: boolean
- `source`: string|null
- `source_date`: date|null
- `source_city`: string|null
- `city`: string

Nerodomi techniniai atributai: `id`, `task_calendar_id`, `fk_task_calendar_id`.

### Inventoriaus elementas

- `name`: string
- `normalized_name`: string
- `quantity`: decimal(10,2)
- `inventory_item_type`: `InventoryItemType`
- `unit`: `InventoryUnit`

Nerodomi techniniai atributai: `id`, `garden_owner_id`, `type`.

### Inventoriaus naudojimo įrašas

- `change_type`: string (`consumed`, `replenished` pagal servisų logiką)
- `quantity_before`: decimal(10,2)
- `quantity_delta`: decimal(10,2)
- `quantity_after`: decimal(10,2)
- `unit`: `InventoryUnit`
- `metadata`: JSON|null
- `created_at`: datetime

Nerodomi techniniai atributai: `id`, `inventory_item_id`, `task_id`, `task_resource_requirement_id`, `garden_owner_id`.

### Derliaus įrašas

- `quantity`: double
- `harvested_on`: date
- `notes`: text|null

Nerodomi techniniai atributai: `id`, `plot_id`, `plant_id`, `task_id`, `garden_owner_id`, `created_at`, `updated_at`.

### Prieigos teisė

- `granted_at`: datetime
- `role`: `AccessRole`

Nerodomi techniniai atributai: `id`, `garden_owner_id`, `plot_id`, `fk_plot_id`, `fk_grantor_owner_id`, `fk_grantor_profile_id`, `fk_recipient_owner_id`, `fk_recipient_profile_id`.

### Bendruomenės įrašas

- `name`: string
- `text`: text
- `share`: boolean
- `created_at`: datetime

Nerodomi techniniai atributai: `id`, `garden_owner_id`, `plot_id`, `fk_owner_id`, `fk_profile_id`, `fk_plot_id`.

## 4. Ryšiai tarp esybių

| Esybė A | Ryšys | Esybė B | Kardinalumas A | Kardinalumas B | Ryšio tipas | Pagrindimas kode |
|---|---|---|---|---|---|---|
| Naudotojas | turi profilį | Profilis | 1 | 0..1 | Kompozicija | `User::profile()`, `Profile::user()`, `profiles.user_id`, `SignUpController` sukuria abu kartu |
| Naudotojas | turi savininko tapatybę | Sodo savininkas | 1 | 0..1 | Kompozicija | `User::gardenOwner()`, `GardenOwner::user()`, `garden_owners.user_id` |
| Naudotojas | specializuojamas į | Administratorius | 1 | 0..1 | Apibendrinimas / specializavimas | `UserRole::Admin`, `AdminMiddleware`, admin kontroleriai; nėra atskiro DB modelio |
| Naudotojas | specializuojamas į | Sodo savininkas | 1 | 0..1 | Apibendrinimas / specializavimas | `UserRole::Owner`, `GardenOwner` modelis |
| Sodo savininkas | valdo | Sklypas | 1 | 0..* | Asociacija / savininkystė | `GardenOwner::ownedPlots()`, `Plot::gardenOwner()`, `plots.garden_owner_id`, `AccessService::userIsOwner()` |
| Sodo savininkas | turi inventorių | Inventoriaus elementas | 1 | 0..* | Kompozicija | `GardenOwner::ownedInventoryItems()`, `InventoryItem::owner()`, `inventory_items.garden_owner_id`, `InventoryService::listForOwner()` |
| Sodo savininkas | suteikia | Prieigos teisė | 1 | 0..* | Asociacija | `AccessRight::grantor()`, `AccessService::sharePlot()` |
| Sodo savininkas | gauna | Prieigos teisė | 1 | 0..* | Asociacija | `AccessRight::recipient()`, `AccessService::sharedAccessQuery()` |
| Prieigos teisė | suteikia prieigą prie | Sklypas | 0..* | 1 | Asociacija | `AccessRight::plot()`, `Plot::accessRights()`, `access_rights.plot_id/fk_plot_id` |
| Sklypas | sudarytas iš | Augalų zona | 1 | 0..* | Kompozicija | `Plot::plantZones()`, `PlantZone::plot()`, FK su `cascadeOnDelete` |
| Sklypas | turi sodinamus augalus | Augalas | 1 | 0..* | Kompozicija | `Plot::plants()`, `Plant::plot()`, `plants.fk_plot_id` su `cascadeOnDelete` |
| Augalų zona | talpina | Augalas | 1 | 0..* | Kompozicija | `PlantZone::plants()`, `Plant::plantZone()`, `plants.plant_zone_id/fk_plant_zone_id` |
| Katalogo augalas | naudojamas sodinamam augalui | Augalas | 0..1 | 0..* | Asociacija | `CatalogPlant::plants()`, `Plant::catalogPlant()`, `plants.fk_catalog_plant_id` |
| Katalogo augalas | turi bendrą priežiūros profilį | Augalo priežiūros profilis | 0..* | 0..1 | Agregacija | `CatalogPlant::plantCare()`, `PlantCare::catalogPlants()`, `catalog_plants.fk_plant_care_id` |
| Augalo priežiūros profilis | taikomas per katalogą | Augalas | 0..1 | 0..* | Netiesioginė asociacija | `Plant::effectivePlantCare()`, `PlantCare::plants()` per `CatalogPlant`; tiesioginis `plants.fk_plant_care_id` pašalintas |
| Augalas | turi būsenos istoriją | Augalo būsenos įrašas | 1 | 0..* | Kompozicija | `Plant::conditionHistory()`, `PlantConditionHistory::plant()`, FK su `cascadeOnDelete` |
| Sklypas | turi sėjomainos istoriją | Sėjomainos įrašas | 1 | 0..* | Kompozicija | `Plot::rotationHistory()`, `RotationHistory::plot()`, `rotation_history.fk_plot_id` |
| Augalų zona | dalyvauja sėjomainoje | Sėjomainos įrašas | 1 | 0..* | Asociacija | `PlantZone::rotationHistory()`, `RotationHistory::plantZone()` |
| Augalas | įrašomas sėjomainoje | Sėjomainos įrašas | 1 | 0..* | Asociacija | `Plant::rotationHistory()`, `RotationHistory::plant()` |
| Sklypas | turi sėjomainos juodraščius | Sėjomainos plano juodraštis | 1 | 0..* | Kompozicija | `Plot::rotationPlanDrafts()`, `RotationPlanDraft::plot()`, `rotation_plan_drafts.plot_id` |
| Sodo savininkas | rengia | Sėjomainos plano juodraštis | 0..1 | 0..* | Asociacija | `RotationPlanDraft::gardenOwner()`, `garden_owner_id` nullable |
| Sklypas | turi planavimo versijas | Sklypo versija | 1 | 0..* | Kompozicija | `plot_snapshots.plot_id`, `PlotSnapshotService::capture()` |
| Sodo savininkas | sukuria planavimo versiją | Sklypo versija | 0..1 | 0..* | Asociacija | `plot_snapshots.garden_owner_id`, `PlotSnapshotService::capture()` |
| Sklypas | turi kalendorius | Rekomendacijų kalendorius | 1 | 0..* | Kompozicija | `Plot::taskCalendars()`, `TaskCalendar::plot()`, `task_calendars.plot_id/fk_plot_id` |
| Rekomendacijų kalendorius | sudarytas iš | Užduotis | 1 | 0..* | Kompozicija | `TaskCalendar::tasks()`, `Task::taskCalendar()`, `tasks.task_calendar_id/fk_task_calendar_id` |
| Rekomendacijų kalendorius | turi orų prognozes | Orų prognozė | 1 | 0..* | Kompozicija | `TaskCalendar::weatherForecasts()`, `WeatherForecast::taskCalendar()` |
| Užduotis | skirta augalui | Augalas | 0..* | 0..1 | Asociacija | `Task::plant()`, `Plant::tasks()`, `tasks.plant_id/fk_plant_id` nullable |
| Užduotis | skirta zonai | Augalų zona | 0..* | 0..1 | Asociacija | `Task::plantZone()`, `PlantZone::tasks()` per `used_on`; dalis ryšio per tiesioginį `plant_zone_id` |
| Užduotis | reikalauja resursų | Užduoties resurso poreikis | 1 | 0..* | Kompozicija | `Task::requiredResources()`, `TaskResourceRequirement::task()`, FK su `cascadeOnDelete` |
| Užduoties resurso poreikis | įgyvendinamas naudojant | Inventoriaus naudojimo įrašas | 0..1 | 0..* | Asociacija | `TaskResourceRequirement::usageLogs()`, `InventoryUsageLog::taskResourceRequirement()` |
| Inventoriaus elementas | turi naudojimo istoriją | Inventoriaus naudojimo įrašas | 1 | 0..* | Kompozicija | `InventoryItem::usageLogs()`, `InventoryUsageLog::inventoryItem()`, FK su `cascadeOnDelete` |
| Užduotis | sukuria inventoriaus pokyčius | Inventoriaus naudojimo įrašas | 0..1 | 0..* | Asociacija | `Task::inventoryUsageLogs()`, `InventoryUsageLog::task()`, `InventoryService::consumeTaskRequirements()` |
| Sklypas | turi derliaus įrašus | Derliaus įrašas | 1 | 0..* | Kompozicija | `Plot::harvestRecords()`, `HarvestRecord::plot()`, FK su `cascadeOnDelete` |
| Augalas | duoda derlių | Derliaus įrašas | 1 | 0..* | Asociacija | `Plant::harvestRecords()`, `HarvestRecord::plant()` |
| Užduotis | gali būti derliaus pagrindas | Derliaus įrašas | 0..1 | 0..1 | Asociacija | `HarvestRecord::task()`, `HarvestService::registerForPlot()` draudžia antrą įrašą tam pačiam task |
| Sodo savininkas | registruoja derlių | Derliaus įrašas | 0..1 | 0..* | Asociacija | `HarvestRecord::gardenOwner()`, `garden_owner_id` nullable |
| Sodo savininkas | publikuoja | Bendruomenės įrašas | 1 | 0..* | Asociacija | `GardenOwner::communityPosts()`, `CommunityPost::owner()`, `CommunityService::createPost()` |
| Profilis | identifikuoja įrašo autorių | Bendruomenės įrašas | 1 | 0..* | Asociacija | `CommunityPost::profile()`, `CommunityPostResource` rodo autoriaus vardą |
| Sklypas | gali būti prisegtas prie | Bendruomenės įrašas | 0..1 | 0..* | Asociacija | `Plot::communityPosts()`, `CommunityPost::plot()`, `fk_plot_id` nullable |

## 5. Enumeracijos

| Enum diagramoje | Kodo failas | Reikšmės | Naudojama esybėse |
|---|---|---|---|
| Naudotojo vaidmuo | `backend/app/Enums/UserRole.php` | `admin`, `owner` | `Naudotojas.role` |
| Prieigos vaidmuo | `backend/app/Enums/AccessRole.php` | `viewer`, `editor` | `PrieigosTeisė.role` |
| Analizės tipas | `backend/app/Enums/AnalysisType.php` | `planning`, `plant_condition`, `harvest` | API analitikos užklausose; nepersistuojama Eloquent modelyje |
| Augalo būsena | `backend/app/Enums/ConditionType.php` | `diseased`, `dried`, `flowering`, `germinating`, `growing`, `mature`, `planted`, `regenerating` | `Augalas.condition`, `AugaloPriežiūrosProfilis.condition`, `AugaloBūsenosĮrašas.condition/condition_type` |
| Augalo tipas | `backend/app/Enums/PlantType.php` | `berry`, `cereal`, `flower`, `forage`, `fruit`, `herb`, `legume`, `oilseed`, `shrub`, `tree`, `vegetable` | `Augalas.type`, `KatalogoAugalas.plant_type`, `AugaloPriežiūrosProfilis.plant_type` |
| Dirvožemio tipas | `backend/app/Enums/SoilType.php` | `clay`, `greasy`, `peaty`, `rocky`, `sandy` | `AugalųZona.soil_type` |
| Užduoties tipas | `backend/app/Enums/TaskType.php` | `buy`, `fertilize`, `harvest`, `planting`, `rest`, `spray`, `transplant`, `watering` | `Užduotis.task_type`, `AugaloPriežiūrosProfilis.task_type` |
| Užduoties būsena | `backend/app/Enums/TaskState.php` | `pending`, `completed`, `canceled` | `Užduotis.state`, DB constraint ir `status` alias |
| Užduoties prioritetas | `backend/app/Enums/TaskPriority.php` | `low`, `medium`, `high` | `Užduotis.priority` |
| Inventoriaus elemento tipas | `backend/app/Enums/InventoryItemType.php` | `material`, `tool` | `InventoriausElementas.inventory_item_type`, `UžduotiesResursoPoreikis.inventory_item_type` |
| Inventoriaus matavimo vienetas | `backend/app/Enums/InventoryUnit.php` | `unit`, `g`, `kg`, `ml`, `l`, `bag`, `pack`, `m3` | `InventoriausElementas.unit`, `UžduotiesResursoPoreikis.unit`, `InventoriausNaudojimoĮrašas.unit` |

Papildomai yra kodo kontroliuojamas, bet enum klase neaprašytas `InventoryUsageLog.change_type`: servisuose naudojamos reikšmės `consumed` ir `replenished`.

## 6. Rekomenduojama diagramos struktūra

Pagrindinė akademinė diagrama turėtų būti skaidri ir neperkrauta Laravel suderinimo laukais.

- Centre rodyti `Sklypas`, `Augalų zona`, `Augalas`.
- Kairėje rodyti naudotojų ir prieigos bloką: `Naudotojas`, `Profilis`, `Sodo savininkas`, `Prieigos teisė`, conceptual `Administratorius`.
- Dešinėje rodyti priežiūros bloką: `Katalogo augalas`, `Augalo priežiūros profilis`, `Augalo būsenos įrašas`.
- Apačioje rodyti kalendoriaus ir inventoriaus bloką: `Rekomendacijų kalendorius`, `Užduotis`, `Užduoties resurso poreikis`, `Inventoriaus elementas`, `Inventoriaus naudojimo įrašas`, `Orų prognozė`.
- Atskirai arba žemiau rodyti istorijos / rezultatų bloką: `Sėjomainos įrašas`, `Sėjomainos plano juodraštis`, `Sklypo versija`, `Derliaus įrašas`.
- Bendruomenės bloką galima rodyti šalia sklypo kaip papildomą posritį: `Bendruomenės įrašas`.
- Enumeracijas dėti diagramoje apačioje, jungiant priklausomybių rodyklėmis arba naudojant atributo tipus.

Rekomenduojami UML ryšių tipai:

- Kompozicija: `Sklypas *-- Augalų zona`, `Sklypas *-- Augalas`, `Sklypas *-- Rekomendacijų kalendorius`, `Rekomendacijų kalendorius *-- Užduotis`, `Užduotis *-- Užduoties resurso poreikis`, `Augalas *-- Augalo būsenos įrašas`, `Sklypas *-- Sklypo versija`.
- Agregacija: `Katalogo augalas o-- Augalo priežiūros profilis`, nes priežiūros profilis yra pakartotinai naudojama žinių bazė.
- Asociacijos: prieigos, derliaus, inventoriaus naudojimo, sėjomainos, bendruomenės ryšiai.
- Apibendrinimas / specializavimas: `Naudotojas <|-- Sodo savininkas` ir `Naudotojas <|-- Administratorius`, su pastaba, kad `Administratorius` kode yra enum vaidmuo, o ne atskira lentelė.

Jeigu diagrama tampa per didelė, siūlomas skaidymas:

1. Naudotojai ir prieigos teisės.
2. Sklypai, zonos ir augalai.
3. Augalų katalogas ir priežiūros žinių bazė.
4. Rekomendacinis kalendorius, užduotys ir orai.
5. Inventorius ir sunaudojimo įrašai.
6. Derlius, būsenų istorija, sėjomaina ir planavimo istorija.
7. Bendruomenė.
8. Techniniai įrašai: auditas, tokenai, slaptažodžio atstatymas.

## 7. Modelio neatitikimai arba abejonės

- Specifikacijoje nurodytas tiesioginis `plants.fk_plant_care_id -> plant_care.id`, bet reali implementacija jį pašalino migracijoje `2026_04_20_120000_remove_redundant_plant_care_from_plants_table.php`. Dabar augalo priežiūra gaunama netiesiogiai: `Plant -> CatalogPlant -> PlantCare`.
- `GardenOwner` lentelė pradžioje turi sudėtinį raktą `id_user + fk_profile_id`, vėlesnėse migracijose pridėti `id` ir `user_id`, o modelis naudoja `$primaryKey = 'id'`. UML rodyti dalykinę tapatybę, o ne visus suderinimo raktus.
- Keliuose modeliuose yra dublikuoti pereinamieji laukai: `plot_id/fk_plot_id`, `plant_id/fk_plant_id`, `task_calendar_id/fk_task_calendar_id`, `task_type/type`, `state/status`, `inventory_item_type/type`, `condition/condition_type`. Diagramoje rodyti tik dalykiškai aiškų atributą.
- `SoilType` backend enum turi `greasy`, tačiau frontend `frontend/src/lib/constants.js` `SOIL_TYPES` sąraše yra tik `clay`, `peaty`, `rocky`, `sandy`. Tai frontend/backend neatitikimas.
- `AnalysisType` enum naudojamas analitikos užklausose, bet nėra persistuojamas kaip modelio atributas.
- `InventoryUsageLog.change_type` nėra enum klasė, nors realiai servisai naudoja `consumed` ir `replenished`.
- `plot_snapshots` turi aiškią dalykinę prasmę, bet neturi Eloquent modelio. UML jį verta rodyti tik istorijos posrities detalizacijoje.
- `HasPlot`, `HasInventory`, `UsedOn` turi Eloquent modelius, bet yra jungiamieji techniniai modeliai be savarankiškų dalykinių atributų. Pagrindinėje diagramoje juos geriau pakeisti asociacijomis.
- `AuditLog`, `personal_access_tokens`, `password_reset_tokens` ir panašūs įrašai yra techninės esybės. Pagrindiniame dalykinės srities esybių modelyje jų nerodyti.
- PDF generavimas, Sanctum autentifikavimas, Meteo.lt, Perenual, el. pašto serveris ir Nominatim reverse geocoding nėra dalykinės srities esybės. Jas rodyti komponentų, išorinių sistemų arba diegimo diagramose.

## 8. Išorinės sistemos ir ribinės klasės

- Meteo.lt: naudojama per `MeteoLtClient` ir `WeatherService`; rezultatų kopijos saugomos kaip `Orų prognozė`.
- Perenual: naudojama per `PerenualService`, `PlantCareService`, `PlantCareNormalizer`; normalizuotas rezultatas saugomas `Augalo priežiūros profilyje` ir `Katalogo augale`.
- El. pašto serveris: naudojamas slaptažodžio atstatymui per `EmailServerBoundary`; nėra dalykinės srities esybė.
- PDF generavimas: `PdfExportService` ir `dompdf/dompdf`; tinka komponentų diagramai.
- Autentifikavimas: Laravel Sanctum tokenai, middleware ir slaptažodžio reset tokenai yra techninė infrastruktūra.
- Nominatim reverse geocoding: naudojamas miesto nustatymui kuriant sklypą, bet pagrindiniame UML esybių modelyje jo nerodyti.

## 9. Pagrindinei diagramai rekomenduojamos esybės

Pagrindinėje diagramoje rekomenduojama rodyti:

- `Naudotojas`, `Profilis`, `Sodo savininkas`, `Administratorius`, `Prieigos teisė`;
- `Sklypas`, `Augalų zona`, `Augalas`;
- `Katalogo augalas`, `Augalo priežiūros profilis`, `Augalo būsenos įrašas`;
- `Rekomendacijų kalendorius`, `Užduotis`, `Užduoties resurso poreikis`, `Orų prognozė`;
- `Inventoriaus elementas`, `Inventoriaus naudojimo įrašas`;
- `Sėjomainos įrašas`, `Derliaus įrašas`, `Sklypo versija`;
- `Bendruomenės įrašas`.

Papildomose diagramose arba pastabose rodyti:

- `Sėjomainos plano juodraštis`;
- `HasPlot`, `HasInventory`, `UsedOn`;
- `AuditLog`, `personal_access_tokens`, `password_reset_tokens`;
- išorines sistemas ir PDF/autentifikavimo mechanizmus.
