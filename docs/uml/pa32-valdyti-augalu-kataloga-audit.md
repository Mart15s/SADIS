# PA32 Valdyti augalų katalogą - codebase auditas

## 1. Realūs frontend boundary

- `PlantsPage` yra faktinis augalų katalogo sąrašo, paieškos ir šalinimo boundary. Katalogo vaizdas parenkamas per `view=catalog`, o duomenys kraunami per `useAsyncData(...)` ir `api.listCatalogPlants(...)`. Source: frontend/src/pages/plant/PlantsPage.jsx:37-231.
- `CatalogPlantDetailPage` pateikia katalogo augalo ir susijusios `plantCare` informacijos peržiūrą. Source: frontend/src/pages/plant/CatalogPlantDetailPage.jsx:23-156.
- `CatalogPlantFormPage` naudojamas naujam katalogo augalui kurti, Perenual/PA15 automatiniam užpildymui ir redagavimui. Source: frontend/src/pages/plant/CatalogPlantFormPage.jsx:151-759.
- `CatalogPlantResource` yra Laravel HTTP resource boundary, formuojantis API atsakymo struktūrą. Source: backend/app/Http/Resources/Plant/CatalogPlantResource.php:10-42.
- `CatalogPlantsPage` rastas, bet jis tik nukreipia į `/plants?view=catalog`, todėl diagramoje nerodomas kaip pagrindinis katalogo valdymo lifeline.

## 2. Controlleriai

- `CatalogPlantController` dalyvauja visuose PA32 backend veiksmuose: `index()`, `show()`, `store()`, `update()`, `destroy()`, taip pat PA15 kvietimo taškuose `searchPerenual()` ir `previewPerenualSpecies()`. Source: backend/app/Http/Controllers/Plant/CatalogPlantController.php:20-184.

## 3. Request / validation objektai

Custom `StoreCatalogPlantRequest` ar `UpdateCatalogPlantRequest` klasės nerastos. Validacija vyksta per `Illuminate\Http\Request`:

- `index()` validuoja `q`;
- `searchPerenual()` validuoja `q` ir `limit`;
- `validatePayload()` validuoja katalogo augalo ir `plant_care` laukus;
- `previewPerenualSpecies()` species id tikrina per `abort_if(...)`.

## 4. Service dalyviai

- `CatalogPlantService` saugo ir atnaujina katalogo augalą per `saveCatalogPlant()`, kur kviečiami `canonicalName()`, `resolvePlantCare()` ir `syncPlantsFromCatalog()`. Source: backend/app/Services/Plant/CatalogPlantService.php:68-222.
- `PerenualService`, `PlantCareNormalizer` ir `PlantCareDefaults` realiai dalyvauja automatinio užpildymo grandinėje, bet PA32 diagramoje jų vidiniai žingsniai neplėtojami, nes jie priklauso `ref PA15 Gauti augalų informaciją`.

## 5. Entity / modeliai

- `CatalogPlant`: katalogo šablonas, turi `plantCare()` ir `plants()` ryšius. Source: backend/app/Models/CatalogPlant.php:11-47.
- `PlantCare`: pakartotinai naudojamas priežiūros profilis. Source: backend/app/Models/PlantCare.php:11-90.
- `Plant`: naudojamas skaičiuojant, ar katalogo augalas jau priskirtas pasodintiems augalams, ir sinchronizuojant katalogo pakeitimus. Source: backend/app/Models/Plant.php:11-84.

## 6. Katalogo augalų sąrašo gavimas

`PlantsPage` katalogo vaizde kviečia `api.listCatalogPlants(...)`, kuris eina į `CatalogPlantController::index()`. Controlleris kviečia `CatalogPlant::query()`, `with(['plantCare'])`, `withCount('plants')`, rikiuoja pagal `name` ir `id`, tada grąžina `CatalogPlantResource::collection(...)->resolve()`.

## 7. Katalogo paieška

Paieška naudoja tą patį `index()` metodą su `q` parametru. `applySearch()` filtruoja `name`, `canonical_name`, `source_scientific_name`, `source_family` ir susijusį `plantCare` per `orWhereHas('plantCare', ...)`. Diagramoje rezultatas parodytas kaip `alt Catalog search result`: rasta arba nerasta.

## 8. Katalogo augalo peržiūra

`CatalogPlantDetailPage` kviečia `api.getCatalogPlant(catalogPlantId)`, o backend vykdo `CatalogPlantController::show(CatalogPlant $catalogPlant)`. Controlleris užkrauna `plantCare`, `plants` skaitiklį ir grąžina `CatalogPlantResource`.

## 9. Rankinis katalogo augalo kūrimas

`CatalogPlantFormPage::handleSubmit(event)` su `entryMethod='manual'` sudaro payload ir kviečia `CatalogPlantController::store()`. `validatePayload()` validuoja duomenis. Validžioje šakoje `CatalogPlantService::saveCatalogPlant()` sukuria arba atnaujina `PlantCare`, išsaugo `CatalogPlant` ir kviečia `syncPlantsFromCatalog()`. Invalid šakoje `PlantCare` ir `CatalogPlant` saugojimas nekviečiamas.

## 10. Automatinis katalogo augalo užpildymas su ref PA15

Automatinis režimas realiai prasideda `handleMethodChange('perenual')`, `handlePerenualSearchSubmit(event)` ir `runPerenualSearch(...)`. Rasti rezultatai gaunami per `CatalogPlantController::searchPerenual()`. Kai naudotojas pasirenka rezultatą, `handlePerenualSelect(result)` kviečia `CatalogPlantController::previewPerenualSpecies(speciesId)`; čia PA32 diagramoje įterptas `ref PA15 Gauti augalų informaciją`, kuris atitinka `CatalogPlantService::buildPerenualDraft(speciesId)` ir grąžina normalizuotą katalogo bei priežiūros ruošinį. PA15 vidinės Perenual ir normalizavimo sekos PA32 diagramoje neišskleistos.

## 11. Katalogo augalo redagavimas

Redagavimo forma per `useAsyncData(...)` užkrauna `show()`, tada `catalogPlantToForm(...)` ir `careToForm(...)` užpildo formą. Pateikus pakeitimus kviečiamas `CatalogPlantController::update()`, `validatePayload(request, catalogPlant)` ir `CatalogPlantService::saveCatalogPlant(validated, catalogPlant)`. Validžioje šakoje atnaujinamas `PlantCare`, `CatalogPlant` ir per `syncPlantsFromCatalog()` susiję `Plant` įrašai. Invalid šakoje saugojimas nevykdomas.

## 12. Katalogo augalo šalinimas

`PlantsPage::handleDelete(entry)` pirmiausia kviečia `window.confirm(...)`. Jei naudotojas atšaukia, `api.deleteCatalogPlant(...)` nekviečiamas. Jei patvirtina, frontend kviečia `CatalogPlantController::destroy(CatalogPlant $catalogPlant)`.

## 13. Naudojimo tikrinimas prieš šalinimą

Backend `destroy()` kviečia `$catalogPlant->loadCount('plants')` ir `abort_if($catalogPlant->plants_count > 0, 422, ...)`. Todėl `delete()` kviečiamas tik tada, kai katalogo šablonas nenaudojamas pasodintuose `Plant` įrašuose.

## 14. Ar šalinamas PlantCare

Ne. `CatalogPlantController::destroy()` kviečia tik `$catalogPlant->delete()`. `PlantCare` atskirai netrinamas. Migracijoje `catalog_plants.fk_plant_care_id` turi `nullOnDelete()`, bet katalogo augalo šalinimas pats priežiūros profilio nepašalina. Source: backend/app/Http/Controllers/Plant/CatalogPlantController.php:20-184; backend/database/migrations/2026_04_08_160000_create_catalog_plants_table.php:13-20.

## 15. Route / API helper sluoksniai, specialiai nerodomi lifeline

- `backend/routes/api.php`: `/catalog-plants`, `/catalog-plants/{catalogPlant}`, `/catalog-plants/perenual/search`, `/catalog-plants/perenual/species/{speciesId}`.
- `frontend/src/lib/api.js`: `listCatalogPlants()`, `getCatalogPlant()`, `createCatalogPlant()`, `updateCatalogPlant()`, `deleteCatalogPlant()`, `searchPerenualPlants()`, `previewPerenualCatalogPlant()`.
- `frontend/src/App.jsx`: katalogo route'ai rasti, bet React Router lifeline nerodomas.

## 16. Galutinės diagramos žinučių kiekis

Galutinėje diagramoje yra 229 žinutės: 101 sinchroniniai call, 110 reply, 17 async/UI event ir 1 ref įrašas. Calls without return = 0.

## 17. Diagramos skaidymas

HTML diagrama padalinta į tris dalis:

1. Katalogo sąrašo gavimas, paieška ir augalo peržiūra.
2. Naujo katalogo augalo kūrimas rankiniu būdu arba automatiškai su `ref PA15`.
3. Katalogo augalo redagavimas, šalinimas ir valdymo pabaiga.

## 18. Panaudoti loop / alt / opt / ref fragmentai

- Alt fragmentai: 8.
- Loop fragmentai: 3.
- Opt fragmentai: 1.
- Ref fragmentai: 1.

## 19. Ref fragmento taisyklė

- PA15 naudojamas kaip `ref`, kai naudotojas pasirenka automatinį katalogo augalo užpildymą ir sistema gauna normalizuotą augalo bei priežiūros informaciją.
- PA15 vidinės sekos PA32 diagramoje neišskleidžiamos.
- PA32 tik parodo PA15 kvietimą per `buildPerenualDraft(speciesId)` ir normalizuoto `perenual_draft` rezultato gavimą.

## 20. Reply rodyklių taisyklė

- Kiekvienas sinchroninis `call` turi vėlesnį `reply`.
- `reply` nėra automatiškai iškart po `call`; jis grįžta po vidinės logikos.
- UI event yra `async` ir gali neturėti `reply`.
- Patikros rezultatas: calls without return = 0.

## 21. Alt fragmentų taisyklė

- Visi `alt` fragmentai turi operandus.
- Kiekvienas operandas turi guard.
- Invalid duomenų šakose `PlantCare` ir `CatalogPlant` save/update nekviečiami.
- Jei katalogo augalas naudojamas, `delete()` nevykdomas.
- Jei naudotojas atšaukia šalinimą, `destroy()` ir `delete()` nevykdomi.
- Realus codebase patvirtinimą rodo frontend prieš backend naudojimo tikrinimą; naudojimo tikrinimas vis tiek atliekamas prieš faktinį `CatalogPlant::delete()`.

## 22. Visų sekų sąrašas HTML puslapio apačioje

HTML apačioje pateiktas pilnas sekų sąrašas su stulpeliais:

- Iš;
- Į;
- Operacija;
- Jungties tipas;
- Fragmentas / pastaba;
- Replies to.

Sąraše taip pat matomos `loop`, `alt`, `opt` ir `ref` pradžios bei pabaigos žymos.

## 23. Nepatvirtintos arba spec/kodo skirtumo vietos

- Atskiros `CatalogPlantRequest` klasės nėra; validacija yra controllerio `validatePayload()` ir `Request::validate(...)`.
- `CatalogPlantsPage` nėra faktinis sąrašo puslapis; jis nukreipia į `PlantsPage`.
- Realiame kode šalinimo patvirtinimas vyksta prieš backend `loadCount('plants')`, o ne po jo. Diagrama tai pažymi ir vis tiek rodo, kad `delete()` nevykdomas, jei šablonas naudojamas arba naudotojas atšaukia.
