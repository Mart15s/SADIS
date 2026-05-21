# PA17 Pažymėti sunaudotas medžiagas - codebase auditas

## 1. Realūs frontend boundary

- `PlotCalendarPage` (`frontend/src/pages/calendar/PlotCalendarPage.jsx`) yra pagrindinis PA17 boundary. Jame:
  - `handleDayClick(day)` atidaro dienos užduočių modalą;
  - `api.listCalendarTasks(selectedCalendarId, params)` užkrauna užduotis su `required_resources`;
  - `resourceRequirements.map(...)` parodo reikalingus resursų kiekius;
  - `missingResources.filter(...)` nustato, ar užduotis blokuojama trūkumu;
  - `handleTaskAction(task.id, 'complete')` inicijuoja užduoties užbaigimą;
  - `setToastMessage('Task completed.')` ir `setError(requestError.message)` pateikia rezultatą.
- `InventoryPage` (`frontend/src/pages/inventory/InventoryPage.jsx`) rastas kaip susijęs boundary trūkumams papildyti. Jis naudoja `inventoryRequestContext`, `applyResourceSuggestion(resource)` ir `handleSubmit(event)`, bet pagrindinis sunaudotų medžiagų nurašymas vyksta ne šiame puslapyje.
- Atskiras `UsedMaterialsForm`, `TaskDetailsPage`, `MaterialUsagePage` arba `InventoryUsagePage` codebase nerastas.

## 2. Dalyvaujantys controlleriai

- `TaskController` (`backend/app/Http/Controllers/Calendar/TaskController.php`) yra pagrindinis PA17 controller:
  - `index()` grąžina kalendoriaus užduotis ir jų inventoriaus kontekstą;
  - `complete()` užbaigia užduotį;
  - `authorizeCalendarView()` tikrina peržiūros prieigą;
  - `authorizeTaskEdit()` tikrina redagavimo prieigą.
- `InventoryController` egzistuoja (`backend/app/Http/Controllers/Inventory/InventoryController.php`), bet PA17 sunaudojimo scenarijuje nėra pagrindinis controller. Jis naudojamas inventoriaus CRUD / papildymo veiksmams.

## 3. Dalyvaujantys service

- `AccessService` (`backend/app/Services/Plot/AccessService.php`) tikrina `userHasAccess()` ir `userCanEdit()`.
- `InventoryService` (`backend/app/Services/Inventory/InventoryService.php`) vykdo inventoriaus logiką:
  - `attachLiveTaskInventory()`;
  - `assertTaskCanBeCompletedForDay()`;
  - `consumeTaskRequirements()`;
  - `prepareTaskRequirementSnapshots()`;
  - `buildTaskInventoryContext()`;
  - `throwInsufficientTaskInventory()`;
  - `deductMaterialForOwner()` kaip senesnis / fallback backend kelias, kai naudojamas `materials_used`.
- `TaskWorkflowService` (`backend/app/Services/Calendar/TaskWorkflowService.php`) koordinuoja `complete()`, transakciją, užduoties būsenos tikrinimą ir task `update()`.
- `TaskInventoryCoverageService` (`backend/app/Services/Calendar/TaskInventoryCoverageService.php`) skaičiuoja pakankamumą per `summarizeTasksByDate()`, `buildDayRequirementSummaries()`, `buildTaskInventoryContext()` ir `matchingItems()`.

## 4. Dalyvaujantys entity / modeliai

- `TaskCalendar` (`backend/app/Models/TaskCalendar.php`) - kalendoriaus užduočių šaltinis.
- `Task` (`backend/app/Models/Task.php`) - užduotis, turi `requiredResources()` ir `inventoryUsageLogs()`.
- `TaskResourceRequirement` (`backend/app/Models/TaskResourceRequirement.php`) - reikalingas resursas; svarbūs laukai `required_quantity`, `shortage_quantity`, `is_consumed`.
- `InventoryItem` (`backend/app/Models/InventoryItem.php`) - inventoriaus likutis; svarbus laukas `quantity`.
- `InventoryUsageLog` (`backend/app/Models/InventoryUsageLog.php`) - sunaudojimo istorijos įrašas.

## 5. Ar PA17 yra atskiras inventoriaus veiksmas?

PA17 realizuotas kaip užduoties atlikimo dalis. Pagrindinis srautas:

`PlotCalendarPage.handleTaskAction()` -> `TaskController::complete()` -> `TaskWorkflowService::complete()` -> `InventoryService::consumeTaskRequirements()`.

Atskiras inventoriaus naudojimo controlleris nerastas. `InventoryPage` padeda papildyti trūkstamus resursus, bet sunaudojimas įvyksta užbaigiant užduotį.

## 6. Reikalingų resursų kiekio gavimas

Resursai gaunami per `TaskController::index()`:

- controller užkrauna užduotis su `requiredResources`;
- `InventoryService::attachLiveTaskInventory()` kviečia `TaskInventoryCoverageService::summarizeTasksByDate()`;
- `TaskInventoryCoverageService::buildDayRequirementSummaries()` kiekvienam `TaskResourceRequirement` paima `required_quantity`;
- `InventoryItem` likučiai agreguojami per `matchingItems()` ir `sum('quantity')`;
- `TaskResourceRequirementResource` grąžina `required_quantity`, `available_quantity`, `shortage_quantity`, `is_consumed`, `is_sufficient`.

## 7. Likučių pakankamumo tikrinimas

Prieš užduoties užbaigimą `TaskWorkflowService::complete()` kviečia `InventoryService::assertTaskCanBeCompletedForDay()`.

Tikrinimas:

- `TaskInventoryCoverageService::summarizeTasksByDate(..., true)` skaičiuoja tos dienos poreikius su inventoriaus eilučių užrakinimu;
- `available_quantity` apskaičiuojamas iš `InventoryItem` kiekio sumos;
- `shortage_quantity = max(0, required_quantity - available_quantity)`;
- jei `can_complete` / `is_actionable` yra false, `InventoryService::throwInsufficientTaskInventory()` meta `ValidationException`.

## 8. Kiekio sumažinimas ir išsaugojimas

Sėkmės šakoje `InventoryService::consumeTaskRequirements()`:

- paruošia `prepareTaskRequirementSnapshots(owner, task, true)`;
- dar kartą sukuria `buildTaskInventoryContext(snapshots)`;
- jei statusas `shortage`, meta `ValidationException`;
- jei `TaskResourceRequirement.is_consumed` yra `true`, eina per atitinkančius `InventoryItem`;
- apskaičiuoja `deductedQuantity`, `quantityAfter`;
- kviečia `$item->update(['quantity' => $quantityAfter])`.

Jei `is_consumed` yra `false`, kiekis nemažinamas ir į santrauką pridedamas `consumed => false`.

## 9. Sunaudojimo registravimas

Kai resursas nurašomas, `InventoryService::consumeTaskRequirements()` sukuria:

`InventoryUsageLog::query()->create([...])`

Svarbūs laukai:

- `inventory_item_id`;
- `task_id`;
- `task_resource_requirement_id`;
- `garden_owner_id`;
- `change_type = consumed`;
- `quantity_before`;
- `quantity_delta` su neigiama reikšme;
- `quantity_after`;
- `unit`;
- `metadata.resource_name`;
- `metadata.task_name`.

Reusable resurso šakoje `InventoryUsageLog` nekuriamas.

## 10. Route / API helper sluoksniai nerodomi lifeline

Rasti, bet diagramoje specialiai nerodomi:

- `backend/routes/api.php`:
  - `GET /calendars/{calendar}/tasks`;
  - `PATCH /tasks/{task}/complete`;
  - `GET/POST/PATCH/DELETE /inventory...`.
- `frontend/src/lib/api.js`:
  - `listCalendarTasks(calendarId, params)`;
  - `completeTask(taskId, payload)`;
  - `listInventory()`, `createInventoryItem()`, `updateInventoryItem()`.
- `frontend/src/App.jsx`:
  - React Router maršrutai.

Šie sluoksniai paminėti specifikacijoje, bet nevaizduojami kaip lifeline pagal taisykles.

## 11. Galutinės diagramos žinučių skaičius

- Iš viso žinučių: 123.
- Sinchroniniai call: 59.
- Reply / return: 62.
- Async/UI event: 2.
- Calls without return: 0.

## 12. Diagramos padalijimas

HTML puslapis padalintas į 2 diagramas:

1. `Užduoties resursų gavimas`.
2. `Užduoties medžiagų nurašymas`.

## 13. Panaudoti loop / alt / opt fragmentai

- `loop [for each required resource]` - resursų kiekių pateikimo / matomumo skaičiavimas, žinutės 21-30.
- `loop [for each required resource]` - užbaigimo pakankamumo skaičiavimas, žinutės 69-80.
- `alt Resource availability`:
  - `[available quantity is sufficient]`, žinutės 82-116;
  - `[available quantity is insufficient]`, žinutės 117-123.
- `loop [for each task requirement snapshot]` - sunaudojimo ciklas, žinutės 94-107.
- `alt Resource consumption requirement`:
  - `[resource is consumable / is_consumed is true]`, žinutės 96-105;
  - `[resource is reusable / is_consumed is false]`, žinutės 106-107.
- `opt` fragmentų nenaudota, nes reali PA17 logika aiškiau išreiškiama `alt` operandais.

## 14. Reply rodyklių taisyklė

- Kiekvienas sinchroninis `call` turi vėlesnį `reply`.
- `reply` nėra automatiškai po `call`; jis grįžta tada, kai kviečiamas objektas baigia savo vidinę logiką.
- Pavyzdžiui, `TaskController -> TaskWorkflowService: complete(...)` gauna `completion_payload` tik po inventoriaus tikrinimo, nurašymo ir `Task::update()`.
- UI event, pvz. `Garden owner -> PlotCalendarPage: handleTaskAction(taskId, 'complete')`, yra asinchroninis ir neturi reply.

## 15. Alt fragmentų taisyklė

- Kiekvienas `alt` turi aiškius operandus.
- Kiekvienas operandas turi guard.
- `Resource availability` klaidos šakoje nevykdomas `InventoryItem::update()` ir nekuriamas `InventoryUsageLog`.
- `Resource availability` sėkmės šaka leidžia tęsti į `consumeTaskRequirements()`.
- `Resource consumption requirement` sėkmės / consumable šakoje vykdomi `update()` ir `InventoryUsageLog::create()`.
- `Resource consumption requirement` reusable šakoje kiekis nemažinamas, nes reali sąlyga yra `TaskResourceRequirement.is_consumed == false`.
- Bendri veiksmai, tokie kaip prieigos tikrinimas ir užduoties užrakinimas, iškelti prieš `alt`.

## 16. Visų sekų sąrašas HTML puslapio apačioje

HTML apačioje pateiktas pilnas sekų sąrašas su:

- `Nr.`;
- `Iš`;
- `Į`;
- `Operacija`;
- `Jungties tipas`;
- `Fragmentas / pastaba`;
- `Replies to`.

Sąrašas generuojamas iš tos pačios `messages` ir `fragments` struktūros kaip diagramos, todėl jame matosi `loop pradžia`, `loop pabaiga`, `alt pradžia`, operandų pradžia / pabaiga ir `alt pabaiga`.

## Nepatvirtintos arba supaprastintos vietos

- Current UI neperduoda `materials_used` payload per `handleTaskAction()`, nors `CompleteTaskRequest` ir `TaskWorkflowService::complete()` turi fallback šaką `materials_used` sąrašui ir `InventoryService::deductMaterialForOwner()`.
- Pagrindinėje diagramoje nerodoma `deductMaterialForOwner()` fallback šaka, nes ji nėra realiai naudojama dabartiniame `PlotCalendarPage` PA17 sraute.
- Veiklos diagramos „ar resursas turi būti nurašomas“ šaka realizacijoje atitinka `TaskResourceRequirement.is_consumed` lauką; atskiro `isConsumable()` metodo Eloquent modelyje nėra. `NormalizedTaskResource::isConsumable()` egzistuoja value object sluoksnyje planavimo / coverage logikoje, bet pagrindinis nurašymo metodas naudoja normalizuotą `is_consumed` reikšmę.
