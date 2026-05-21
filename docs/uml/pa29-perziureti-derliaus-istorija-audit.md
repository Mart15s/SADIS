# PA29 Peržiūrėti derliaus istoriją — codebase auditas

## 1. Realūs frontend boundary

- `PlotsPage` — pateikia sklypų / planų sąrašą per `useAsyncData(() => api.listPlots(), [], [])` ir renderina plot cards. Šaltinis: `frontend/src/pages/plot/PlotsPage.jsx:78-180`.
- `PlotHarvestsPage` — realus derliaus istorijos peržiūros ir registravimo puslapis. Atskiro `HarvestHistoryPage` codebase nėra. Šaltinis: `frontend/src/pages/plot/PlotHarvestsPage.jsx:27-230`.
- `PlotSectionNav` — reali sklypo posistemių navigacija, turi `harvests` tab ir back link į `/plots`. Šaltinis: `frontend/src/components/plot/PlotSectionNav.jsx:4-8`, `frontend/src/components/plot/PlotSectionNav.jsx:172`.
- `EmptyState` — realus tuščios derliaus istorijos pranešimo komponentas, naudojamas `PlotHarvestsPage`. Šaltinis: `frontend/src/pages/plot/PlotHarvestsPage.jsx:204-205`.

## 2. Controlleriai

- `PlotController::index()` — gauna naudotojui pasiekiamų sklypų sąrašą. Šaltinis: `backend/app/Http/Controllers/Plot/PlotController.php:18-34`.
- `PlotController::show()` — užkrauna pasirinkto sklypo planą su `plantZones` ir `plants`. Šaltinis: `backend/app/Http/Controllers/Plot/PlotController.php:60-66`.
- `PlantController::index()` — užkrauna pasirinkto sklypo augalus, reikalingus tame pačiame derliaus puslapyje. Šaltinis: `backend/app/Http/Controllers/Plant/PlantController.php:54-62`.
- `HarvestController::index()` — gauna pasirinkto sklypo derliaus įrašų istoriją. Šaltinis: `backend/app/Http/Controllers/Plot/HarvestController.php:19-30`.

## 3. Service

- `AccessService` — naudojamas pasiekiamiems sklypams ir peržiūros teisėms nustatyti: `accessiblePlotIds()`, `getUserRoleForPlot()`, `userHasAccess()`. Šaltinis: `backend/app/Services/Plot/AccessService.php:117-138`, `backend/app/Services/Plot/AccessService.php:155-177`.
- `HarvestService` — realus derliaus istorijos gavimo service per `listForPlot()`. Šaltinis: `backend/app/Services/Plot/HarvestService.php:19-30`.

## 4. Entity / modeliai

- `GardenOwner` — naudotojo sodo savininko kontekstas. Šaltinis: `backend/app/Models/GardenOwner.php:11`.
- `AccessRight` — bendrinamos prieigos įrašai. Šaltinis: `backend/app/Services/Plot/AccessService.php:161-163`, `backend/app/Services/Plot/AccessService.php:173-177`.
- `Plot` — sklypas / planas, turi `plantZones()`, `plants()`, `harvestRecords()`. Šaltinis: `backend/app/Models/Plot.php:38-68`.
- `Plant` — derliaus įrašo augalas, turi `plantZone()` ir `harvestRecords()`. Šaltinis: `backend/app/Models/Plant.php:60`, `backend/app/Models/Plant.php:99`.
- `PlantZone` — derliaus istorijoje rodoma per `zone_name`. Šaltinis: `backend/app/Models/PlantZone.php:47`, `backend/app/Http/Resources/Harvest/HarvestRecordResource.php:22`.
- `Task` — derliaus istorijoje rodoma per `task_name`, jei derlius susietas su užduotimi. Šaltinis: `backend/app/Models/Task.php:117`, `backend/app/Http/Resources/Harvest/HarvestRecordResource.php:24`.
- `HarvestRecord` — pagrindinis derliaus istorijos modelis. Šaltinis: `backend/app/Models/HarvestRecord.php:9-49`.

## 5. Sklypų / planų sąrašo gavimas

`PlotsPage` kviečia `api.listPlots()`, kuris siunčia `GET /api/plots`. Backend maršrutas ateina per `Route::apiResource('plots', PlotController::class)`. `PlotController::index()` iškviečia `AccessService::accessiblePlotIds($owner)`, tada `Plot::query()->whereIn(...)->withCount(['plantZones', 'plants'])->get()` ir kiekvienam sklypui prideda `access_role`.

Šaltiniai: `frontend/src/pages/plot/PlotsPage.jsx:79`, `frontend/src/lib/api.js:149-151`, `backend/routes/api.php:57`, `backend/app/Http/Controllers/Plot/PlotController.php:18-34`.

## 6. Pasirinkto sklypo derliaus istorijos gavimas

`PlotHarvestsPage` realiai užkrauna tris pasirinkto sklypo duomenų grupes: `api.getPlot(plotId)`, `api.listPlants(plotId)` ir `api.listHarvests(plotId)`. Derliaus istorijai svarbiausias kvietimas yra `api.listHarvests(plotId)`, kuris siunčia `GET /api/plots/{plot}/harvests` į `HarvestController::index()`. Controlleris kviečia `ensureUserCanViewPlot()` ir `HarvestService::listForPlot($plot, $owner, $request->query('plant_id'))`.

Šaltiniai: `frontend/src/pages/plot/PlotHarvestsPage.jsx:39-42`, `frontend/src/lib/api.js:364-366`, `backend/routes/api.php:104`, `backend/app/Http/Controllers/Plot/HarvestController.php:19-30`.

## 7. Kai derliaus įrašų yra

`HarvestService::listForPlot()` grąžina kolekciją iš `HarvestRecord::query()->with(['plant.plantZone', 'task'])->where('plot_id', $plot->id)->when(...)->orderByDesc('harvested_on')->orderByDesc('id')->get()`. `HarvestController::index()` ją paverčia į `HarvestRecordResource::collection($records)->resolve()`. Frontend šakoje `pageState.data.harvests.map((record) => ...)` renderina lentelės eilutes.

Šaltiniai: `backend/app/Services/Plot/HarvestService.php:23-29`, `backend/app/Http/Controllers/Plot/HarvestController.php:28-30`, `backend/app/Http/Resources/Harvest/HarvestRecordResource.php:10-30`, `frontend/src/pages/plot/PlotHarvestsPage.jsx:219-230`.

## 8. Kai derliaus įrašų nėra

Backend grąžina tą patį `data` lauką kaip tuščią kolekciją. Frontend tikrina `pageState.data.harvests.length === 0` ir rodo `EmptyState title="No harvest history" description="Register the first harvest record for this plot."`.

Šaltiniai: `backend/app/Http/Controllers/Plot/HarvestController.php:28-30`, `frontend/src/pages/plot/PlotHarvestsPage.jsx:204-205`.

## 9. Kito plano pasirinkimas tame pačiame UI sraute

Realiame backend nėra ciklo. UI lygiu naudotojas gali grįžti į `PlotsPage` per `PlotSectionNav` back link ir pasirinkti kitą sklypą / planą. Diagramoje `loop [until user finishes harvest history view]` atspindi šį kartotinį naudotojo pasirinkimą pagal veiklos diagramą, o ne atskirą backend ciklą.

Šaltiniai: `frontend/src/components/plot/PlotSectionNav.jsx:172`, `frontend/src/pages/plot/PlotsPage.jsx:137-180`.

## 10. Route / API helper sluoksniai, kurie nerodomi lifeline

- `backend/routes/api.php:57` — `Route::apiResource('plots', PlotController::class)`.
- `backend/routes/api.php:73` — `GET /plots/{plot}/plants`.
- `backend/routes/api.php:104` — `GET /plots/{plot}/harvests`.
- `frontend/src/lib/api.js:149-151` — `api.listPlots()`.
- `frontend/src/lib/api.js:157-159` — `api.getPlot(plotId)`.
- `frontend/src/lib/api.js:191-193` — `api.listPlants(plotId)`.
- `frontend/src/lib/api.js:364-366` — `api.listHarvests(plotId, params)`.
- `frontend/src/App.jsx:230-234` — React Router route į `PlotHarvestsPage`.

Šie sluoksniai sąmoningai nerodomi sekų diagramoje kaip lifeline, nes užduotis draudžia rodyti route, API helperius ir React Router.

## 11. Žinučių skaičius

Galutinėje diagramoje yra 101 žinutė:

- sinchroniniai call: 48;
- return / reply: 49;
- async / UI event: 4;
- calls without return: 0.

## 12. Diagramų skaidymas

PA29 pateiktas viena diagrama: „Derliaus istorijos peržiūros inicijavimas, plano pasirinkimas ir įrašų pateikimas“. Atskiros dalys neskaidytos, nes reali realizacija telpa į vieną `PlotsPage` + `PlotHarvestsPage` srautą.

## 13. Panaudoti loop / alt / opt fragmentai

- `loop: Harvest history browsing` su guard `[until user finishes harvest history view]`, žinutės 28-101.
- `alt: Harvest history availability`, žinutės 78-99:
  - operandas `[harvest records exist]`, žinutės 78-92;
  - operandas `[harvest records do not exist]`, žinutės 93-99.
- `opt: User finishes harvest history view` su guard `[user finishes harvest history view]`, žinutės 100-101.

## 14. Reply rodyklių taisyklė

Kiekvienas sinchroninis call turi vėlesnį reply. Reply nėra automatiškai po call: pvz., `HarvestController -> HarvestService: listForPlot(...)` atsakymas grįžta tik po visų `HarvestRecord` užklausos operacijų. Reply grįžta tada, kai kviečiamas objektas baigia savo vidinę logiką. UI event, pvz. `Garden owner -> PlotHarvestsPage: openHarvestHistory(plotId)`, yra asinchroninis ir neturi reply.

## 15. Alt fragmentų taisyklė

`alt` turi du operandus su atskirais guard:

- `[harvest records exist]` šakoje rodomas derliaus istorijos sąrašas per `map((record) => tableRow)`.
- `[harvest records do not exist]` šakoje rodomas tuščios istorijos pranešimas per `EmptyState(title, description)`.
- Bendri veiksmai, tokie kaip prieigos tikrinimas, `HarvestService::listForPlot()` ir `HarvestRecord::get()`, yra iškelti prieš `alt`.

## 16. Visų sekų sąrašas HTML puslapio apačioje

HTML apačioje pateiktas pilnas sekų sąrašas su:

- `Iš`;
- `Į`;
- `Operacija`;
- `Jungties tipas`;
- `Fragmentas / pastaba`;
- `Replies to`.

Sąrašas generuojamas iš tų pačių `messages` ir `fragments` struktūrų kaip SVG diagrama, todėl jame matosi `loop`, `alt`, `opt` pradžia, operandai ir pabaiga.

## 17. Neaptvirtintos / interpretuotos vietos

- Atskiro `HarvestHistoryPage` ar specialaus „select another plot“ backend metodo nėra.
- `loop` yra veiklos diagramos ir UI navigacijos interpretacija: naudotojas gali grįžti į `PlotsPage` ir pasirinkti kitą planą, bet backend vienoje užklausoje ciklo nevykdo.
- Tuščios istorijos atvejis backend pusėje nėra atskiras specialus response tipas; tai tas pats `data` masyvas be įrašų, o pranešimas suformuojamas frontend.
