# PA11 Valdyti rekomendacin? veiksm? kalendori? - codebase auditas

## Frontend boundary
`PlotCalendarPage` valdo kalendori? (`handleGenerate`, `handleDayClick`, `handleTaskAction`). `PlantDetailPage` naudojamas lifecycle review ?akai per `pendingReviewTask` ir `handleReviewSubmit`.

## Controlleriai
`CalendarController::index`, `store`, `show`; `TaskController::index`, `complete`, `reject`; `GenerateCalendarRequest` validuoja interval?.

## Service
`AccessService`, `TaskCalendarService`, `CalendarGenerationService`, `WeatherService`, `PlantCareService`, `PlantLifecyclePhaseService`, `PlantLifecycleService`, `InventoryService`, `TaskInventoryCoverageService`, `TaskWorkflowService`, `PlantConditionHistoryService`.

## Entity/modeliai
`Plot`, `Plant`, `CatalogPlant`, `PlantCare`, `TaskCalendar`, `WeatherForecast`, `Task`, `TaskResourceRequirement`, `InventoryItem`, `InventoryUsageLog`, `PlantConditionHistory`; `PlantZone` per ry?ius.

## Realizacijos santrauka
Kalendori? s?ra?as gaunamas per `Plot::taskCalendars()->withCount('tasks')->get()`. Generavimas eina per `TaskCalendarService::generate()` ? `CalendarGenerationService::generateCalendar()`, kur u?kraunami plot/zones/plants, sprend?iami prie?i?ros profiliai, kvie?iamas PA12 ref `WeatherService::getForecastRange()`, cikle einama per datas ir augalus, PA27 ref vietoje kvie?iamas `PlantLifecyclePhaseService::resolveSimulatedPhase()`, sudaromi veiksmai, pritaikomos or? taisykl?s ir inventoriaus planavimas. Tr?kumo atveju naudojamas `buildDayBuyAction()`. Dienos veiksmai pateikiami per `TaskController::index()` ir `InventoryService::attachLiveTaskInventory()`. Veiksmas vykdomas per `TaskWorkflowService::complete()`, o med?iagos nura?omos PA17 ref vietoje per `InventoryService::consumeTaskRequirements()`.

## Ref fragment? taisykl?
PA12, PA27 ir PA17 pateikti kaip ref; j? vidin?s sekos PA11 diagramoje nei?skleistos.

## Reply rodykli? taisykl?
Kiekvienas sinchroninis call turi v?lesn? reply; UI event yra async ir gali netur?ti reply.

## Alt fragment? taisykl?
Visi alt turi operandus ir guard; kai n?ra dviej? alternatyv?, naudotas opt.

## Vis? sek? s?ra?as HTML puslapio apa?ioje
HTML apa?ioje pateiktas pilnas sek? s?ra?as su I?, ?, Operacija, Jungties tipas, Fragmentas / pastaba, Replies to.

## Nepatvirtintos vietos
Atskiros taisykl?s ?vienoje zonoje ne daugiau negu vienas augalas? PA11 kode neradau. `keep_current` b?kl?s per?i?roje realiai sukuria istorijos ?ra?? su esama b?kle. Routes ir API helperiai rasti, bet nerodomi lifeline.

## Skai?iai
?inut?s: 159; call: 68; return: 75; async: 13; ref ?ra?ai: 3; loop: 4; alt: 6; opt: 1; calls without return: 0.
