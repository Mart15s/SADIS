# PA27 Identifikuoti augalo bukle - codebase auditas

## 1. Frontend boundary

Realus PA27 inicijavimas vyksta ne per atskira augalo bukles puslapi, o per rekomendacinio kalendoriaus generavima:

- `PlotCalendarPage` (`frontend/src/pages/calendar/PlotCalendarPage.jsx`) turi `handleGenerate(event)`, kuris kviecia `api.generateCalendar(plotId, generateForm)`.
- `PlantDetailPage` (`frontend/src/pages/plant/PlantDetailPage.jsx`) rodo lifecycle santrauka ir bukles istorija, bet tiesiogiai nekviecia `PlantLifecyclePhaseService::resolveSimulatedPhase()`.

## 2. Controlleriai

- `CalendarController::store()` (`backend/app/Http/Controllers/Calendar/CalendarController.php`) yra realus UI inicijuoto kalendoriaus generavimo controlleris.
- `GenerateCalendarRequest` (`backend/app/Http/Requests/Calendar/GenerateCalendarRequest.php`) validuoja `start_date` ir `end_date`.
- `PlantController::show()` ir `PlantController::showGlobal()` naudoja `PlantLifecycleService::buildSummary()` augalo puslapio lifecycle santraukai, bet tai nera tas pats aktyvus PA27 kalendoriaus fazes identifikavimo kvietimas.

## 3. Service dalyviai

- `TaskCalendarService::generate()` deleguoja i `CalendarGenerationService::generateCalendar()`.
- `AccessService::userCanEdit()` dalyvauja per `ensureUserCanEditPlot()` pries leidziant generuoti kalendoriu.
- `CalendarGenerationService::generateCalendar()` yra pagrindinis PA27 inicijavimo taskas kalendoriaus generavimo metu.
- `PlantCareService::resolveEffectivePlantCare()` paruosia / susieja augalo prieziuros profili.
- `PlantLifecyclePhaseService::resolveSimulatedPhase()` apskaiciuoja simuliuojama lifecycle faze pagal data ir `PlantCare` trukmes.
- `PlantLifecycleService::buildActionsForDate()` naudoja `simulatedState` lifecycle perziuros ir derliaus veiksmams.
- `PlantStateService::simulatePlantState()` egzistuoja ir logiskai panasus i pateikta veiklos diagrama, bet realiu kvietimu codebase paieskoje nerasta, todel i pagrindine diagrama neitrauktas.

## 4. Entity / modeliai

- `Plot`
- `Plant`
- `CatalogPlant`
- `PlantCare`
- `TaskCalendar`

Papildomai PA27 kontekste naudojami `Task`, `WeatherForecast` ir `TaskResourceRequirement`, bet diagramoje jie nerodomi, nes PA27 fokusuojasi i bukles/fazes identifikavima, o ne i pilna kalendoriaus persistinima.

## 5. PA27 realizacijos tipas

PA27 realizuotas kaip vidinis backend service procesas, kvieciamas rekomendacinio kalendoriaus generavimo metu. Jis turi UI inicijavimo kelia per `PlotCalendarPage`, taciau nera atskiro "Identifikuoti augalo bukle" puslapio ar atskiro REST endpointo.

## 6. Is kokiu PA / procesu kvieciamas

Patvirtintas aktyvus kvietimas:

- rekomendacinio kalendoriaus generavimas: `CalendarGenerationService::generateCalendar()` -> `PlantLifecyclePhaseService::resolveSimulatedPhase(plant, care, generationDate)`.

Susije, bet ne tas pats PA27 kvietimas:

- augalo perziura: `PlantController::show()` -> `PlantLifecycleService::buildSummary()`.
- lifecycle review task completion: `PlantLifecycleService::completeReviewTask()` sukuria bukles istorijos irasa, bet neidentifikuoja simuliuojamos fazes.

## 7. Tikrinamos dienos gavimas

Nera atskiro `getCheckDate()` metodo. Tikrinama data ateina kaip:

- `start_date` / `end_date` is `GenerateCalendarRequest`;
- `CalendarGenerationService::generateCalendar()` viduje per `CarbonPeriod::create(...)`;
- konkretus ciklo elementas perduodamas kaip `generationDate` parametras i `resolveSimulatedPhase(plant, care, generationDate)`.

## 8. Pasodinimo datos gavimas

Pasodinimo data gaunama is `Plant::plant_date`:

- `CalendarGenerationService::generateCalendar()` tikrina `generationDate->lt($plant->plant_date->copy()->startOfDay())`;
- `PlantLifecyclePhaseService::resolveSimulatedPhase()` skaiciuoja `plant_date->copy()->startOfDay()->diffInDays(...)`.

## 9. Augalo prieziuros informacijos gavimas

Prieziuros profilis gaunamas per:

- `PlantCareService::resolveEffectivePlantCare(plant)`;
- `PlantCareService::ensureLinkedCareProfile(plant, speciesId)`;
- `CatalogPlant::plantCare()`;
- veliau cikle naudojamas `Plant::effectivePlantCare()`.

## 10. Dienu nuo pasodinimo skaiciavimas

`PlantLifecyclePhaseService::resolveSimulatedPhase()` naudoja:

```php
$plant->plant_date->copy()->startOfDay()->diffInDays($forDate->copy()->startOfDay(), false)
```

Svarbu: `false` leidzia gauti neigiama reiksme, jeigu data yra pries pasodinima.

## 11. "Dar nepasodintas" atvejis

Aktyviame kalendoriaus generavimo kelyje `CalendarGenerationService::generateCalendar()` pries lifecycle kvietima turi:

```php
if ($generationDate->lt($plant->plant_date->copy()->startOfDay())) {
    continue;
}
```

Todel pries pasodinima nera ligos tikrinimo ir nera timeline skaiciavimo. `PlantLifecyclePhaseService::resolveSimulatedPhase()` taip pat turi defensyvu `$elapsedDays < 0` kelia, kuris grazina `simulated_phase => null`, bet kalendoriaus kelyje jis paprastai nepasiekiamas del ankstesnio `continue`.

## 12. Ligos / isskirtines bukles tikrinimas

Aktyviame kalendoriaus kelyje liga tikrinama `CalendarGenerationService::buildBaseActions()`:

```php
if ($plant->disease || $actualCondition === ConditionType::Diseased->value) {
    return [[ ... spray treatment action ... ]];
}
```

`PlantLifecyclePhaseService::isExceptionalManualCondition()` pazymi `diseased` ir `dried` kaip isskirtines manualines bukles lauke `is_exceptional_actual_condition`.

## 13. Lifecycle timeline sudarymas

Timeline sudaro `PlantLifecyclePhaseService::buildTimeline(PlantCare $care)` is siu realiu `PlantCare` lauku:

- `germinating_duration_days`
- `growing_duration_days`
- `flowering_duration_days`
- `mature_duration_days`
- `regenerating_duration_days`

Jei trukmes nesukonfiguruotos, service prideda numatytaja `mature` faze.

## 14. Bukles nustatymas pagal einamaja diena

`PlantLifecyclePhaseService::resolvePhaseFromTimeline(array $timeline, int $elapsedDays)` grazina einamaja faze. Galimos realios `ConditionType` reiksmes:

- `diseased`
- `dried`
- `flowering`
- `germinating`
- `growing`
- `mature`
- `planted`
- `regenerating`

## 15. Route / API helper sluoksniai, nerodomi lifeline

Rasti, bet specialiai nerodomi diagramoje:

- `backend/routes/api.php`: `POST /plots/{plot}/calendars` -> `CalendarController::store()`;
- `frontend/src/lib/api.js`: `generateCalendar(plotId, payload)`;
- `frontend/src/App.jsx`: React Router konfiguracija.

## 16. Galutines diagramos zinuciu skaicius

Galutineje diagramoje yra 100 zinuciu.

## 17. Ar diagrama padalinta

Diagrama nepadalinta i kelias dalis. Parodytas vienas realus PA27 inicijavimo kelias: rekomendacinio kalendoriaus generavimas.

## 18. Loop / alt / opt fragmentai

Panaudoti fragmentai:

- `loop [for each plant being prepared]`
- `loop [for each generation date]`
- `loop [for each plant in generation date]`
- `alt Check date relative to planting date`
- `loop [for each configured lifecycle duration in PlantCare]`
- `alt Current disease condition`

`opt` fragmentu nera.

## Reply rodykliu taisykle

- Kiekvienas sinchroninis call turi velesni reply.
- Reply nera automatiskai po call; jis grizta tada, kai kvieciamas objektas baigia savo vidine logika.
- UI event / sisteminis startas gali buti async ir be reply.
- Diagramoje vienintelis async startas yra `Garden owner -> PlotCalendarPage: handleGenerate(event)`.

## Alt fragmentu taisykle

- Kiekvienas `alt` turi operandus.
- Kiekvienas operandas turi guard.
- "Dar nepasodintas" sakoje nevykdomas nei ligos tikrinimas, nei timeline skaiciavimas: realus kodas naudoja `continue`.
- "Serga" sakoje `buildBaseActions()` grazina gydymo / purskimo veiksmus ir nevykdo iprasto intervalinio lifecycle veiksmu sudarymo to metodo viduje.
- Bendri veiksmai, tokie kaip `plant_date` gavimas ir datos palyginimas, iskelti pries `alt`.

## Visu seku sarasas HTML puslapio apacioje

HTML apacioje pateiktas pilnas seku sarasas su:

- Is;
- I;
- Operacija;
- Jungties tipas;
- Fragmentas / pastaba;
- Replies to.

## Nepatvirtintos / ribines vietos

- `PlantStateService::simulatePlantState()` turi procesui artima logika, iskaitant `not_planted` ir `is_diseased`, bet codebase paieska nerado realaus kvietimo, todel jis laikomas neaktyviu / nenaudojamu keliu.
- Codebase neturi atskiro `buildNotPlantedResult()`, `checkCurrentCondition()`, `markDiseaseState()` ar `buildConditionResult()` metodo. Diagramoje rodomi realus atitikmenys: `continue`, `isExceptionalManualCondition()`, `buildBaseActions()` ir `simulated_state` masyvo grazinimas.
