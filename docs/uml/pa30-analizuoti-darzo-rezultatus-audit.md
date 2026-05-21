# PA30 „Analizuoti daržo rezultatus“ audit

## 1. Frontend boundary

- `PlotsPage` (`frontend/src/pages/plot/PlotsPage.jsx`) rodo sklypų planų sąrašą ir turi realią nuorodą į `/plots/${plot.id}/analytics`.
- `PlotAnalyticsPage` (`frontend/src/pages/plot/PlotAnalyticsPage.jsx`) yra pagrindinis PA30 boundary. Jis per `useAsyncData()` kviečia `api.listPlots()` ir `api.getPlot(plotId)`, leidžia pasirinkti analizės tipus per `toggleType(type)`, inicijuoja analizę per `handleGenerate(event)` ir ataskaitą renderina per `renderSection(type, section)`.

## 2. Controlleriai

- `PlotController` (`backend/app/Http/Controllers/Plot/PlotController.php`) dalyvauja pradiniame sklypų sąrašo ir pasirinkto sklypo gavime: `index()` ir `show()`.
- `AnalyticsController` (`backend/app/Http/Controllers/Plot/AnalyticsController.php`) dalyvauja analizės generavime: `store()` ir `show()` abu kviečia `AnalyticsService::analyzePlot()`.
- `GenerateAnalyticsRequest` (`backend/app/Http/Requests/Plot/GenerateAnalyticsRequest.php`) nėra controlleris, bet yra realus control sluoksnio validavimo objektas. Jis tikrina `analysisTypes` per `rules()` ir grąžina pasirinktus tipus per `analysisTypes()`.

## 3. Service

- `AccessService` tikrina prieigą ir grąžina pasiekiamų sklypų sąrašą: `accessiblePlotIds()`, `getUserRoleForPlot()`, `userHasAccess()`.
- `AnalyticsService` yra pagrindinis PA30 service: `analyzePlot()`, `normalizeAnalysisTypes()`, `preparePlot()`, `buildPlanningSection()`, `buildPlantConditionSection()`, `buildHarvestSection()`, `buildSummary()`.
- `PlotSnapshotService` pateikia planavimo istorijos / plano versijų duomenis per `listForPlot()`.
- `PlantConditionHistoryService` pateikia augalų būklės istorijos kontekstą per `listForPlot()`. PA30 diagramoje šis gavimas rodomas kaip `ref PA14`.
- `HarvestService` pateikia derliaus įrašus per `listForPlot()`.

## 4. Entity / modeliai

- `Plot`
- `AccessRight`
- `AnalysisType`
- `plot_snapshots` lentelė, pasiekiama per `PlotSnapshotService`; Eloquent modelio nėra.
- `RotationHistory`
- `Task`
- `HarvestRecord`

`PlantConditionHistory` realiai dalyvauja PA14 ir `PlantConditionHistoryService::listForPlot()`, bet PA30 lifeline vietoje jo naudojamas `ref PA14`, kad PA14 vidinė seka nebūtų išskleista.

## 5. Realiai palaikomi analizės tipai

`AnalysisType` enum palaiko tris tipus:

- `planning`
- `plant_condition`
- `harvest`

Frontend `ANALYSIS_OPTIONS` turi tas pačias tris reikšmes. Backend testai `AnalyticsTest` patvirtina visų trijų tipų generavimą ir kelių tipų generavimą viename atsakyme.

## 6. Planavimo sprendimų analizė

Planavimo analizė realizuota `AnalyticsService::buildPlanningSection()`. Duomenys surenkami per:

- `PlotSnapshotService::listForPlot($plot, 500)` iš `plot_snapshots`;
- `AnalyticsService::plotRotationHistoryQuery($plot)` iš `RotationHistory`.

Rodikliai skaičiuojami realiais metodais:

- `buildPlanningHistoryMetrics()`;
- `buildZoneSeasonSelections()`;
- `buildRotationHistoryMetrics()`;
- `detectRotationViolations()`.

## 7. Augalų būklės analizė ir PA14 ref

Augalų būklės analizė realizuota `AnalyticsService::buildPlantConditionSection()`. Istorijos gavimo vieta realiame kode yra `PlantConditionHistoryService::listForPlot($plot)`, tačiau PA30 diagramoje ji pateikta kaip `ref PA14 Peržiūrėti augalo būklės istoriją`, nes PA14 turi atskirą sekų diagramą.

Po `ref` PA30 rodo tik PA30 lygio apdorojimą:

- `plotTasksQuery($plot)` ir `Task` užklausą priežiūros veiksmams;
- `buildPlantConditionSection()`;
- `groupBy('plant_id')`;
- `sortBy(measured_at_and_id)`;
- `conditionValue()`;
- `conditionScore()`;
- `isCriticalDeterioration()`;
- `serializeConditionEntry()`;
- `normalizeConditionCounts()`.

PA14 vidinis controller / resource / model srautas šiame PA30 faile neišskleistas.

## 8. Derliaus analizė

Derliaus analizė realizuota `AnalyticsService::buildHarvestSection()`. Duomenys surenkami per:

- `HarvestService::listForPlot($plot)`;
- `HarvestRecord::query()->with(['plant.plantZone', 'task'])->where('plot_id', ...)`;
- `plotTasksQuery($plot)` ir `Task` užklausą derliaus tipo užduotims.

Rodikliai skaičiuojami per `groupBy('plant_id')`, `groupBy(harvested_on_period)`, `sum(quantity)` ir `buildPeriodTrend()`.

## 9. Route / API helper sluoksniai, nerodomi lifeline

Rasti, bet sąmoningai nerodomi kaip lifeline:

- `backend/routes/api.php`: `GET /plots`, `GET /plots/{plot}`, `GET /plots/{plot}/analytics`, `POST /plots/{plot}/analytics`.
- `frontend/src/lib/api.js`: `listPlots()`, `getPlot(plotId)`, `getPlotAnalytics(plotId)`, `generatePlotAnalytics(plotId, payload)`.
- `frontend/src/App.jsx`: React Router kelias `plots/:plotId/analytics`.

## 10. Žinučių skaičius

Galutinėje diagramoje yra 128 žinutės:

- 60 sinchroninių call;
- 61 reply;
- 6 async/UI event;
- 1 ref žinutės įrašas.

## 11. Diagramos suskaidymas

HTML puslapis padalintas į 2 dalis:

- analizės puslapio atidarymas ir sklypo plano pasirinkimas;
- analizės tipo vykdymas ir ataskaitos pateikimas.

## 12. Fragmentai

Naudoti fragmentai:

- `loop [for each visible plot]`;
- `loop [for each selected analysis type]`;
- `alt Analysis type selection`;
- `ref PA14 Peržiūrėti augalo būklės istoriją`;
- `loop [for each plant with condition history]`;
- `loop [for each generated section]`.

`opt` fragmentų nėra, nes realiame `PlotAnalyticsPage` nėra atskiro ataskaitos uždarymo metodo ar UI veiksmo, kurį būtų saugu rodyti kaip realų `closeAnalyticsReport()`.

## 13. Reply rodyklių taisyklė

- Kiekvienas sinchroninis `call` turi vėlesnį `reply`.
- `reply` nėra automatiškai iškart po `call`; jis grįžta po vidinės logikos.
- `AnalyticsController::store()` reply grįžta tik po `AnalyticsService::analyzePlot()` ir `AnalyticsResource::make()`.
- UI event yra `async` ir gali neturėti reply.
- Patikrinimas: calls without return = 0.

## 14. Alt fragmentų taisyklė

- `alt` turi tris operandus.
- Kiekvienas operand turi guard:
  - `[planning decision analysis selected]`;
  - `[plant condition analysis selected]`;
  - `[harvest analysis selected]`.
- Kadangi realus kodas leidžia pasirinkti kelis tipus, `alt` yra `loop [for each selected analysis type]` viduje.
- Bendri veiksmai `buildSummary()`, `AnalyticsResource::make()`, `setAnalytics()` ir `renderSection()` iškelti po `alt`.
- Vietose, kur realių alternatyvų nėra, `opt` nenaudotas.

## 15. Ref fragmento taisyklė

- PA14 naudojamas kaip `ref`, kai pasirenkama augalų būklės analizė.
- PA14 vidinė seka PA30 diagramoje neišskleidžiama.
- PA30 parodo tik `AnalyticsService` priklausomybę nuo būklės istorijos konteksto ir grįžtantį `condition_history_context`.
- `connections.txt` nurodo, kad ref remiasi atskira `docs/uml/pa14-perziureti-augalo-bukles-istorija-sequence.html` diagrama.

## 16. Visų sekų sąrašas HTML puslapio apačioje

HTML apačioje pateiktas pilnas sekų sąrašas, sugeneruotas iš tų pačių `messages` ir `fragments` struktūrų kaip SVG diagrama. Sąraše yra:

- `Iš`;
- `Į`;
- `Operacija`;
- `Jungties tipas`;
- `Fragmentas / pastaba`;
- `Replies to`.

Sąraše taip pat matosi `loop pradžia`, `loop pabaiga`, `alt pradžia`, operandų pradžia / pabaiga, `alt pabaiga`, `ref pradžia` ir `ref pabaiga`.

## Nepatvirtintos / interpretuotos vietos

- Veiklos diagramoje minimas „sklypo naudingumo rodiklių“ skaičiavimas realiame kode nėra atskiras metodas tokiu pavadinimu. Diagrama naudoja realų atitikmenį `buildSummary()` ir `getPlotSummaryMetrics()`.
- `plot_snapshots` yra reali lentelė ir `PlotSnapshotService` ją naudoja, bet Eloquent modelio `PlotSnapshot` nėra. Todėl lifeline pavadintas `plot_snapshots`, o ne neegzistuojančia klase.
- PA30 realiame kode gali generuoti kelias analizės sekcijas vienu užklausos vykdymu. UML tai modeliuoja `loop [for each selected analysis type]` aplink privalomą analizės tipo `alt`.
