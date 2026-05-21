# PA10 Eksportuoti planą į PDF - codebase auditas

## 1. Realūs frontend boundary

- `PlotDetailPage` - realus PDF eksportavimo mygtukas yra `frontend/src/pages/plot/PlotDetailPage.jsx:902`, su `onClick={() => api.downloadPlotPdf(plotId, pageState.data.plot?.name)}`.
- Failo atsisiuntimo mechanika įgyvendinta `downloadBlob()` helperyje `frontend/src/lib/api.js:101`, bet `apiClient` ir helper lifeline diagramoje nerodomi pagal sekų diagramų taisykles.
- Atskiro `PlotExportPage`, `PdfDownloadPage` ar specialaus success UI PA10 srautui nerasta.

## 2. Controlleriai

- `ExportController` (`backend/app/Http/Controllers/Plot/ExportController.php:17`) turi metodą `pdf(Request $request, Plot $plot, PdfExportService $pdfExportService, AccessService $accessService): Response`.
- Controlleris kviečia `ensureUserCanViewPlot()` (`ExportController.php:23`) ir, kai prieiga leidžiama, `PdfExportService::exportPlotReport()` (`ExportController.php:25`).

## 3. Service

- `AccessService` - naudojamas `userHasAccess()`, `userIsOwner()` ir `sharedAccessQuery()`.
- `PdfExportService` - pagrindinis PDF eksportavimo service.
- `AnalyticsService` - PDF ataskaitai generuoja analitikos ir užduočių metrikas.
- `PlotSnapshotService`, `PlantConditionHistoryService`, `HarvestService` - naudojami per `AnalyticsService::buildContext()`.
- `Dompdf` - išorinė PDF biblioteka, diagramoje rodoma kaip «service».

## 4. PDF generavimo mechanizmas

Naudojamas `dompdf/dompdf` (`backend/composer.json:10`). `PdfExportService` importuoja `Dompdf\Dompdf` ir `Dompdf\Options`, sukuria `new Dompdf($options)`, kviečia `loadHtml($html, 'UTF-8')`, `setPaper('A4', 'portrait')`, `render()` ir `output()` (`backend/app/Services/Plot/PdfExportService.php:46-50`).

## 5. Entity / modeliai

Galutinėje diagramoje naudojami tik realūs modeliai:

- `GardenOwner`
- `AccessRight`
- `Plot`
- `PlantZone`
- `Plant`
- `CatalogPlant`
- `PlantCare`
- `TaskCalendar`
- `Task`
- `RotationHistory`
- `PlotSnapshot`
- `PlantConditionHistory`
- `HarvestRecord`

## 6. PDF dokumento duomenys

`pdf.plot-report` Blade šablonui perduodama:

- `plot`
- `analytics`
- `task_metrics`
- `recent_calendars`
- `recent_tasks`
- `recent_rotation_history`
- `recent_condition_history`
- `generated_at`
- `plan_preview`

Šie duomenys surenkami `PdfExportService::renderPlotReportHtml()` ir `loadPlotReportData()`.

## 7. PA10 realizacijos tipas

PA10 realizuotas kaip frontend mygtukas sklypo puslapyje, kuris kviečia backend export endpointą. Tai nėra atskiras UI puslapis. Endpointas:

- `GET /api/plots/{plot}/export/pdf`
- route source: `backend/routes/api.php:65`
- frontend helper source: `frontend/src/lib/api.js:372`

## 8. Naudotojo prieigos tikrinimas

`ExportController::pdf()` kviečia `ensureUserCanViewPlot()`. Šis metodas:

- iš `Request` išsprendžia `GardenOwner` per `resolveGardenOwner()`;
- kviečia `AccessService::userHasAccess($owner, $plot)`;
- jei rezultatas `false`, kviečiamas `abort_unless(..., 403, 'Neturite teises perziureti sio sklypo.')`.

Testas `PdfExportTest::test_unauthorized_user_gets_403_on_export()` patvirtina `403` atsaką.

## 9. Pasirinkto sklypo / plano duomenų gavimas

`Plot $plot` gaunamas Laravel route model binding lygyje. Sklypo PDF duomenys kraunami `PdfExportService::loadPlotReportData()` per `load()` su ryšiais:

- `plantZones` su `withCount('plants')`;
- `plants` su `plantZone`, `catalogPlant.plantCare`, `conditionHistory`;
- `taskCalendars` su `tasks`, o užduotims papildomai kraunamas `plant` ir `plant.plantZone`.

Papildomai `renderPlotReportHtml()` gauna `recent_rotation_history` iš `RotationHistory::query()`.

## 10. PDF HTML / view sugeneravimas

HTML generuojamas per Blade:

- `view('pdf.plot-report', [...])->render()`
- source: `backend/app/Services/Plot/PdfExportService.php:101-111`
- view source: `backend/resources/views/pdf/plot-report.blade.php`

## 11. PDF failo atsisiuntimo atsakas

`PdfExportService::exportPlotReport()` grąžina `response($pdfOutput, 200, [...])` su antraštėmis:

- `Content-Type: application/pdf`
- `Content-Disposition: attachment; filename="plot-report.pdf"`
- `Content-Length`
- `Cache-Control`

Tai patvirtinta `backend/tests/Feature/PdfExportTest.php:82-84`.

## 12. PDF generavimo klaidos apdorojimas

Specialios PDF generavimo klaidos šakos nerasta: `PdfExportService` neturi `try/catch` aplink Dompdf generavimą. Todėl galutinėje diagramoje nėra `PDF generation failed` alt šakos. Jei Dompdf išmestų exception, ji būtų tvarkoma globaliu Laravel exception mechanizmu.

## 13. Route / API helper sluoksniai, nerodomi lifeline

Šie sluoksniai rasti ir naudoti auditui, bet diagramoje nerodomi:

- `routes/api.php`
- `apiClient`
- `api.downloadPlotPdf()`
- `downloadBlob()`
- Axios response interceptoriai
- Laravel router / route model binding
- Eloquent query-builder

## 14. Žinučių kiekis

Galutinėje diagramoje yra 99 žinutės.

## 15. Diagramos padalijimas

Diagrama nepadalinta į kelias dalis. PA10 pateiktas vienoje diagramoje: PDF eksportavimo inicijavimas, prieigos patikrinimas, duomenų gavimas, HTML/PDF generavimas ir failo atsisiuntimo pradėjimas.

## 16. Loop / alt / opt fragmentai

- `alt: Export access check`
  - `[access granted]` - tęsiamas PDF duomenų gavimas ir generavimas.
  - `[access denied]` - PDF negeneruojamas, grąžinamas `403`.
- `opt: [owner_match is false]`
  - rodo, kad `sharedAccessQuery(...).exists()` reikalingas tik tada, kai naudotojas nėra sklypo savininkas.
- `loop: [for each plant zone included in PDF plan preview]`
  - rodo zonų geometrijų konvertavimą į SVG taškus per `toSvgPoints()`.

## 17. Reply rodyklių taisyklė

- Kiekvienas sinchroninis call turi vėlesnį reply.
- Reply nėra automatiškai po call.
- Reply grįžta tada, kai kviečiamas objektas baigia savo vidinę logiką.
- UI event `clickExportPdf(plotId)` yra asinchroninis ir neturi reply.
- Galutinėje jungčių santraukoje `Calls without return: 0`.

## 18. Alt fragmentų taisyklė

- `alt` turi operandus.
- Kiekvienas operandas turi guard.
- `[access denied]` šakoje PDF negeneruojamas.
- `PDF generation failed` šaka nerodoma, nes tokio specialaus klaidos apdorojimo kode nėra.
- Sklypo neradimo šaka nerodoma kaip `alt`, nes `Plot $plot` sprendžiamas Laravel route model binding lygyje.

## 19. Visų sekų sąrašas HTML puslapio apačioje

HTML apačioje pateiktas pilnas visų sekų sąrašas su:

- `Iš`
- `Į`
- `Operacija`
- `Jungties tipas`
- `Fragmentas / pastaba`
- `Replies to`

Sąraše taip pat matosi `alt`, `opt` ir `loop` pradžios bei pabaigos žymos.

## 20. Nepatvirtintos / framework lygio vietos

- `plot not found` nėra atskiras projekto metodas; tai Laravel route model binding 404 elgsena.
- `forbidden_response` formuoja Laravel `abort_unless()` / exception pipeline, ne atskiras projekto service.
- `PlotDetailPage` neturi specialaus PDF eksportavimo success toast. Sėkmė realizuota kaip naršyklės failo atsisiuntimo atsakas per programinį `link.click()`.
