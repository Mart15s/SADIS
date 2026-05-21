# PA14 Peržiūrėti augalo būklės istoriją - auditas

## 1. Realūs frontend boundary

- `PlantDetailPage` (`frontend/src/pages/plant/PlantDetailPage.jsx`) yra realus PA14 naudotojo boundary. Puslapis per `useAsyncData()` kviečia `api.listPlantConditions(resolvedPlotId, plantId)` ir tame pačiame puslapyje rodo `Condition History` lentelę arba `EmptyState` pranešimą `No condition history`.
- Atskiro `PlantConditionHistoryPage` arba `PlantConditionHistoryPanel` codebase nerasta. Todėl diagramoje naudojamas tik realus `PlantDetailPage`.
- Sistemos / analizės scenarijuje frontend boundary nėra būtinas, nes modeliuojamas vidinis analizės duomenų surinkimas.

## 2. Realūs controlleriai

- `PlantConditionController` (`backend/app/Http/Controllers/Plant/PlantConditionController.php`) turi realų `index()` metodą istorijai gauti ir `store()` metodą istorijos įrašui sukurti.
- `ConditionHistoryController` egzistuoja kaip klasė, bet tik paveldi `PlantConditionController`; `routes/api.php` realiai nukreipia `/plots/{plot}/plants/{plant}/conditions` į `PlantConditionController`.
- `AnalyticsController` (`backend/app/Http/Controllers/Plot/AnalyticsController.php`) turi `store()` ir `show()`, kurie kviečia `AnalyticsService::analyzePlot()`.

## 3. Realūs service

- `AccessService` tikrina prieigą per `userHasAccess()`; controlleriai jį pasiekia per `AuthorizesPlotAccess::ensureUserCanViewPlot()`.
- `PlantConditionHistoryService` turi `listForPlant()`, `listForPlot()` ir `record()`.
- `AnalyticsService` turi `analyzePlot()`, `buildContext()`, `buildPlantConditionSection()`, `serializeConditionEntry()`, `conditionValue()` ir kitus analizės metodus.
- `CalendarGenerationService` realiai įkelia `plantZones.plants.conditionHistory` ir perduoda `plant->conditionHistory` į `PlantLifecycleService::buildActionsForDate()`. Atskiro `collectConditionHistoryForAnalysis()` metodo nėra.

## 4. Realūs entity/modeliai

- `Plant`
- `PlantConditionHistory`
- `Plot`
- `Task`

## 5. Naudotojo inicijuotas scenarijus

Naudotojas atidaro augalo peržiūrą / būklės istorijos bloką `PlantDetailPage`. Puslapis turi `plantId`, kviečia `api.listPlantConditions()`, o backend kelias yra `PlantConditionController::index()`. Controlleris patikrina prieigą, kviečia `PlantConditionHistoryService::listForPlant()`, šis per `Plant::conditionHistory()` gauna istoriją su `latest('measured_at')`, `latest('id')`, `get()`. Atsakymas serializuojamas per `PlantConditionHistoryResource::collection()`.

Frontend tada tikrina `pageState.data.conditions.length === 0`. Jei įrašai yra, renderinama lentelė. Jei nėra, rodomas `EmptyState` su tekstu `No condition history`.

## 6. Sistemos inicijuotas scenarijus

Realus analizės kelias naudoja `AnalyticsController::store()` -> `AnalyticsService::analyzePlot()` -> `buildContext()` -> `PlantConditionHistoryService::listForPlot()`. Istorija gaunama visam sklypui, o `buildPlantConditionSection()` ją analizuoja: tikrina `isEmpty()`, jei duomenų yra, grupuoja pagal `plant_id`, rūšiuoja pagal datą ir `id`, skaičiuoja pokyčius, trendus ir serializuoja `condition_timeline`. Jei duomenų nėra, grąžina `no_data` būklės analizės sekciją.

PA30 / rekomendacinio kalendoriaus kelias taip pat susijęs: `CalendarGenerationService::generateCalendar()` įkelia `plantZones.plants.conditionHistory`. Tačiau codebase neturi atskiro PA30 metodo, kuris būtų pavadintas `collectConditionHistoryForAnalysis()`, todėl galutinėje sistemos diagramoje naudotas patvirtintas `AnalyticsService` analizės kelias, o kalendoriaus sąsaja pažymėta kaip susijusi realizacijos vieta.

## 7. Route / API helper sluoksniai nerodomi lifeline

- `backend/routes/api.php`:
  - `GET /plots/{plot}/plants/{plant}/conditions`
  - `POST /plots/{plot}/analytics`
- `frontend/src/lib/api.js`:
  - `listPlantConditions(plotId, plantId)`
  - `generatePlotAnalytics(plotId, payload)`

Šie sluoksniai naudojami auditui, bet pagal taisykles nerodomi diagramoje kaip lifeline.

## 8. Žinučių skaičius

Galutinėje diagramoje yra 84 žinutės:
- naudotojo inicijuotas scenarijus: 1-28;
- sistemos inicijuotas analizės scenarijus: 29-84.

## 9. Diagramos padalijimas

Diagrama padalinta į dvi dalis:
- `Naudotojo inicijuota augalo būklės istorijos peržiūra`;
- `Sistemos inicijuotas būklės istorijos surinkimas analizei`.

Todėl atskiras `alt: PA14 start source` fragmentas HTML diagramoje nenaudotas. Abu PA pradžios scenarijai atskirti atskiromis diagramomis.

## 10. Loop / alt / opt fragmentai

- `alt: Plant condition history availability`
  - `[condition history exists]`
  - `[condition history does not exist]`
- `alt: Plant condition history availability for analysis`
  - `[condition history exists]`
  - `[condition history does not exist]`
- `loop: [for each plant with condition history]`
- `opt: [user closes condition history view]`

## Reply rodyklių taisyklė

- Kiekvienas sinchroninis call turi vėlesnį reply.
- Reply nėra automatiškai po call; jis grįžta po vidinės logikos.
- Reply grįžta tada, kai kviečiamas objektas baigia savo vidinius kvietimus.
- UI event ir sisteminis startas yra async ir gali neturėti reply.
- `connections.txt` santraukoje `Calls without return: 0`.

## Alt fragmentų taisyklė

- Kiekvienas `alt` turi operandus.
- Kiekvienas operand turi guard.
- Bendri veiksmai iškelti prieš arba po `alt`.
- Ten, kur nėra dviejų realių alternatyvų, naudotas `opt` (`[user closes condition history view]`).

## Visų sekų sąrašas HTML puslapio apačioje

HTML puslapio apačioje pateiktas pilnas sekų sąrašas su:
- Iš;
- Į;
- Operacija;
- Jungties tipas;
- Fragmentas / pastaba;
- Replies to.

Sąrašas generuojamas iš tos pačios `messages` ir `fragments` struktūros, iš kurios renderinamos diagramos.

## Nepatvirtintos arba specialiai pažymėtos vietos

- Nerastas atskiras `PlantConditionHistoryPage` / `PlantConditionHistoryPanel`; istorija yra `PlantDetailPage` dalis.
- Nerastas atskiras `PlantConditionHistoryController`; yra `ConditionHistoryController`, bet jis tik paveldi `PlantConditionController` ir nėra naudojamas route.
- Nerastas metodas `collectConditionHistoryForAnalysis()`. Vietoje jo realiai naudojami `PlantConditionHistoryService::listForPlot()` ir `AnalyticsService::buildPlantConditionSection()`.
- PA30 kalendoriaus generavimas įkelia `conditionHistory`, tačiau galutinėje sistemos diagramoje naudotas aiškesnis ir patvirtintas analizės kelias, nes jame istorija tikrai apdorojama kaip analizės kontekstas.
