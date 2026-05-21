# PA6 Valdyti sklypo planą - codebase auditas

## 1. Frontend boundary dalyviai
Rasti realūs PA6 boundary: PlotsPage, PlotCreatePage, PlotDetailPage, PlotSectionNav, PlotEditPage, PlotDesignerCanvas, PlotLocationMap, PlotPlantingDrawer, PlantFormPage. Pagrindinis sąrašas yra `PlotsPage`, naujas planas kuriamas `PlotCreatePage`, esamas planas redaguojamas `PlotDetailPage` su `PlotDesignerCanvas`, `PlotLocationMap`, `PlotPlantingDrawer` ir `PlotSectionNav`. Metaduomenims naudojamas `PlotEditPage`, o augalo redagavimui - `PlantFormPage`.

## 2. Controlleriai
Dalyvauja: PlotController, WorkspaceController, SchemeController, PlantController, CatalogPlantController. `routes/api.php` tik susieja URL su šiais controlleriais ir diagramoje kaip lifeline nerodomas.

## 3. Service dalyviai
Dalyvauja: AccessService, PlotWorkspaceService, PlotSnapshotService, CatalogPlantService, PlantCareService. `PlotWorkspaceService::commitDraft()` yra pagrindinis esamo plano juodraščio išsaugojimo taškas; `PlotSnapshotService` fiksuoja planavimo istoriją.

## 4. Entity/modeliai
Dalyvauja: Plot, PlantZone, Plant, CatalogPlant, PlantCare, AccessRight. `plot_snapshots` lentelė naudojama per `PlotSnapshotService`, bet Eloquent modelio codebase nėra, todėl lifeline neįtraukta.

## 5. Sklypų sąrašo gavimas
`PlotsPage` kviečia `api.listPlots()`; backend'e tai `PlotController::index()`. Controlleris kviečia `AccessService::accessiblePlotIds()`, tada `Plot::query()->whereIn()->withCount(['plantZones','plants'])->get()` ir kiekvienam sklypui prideda `AccessService::getUserRoleForPlot()`.

## 6. Esamo sklypo plano atidarymas
`PlotDetailPage` krauna `api.getPlot(plotId)`, `api.listPlantZones(plotId)` ir `api.listPlants(plotId)`. Tai atitinka `PlotController::show()`, `SchemeController::index()` ir `PlantController::index()`.

## 7. Naujo sklypo plano kūrimas
`PlotCreatePage::handleSave()` pirmiausia sanitizuoja geometriją per `assertSanitizedGeometryPayload()`, tada kviečia `PlotController::store()`. Jei yra zonų, po sklypo sukūrimo kviečiamas `WorkspaceController::update()`, kuris per `PlotWorkspaceService::commitDraft()` sukuria zonas.

## 8. Sklypo ribų žymėjimas ir validavimas
Ribos žymimos `PlotLocationMap` ir `PlotDesignerCanvas` įvykiais. Frontend tikrina `isBoundaryReady`, `handleBoundaryClose()`, `assertSanitizedGeometryPayload()`; backend validuoja `geometry` per `NormalizedGeometry::validationRule()` controllerių `validate()` kvietimuose.

## 9. Zonų žymėjimas, redagavimas ir validavimas
Zonos kuriamos/redaguojamos `PlotDesignerCanvas::createZoneFromShape()`, `resolveNewZoneShape()`, `isZonePlacementValid()`, `handleZoneApply()` ir `handleZoneDelete()`. Backend išsaugojimas eina per `WorkspaceController::update()` ir `PlotWorkspaceService::commitDraft()`; atskiri `SchemeController::store/update/destroy` endpointai egzistuoja, bet pagrindinis editorius naudoja workspace commit.

## 10. Augalo pridėjimas į zoną
`PlotPlantingDrawer` krauna katalogą per `CatalogPlantController::index()`, parodo `plantCare` per `CatalogPlant::plantCare()`, o pasirinktas augalas dedamas į `PlotDetailPage` juodraštį per `handlePlantCreate()`. Persistavimas vyksta `PlotWorkspaceService::commitDraft()`, kur kuriamas `Plant`, prireikus kviečiami `CatalogPlantService` ir `PlantCareService`.

## 11. Augalo redagavimas
Atskirame puslapyje `PlantFormPage` naudoja `PlantController::showGlobal()` ir `PlantController::updateGlobal()`. Validacijai naudojamas realus `validateGlobalPlantPayload(request, true)`; invalid šakoje `Plant::update()` nevykdomas.

## 12. Augalo šalinimas
Pagrindiniame sklypo editoriuje augalas pašalinamas iš juodraščio per `PlotDetailPage::handlePlantDelete()`, o DB šalinimas įvyksta workspace commit metu per `Plant::whereIn(...)->delete()`. `PlantController::destroy()` ir `destroyGlobal()` taip pat egzistuoja API, bet pagrindinis PA6 editorius naudoja juodraščio commit modelį.

## 13. Plano duomenų redagavimas
Metaduomenys redaguojami `PlotEditPage`; jis kviečia `PlotController::update()`, kuris tikrina `AccessService::userCanEdit()`, validuoja payload ir vykdo `Plot::update()` bei `PlotSnapshotService::capture(..., 'plot_updated')`.

## 14. Sklypo plano šalinimas
`PlotEditPage::handleDelete()` kviečia `PlotController::destroy()`. Controlleris naudoja `ensureUserOwnsPlot()` / `AccessService::userIsOwner()`, prieš `Plot::delete()` išsaugo `plot_deleted` snapshot. Susijusių zonų/augalų kaskados apibrėžtos migracijose, diagramoje rodomas realus `Plot::delete()` kvietimas.

## 15. PA6 naudoja ref PA10 Eksportuoti planą į PDF
PA6 turi `ref PA10 Eksportuoti planą į PDF` specialių veiksmų dalyje: `PlotDetailPage` mygtukas kviečia `api.downloadPlotPdf(plotId, name)`, route nukreipia į `ExportController::pdf()`, bet PA10 vidinės sekos PA6 diagramoje neišskleistos.

## 16. Route/API helper sluoksniai nerodomi lifeline
Rasti `backend/routes/api.php`, `frontend/src/lib/api.js`, `apiClient`, React Router keliai `frontend/src/App.jsx`. Jie paminėti audite/source, bet diagramoje nerodomi kaip lifeline pagal taisykles.

## 17. Žinučių kiekis
Galutinėje diagramoje yra 270 žinutės.

## 18. Diagramų skaidymas
HTML padalintas į 4 dalis: sąrašas/atidarymas, naujo plano kūrimas, esamo plano redagavimas, specialūs veiksmai.

## 19. Loop / alt / opt / ref fragmentai
Panaudota: alt=12, loop=4, opt=3, ref=1. Visi alt turi operandus su guard sąlygomis.

## 20. Ref fragmento taisyklė
PA10 naudojamas kaip ref, kai naudotojas pasirenka eksportuoti planą į PDF. PA10 vidinės sekos PA6 diagramoje neišskleidžiamos.

## 21. Reply rodyklių taisyklė
Kiekvienas sinchroninis call turi vėlesnį reply. Reply nėra automatiškai po call: jis grįžta po vidinės logikos. UI event yra async ir gali neturėti reply. Connections santraukoje `Calls without return` = 0.

## 22. Alt fragmentų taisyklė
Alt fragmentai turi operandus ir kiekvienas operandas turi guard. Invalid ribų, zonų, workspace payload, augalo duomenų ir plano duomenų šakose DB `create/update/delete` veiksmai nevykdomi; bendri veiksmai iškelti prieš arba po alt.

## 23. Visų sekų sąrašas HTML puslapio apačioje
HTML apačioje pateiktas pilnas sekų sąrašas su stulpeliais: Iš, Į, Operacija, Jungties tipas, Fragmentas / pastaba ir Replies to. Sąraše matomos loop / alt / opt / ref pradžios ir pabaigos.

## Nepatvirtintos vietos
Neaptikta nepatvirtintų lifeline pavadinimų. Architektūrinė pastaba: individualūs `SchemeController::store/update/destroy` ir `PlantController::store/update/destroy` endpointai egzistuoja, bet pagrindinis sklypo plano editorius realiai persistuoja pakeitimus per `WorkspaceController::update()` ir `PlotWorkspaceService::commitDraft()`.
