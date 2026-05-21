# PA15 Gauti augalų informaciją - codebase auditas

## 1. Frontend boundary

Realus PA15 boundary yra `CatalogPlantFormPage`. Jame:

- `runPerenualSearch(limit)` inicijuoja Perenual augalų paiešką.
- `handlePerenualSearchSubmit(event)` paleidžia paiešką tik naudotojui pateikus formą.
- `handlePerenualSelect(result)` pasirenka rastą rūšį ir kviečia preview/draft užklausą.
- `buildFallbackDraftFromSearchResult(result)` naudojamas, kai rūšies detalių preview nepavyksta dėl 429 rate-limit.

Šaltiniai:
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:104`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:205`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:245`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:262`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:271`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:281`

`CatalogPlantsPage` rastas, bet jis tik nukreipia į `/plants?view=catalog`, todėl PA15 diagramoje nerodomas kaip faktinis Perenual importo boundary.

Šaltinis:
- `frontend/src/pages/plant/CatalogPlantsPage.jsx:1`

## 2. Controlleriai

PA15 realiai dalyvauja `CatalogPlantController`:

- `searchPerenual(Request $request, PerenualService $perenualService)`
- `previewPerenualSpecies(int $speciesId, CatalogPlantService $catalogPlantService)`

Šaltiniai:
- `backend/app/Http/Controllers/Plant/CatalogPlantController.php:20`
- `backend/app/Http/Controllers/Plant/CatalogPlantController.php:35`

`PlantController::search()` taip pat turi Perenual paieškos endpointą `/plants/search`, bet katalogo importo UI naudoja `CatalogPlantController::searchPerenual()`, todėl pagrindinėje PA15 diagramoje pasirinktas katalogo kelias.

Šaltinis:
- `backend/app/Http/Controllers/Plant/PlantController.php:65`

## 3. Request / validation objektai

Custom `SearchPlantRequest`, `PerenualSearchRequest` ar `StoreCatalogPlantRequest` klasės nerastos. Validacija vyksta per `Illuminate\Http\Request`:

- `CatalogPlantController::searchPerenual()` kviečia `$request->validate([...])`, validuodamas `q` ir `limit`.
- `CatalogPlantController::previewPerenualSpecies()` validuoja `speciesId` per `abort_if($speciesId < 1, 422, ...)`.

Šaltiniai:
- `backend/app/Http/Controllers/Plant/CatalogPlantController.php:22`
- `backend/app/Http/Controllers/Plant/CatalogPlantController.php:37`

## 4. Service dalyviai

Galutinėje diagramoje naudojami realūs service:

- `PerenualService`
- `CatalogPlantService`
- `PlantCareNormalizer`
- `PlantCareDefaults`

Šaltiniai:
- `backend/app/Services/Integrations/PerenualService.php:34`
- `backend/app/Services/Integrations/PerenualService.php:166`
- `backend/app/Services/Plant/CatalogPlantService.php:134`
- `backend/app/Services/Plant/PlantCareNormalizer.php:32`
- `backend/app/Services/Plant/PlantCareDefaults.php:342`

## 5. Entity / modeliai

Diagramoje rodomas `Plant`, nes `CatalogPlantService::buildPerenualDraft()` sukuria laikiną `new Plant([...])` objektą normalizavimui.

Rasti, bet PA15 preview diagramoje nesaugomi:

- `CatalogPlant`
- `PlantCare`

Šaltiniai:
- `backend/app/Services/Plant/CatalogPlantService.php:139`
- `backend/app/Models/Plant.php`
- `backend/app/Models/CatalogPlant.php:11`
- `backend/app/Models/PlantCare.php`

## 6. PA15 realizacijos tipas

PA15 realizuotas kaip katalogo importo UI veiksmas ir backend service metodų grandinė. Tai nėra atskiras naudotojo puslapis, nes importas vyksta `CatalogPlantFormPage` viduje.

Realūs inicijavimo taškai:

- `CatalogPlantFormPage::handlePerenualSearchSubmit(event)`
- `CatalogPlantFormPage::handlePerenualSelect(result)`

## 7. Iš kokių PA arba procesų kviečiamas PA15

PA15 realiai kviečiamas iš augalų katalogo kūrimo proceso:

`CatalogPlantFormPage` -> `api.searchPerenualPlants()` -> `GET /catalog-plants/perenual/search` -> `CatalogPlantController::searchPerenual()` -> `PerenualService::searchPlants()`

ir:

`CatalogPlantFormPage` -> `api.previewPerenualCatalogPlant()` -> `GET /catalog-plants/perenual/species/{speciesId}` -> `CatalogPlantController::previewPerenualSpecies()` -> `CatalogPlantService::buildPerenualDraft()`

Šaltiniai:
- `frontend/src/lib/api.js:224`
- `frontend/src/lib/api.js:233`
- `backend/routes/api.php:78`
- `backend/routes/api.php:79`

## 8. Perenual augalų paieška

`PerenualService::searchPlants($query, $limit)`:

- apkarpo užklausą;
- normalizuoja limitą per `resolveSearchLimit()`;
- cacheina atsakymą per `rememberWithMeta()`;
- kviečia `searchSpeciesResponse()`;
- `searchSpeciesResponse()` kviečia Perenual `GET /species-list`;
- rezultatus reitinguoja per `scoreSpeciesMatch()` ir `speciesTieBreakerScore()`.

Šaltiniai:
- `backend/app/Services/Integrations/PerenualService.php:34`
- `backend/app/Services/Integrations/PerenualService.php:47`
- `backend/app/Services/Integrations/PerenualService.php:267`
- `backend/app/Services/Integrations/PerenualService.php:281`
- `backend/app/Services/Integrations/PerenualService.php:369`
- `backend/app/Services/Integrations/PerenualService.php:398`

## 9. Rūšies detalių / priežiūros gairių gavimas

`CatalogPlantService::buildPerenualDraft($speciesId)` kviečia `PerenualService::fetchSpeciesSeed('', $speciesId)`. Kadangi speciesId jau pateiktas, `fetchSpeciesSeed()` nebekviečia `species-list` paieškos, o tiesiog gauna:

- `fetchSpeciesDetailsById($speciesId)` -> `GET /species/details/{speciesId}`;
- `fetchEnrichedCareGuidePayloads($speciesId)`;
- `fetchSpeciesCareGuideById($speciesId, $type)` -> `GET /species-care-guide-list`;
- `normalizeCareGuidesFromPayloads($payloads)`.

Šaltiniai:
- `backend/app/Services/Plant/CatalogPlantService.php:136`
- `backend/app/Services/Integrations/PerenualService.php:166`
- `backend/app/Services/Integrations/PerenualService.php:443`
- `backend/app/Services/Integrations/PerenualService.php:473`
- `backend/app/Services/Integrations/PerenualService.php:509`
- `backend/app/Services/Integrations/PerenualService.php:648`

## 10. Klaidų ir nerastų rezultatų fallback

Neteisinga paieškos užklausa:

- frontend tikrina `query.length < 2` ir nerodo Perenual API kvietimo;
- backend papildomai validuoja `q` ir `limit` per `Request::validate()`;
- invalid request šakoje Perenual API nekviečiamas.

Nerasti paieškos rezultatai:

- `PerenualService::searchPlants()` gali grąžinti `data: []`;
- `CatalogPlantFormPage` rodo tuščios būsenos UI;
- šiame konkrečiame kelyje lokalių priežiūros defaults paieškos stadijoje dar netaiko, nes nėra pasirinkto `speciesId`.

Perenual detail/care guide klaida:

- jei preview užklausa grąžina 429, `CatalogPlantFormPage` kviečia `buildFallbackDraftFromSearchResult(result)`;
- fallback ruošinys remiasi paieškos rezultato `name`, `scientific_name`, `image`, `watering`, `sunlight`.

Šaltiniai:
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:212`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:236`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:281`
- `frontend/src/pages/plant/CatalogPlantFormPage.jsx:104`

## 11. Lokalių numatytųjų reikšmių naudojimas

Lokalių numatytųjų reikšmių centras yra `PlantCareDefaults::forPlant()`. `PlantCareNormalizer::normalizeWithTrace()` visada iškviečia `forPlant(...)`, o vėliau atskiri resolveriai naudoja defaults, kai Perenual duomenys nepilni.

Šaltiniai:
- `backend/app/Services/Plant/PlantCareNormalizer.php:44`
- `backend/app/Services/Plant/PlantCareDefaults.php:342`
- `backend/tests/Unit/PlantCareNormalizerTest.php:144`

## 12. Augalo informacijos normalizavimas

Normalizavimą atlieka `PlantCareNormalizer::normalizeWithTrace()`:

- `mergedRaw($seed)` sujungia `search_match`, `details` ir `care_guides`;
- `careGuideSections($raw)` išskiria priežiūros gairių tekstus;
- `buildSignals(...)` suformuoja klasifikavimo ir priežiūros signalus;
- `resolvePlantTypeTrace(...)` nustato projekto `PlantType`;
- `resolveWateringInterval(...)`, `resolveFertilizingInterval(...)`, `resolvePestCheckInterval(...)` ir kiti resolveriai užpildo priežiūros laukus;
- `resolveQuality(...)` nustato `source_quality`;
- `buildClassification(...)` grąžina klasifikavimo metadata.

Šaltiniai:
- `backend/app/Services/Plant/PlantCareNormalizer.php:32`
- `backend/app/Services/Plant/PlantCareNormalizer.php:197`
- `backend/app/Services/Plant/PlantCareNormalizer.php:327`
- `backend/app/Services/Plant/PlantCareNormalizer.php:362`
- `backend/app/Services/Plant/PlantCareNormalizer.php:902`
- `backend/app/Services/Plant/PlantCareNormalizer.php:941`
- `backend/app/Services/Plant/PlantCareNormalizer.php:973`
- `backend/app/Services/Plant/PlantCareNormalizer.php:1147`
- `backend/app/Services/Plant/PlantCareNormalizer.php:1305`

## 13. Ar PA15 saugo CatalogPlant / PlantCare įrašus

PA15 preview srautas nesaugo `CatalogPlant` arba `PlantCare`. Jis grąžina normalizuotą ruošinį:

- `species_id`
- `catalog`
- `plant_care`

Saugojimas vyksta vėlesniame katalogo valdymo sraute:

`CatalogPlantController::store()` -> `CatalogPlantService::saveCatalogPlant()` -> `resolvePlantCare()` -> `PlantCare::save()` -> `CatalogPlant::save()`.

Šaltiniai:
- `backend/app/Services/Plant/CatalogPlantService.php:151`
- `backend/app/Http/Controllers/Plant/CatalogPlantController.php:72`
- `backend/app/Services/Plant/CatalogPlantService.php:68`
- `backend/app/Services/Plant/CatalogPlantService.php:176`

## 14. Route / API helper sluoksniai, rasti, bet nerodomi lifeline

Rasti sluoksniai:

- `GET /catalog-plants/perenual/search`
- `GET /catalog-plants/perenual/species/{speciesId}`
- `POST /catalog-plants`
- `api.searchPerenualPlants(query, options)`
- `api.previewPerenualCatalogPlant(speciesId)`
- `api.createCatalogPlant(payload)`

Jie specialiai nerodomi kaip lifeline pagal užduoties taisykles, nes routes ir API helperiai nėra sekų diagramos dalyviai.

Šaltiniai:
- `backend/routes/api.php:78`
- `backend/routes/api.php:79`
- `backend/routes/api.php:80`
- `frontend/src/lib/api.js:207`
- `frontend/src/lib/api.js:224`
- `frontend/src/lib/api.js:233`

## 15. Žinučių skaičius

Galutinėje diagramoje yra 162 žinutės:

- 77 sinchroniniai call;
- 83 reply;
- 2 async/UI event.

Calls without return: 0.

## 16. Diagramos padalijimas

HTML puslapyje diagrama padalinta į 2 dalis:

1. Perenual augalų paieška katalogo formoje.
2. Pasirinktos rūšies duomenų gavimas, normalizavimas ir ruošinio grąžinimas.

## 17. Loop / alt / opt fragmentai

Panaudota:

- Alt fragments: 6
- Loop fragments: 4
- Opt fragments: 1

Fragmentai:

- `Search form query validity`
- `Backend Perenual search request validity`
- `Search result ranking`
- `Perenual search result`
- `Species preview request validity`
- `Perenual detail/care guide fetch result`
- `Initial care guide section parsing`
- `Supplemental care guide enrichment`
- `Supplemental care guide types`
- `Final care guide section parsing`
- `Perenual detail/care guide result`

## 18. Reply rodyklių taisyklė

- Kiekvienas sinchroninis call turi vėlesnį reply.
- Reply nėra automatiškai po call.
- Reply grįžta po vidinės logikos, pvz. `searchPlants()` reply grįžta tik po HTTP, cache, reitingavimo ir limitavimo.
- UI event / sisteminis startas gali būti async ir be reply.

## 19. Alt fragmentų taisyklė

- Kiekvienas alt turi operandus.
- Kiekvienas operand turi guard.
- Invalid request šakoje nekviečiamas Perenual API.
- No results/error paieškos šakoje UI išvalo rezultatus ir rodo klaidos / tuščios būsenos informaciją; lokalių care defaults čia nėra, nes dar nėra `speciesId`.
- Detail/care guide rate-limit šakoje naudojamas `buildFallbackDraftFromSearchResult(result)`.
- Detail/care guide nepilnumo šakoje `PlantCareNormalizer` naudoja `PlantCareDefaults::forPlant()` ir grąžina normalizuotą rezultatą su `partial` arba `default` kokybės trace.
- Bendri veiksmai iškelti prieš arba po alt, kai jie realiai bendri abiem šakoms.

## 20. Visų sekų sąrašas HTML puslapio apačioje

HTML apačioje pateiktas pilnas sekų sąrašas su:

- Iš;
- Į;
- Operacija;
- Jungties tipas;
- Fragmentas / pastaba;
- Replies to.

Sąrašas generuojamas iš tos pačios `messages` ir `fragments` struktūros kaip SVG diagramos, todėl fragmentų pradžios, operandai ir pabaigos sutampa su vizualia diagrama.

## 21. Nepatvirtintos arba sąmoningai ribotos vietos

- Codebase neturi atskiro `PerenualClient`; naudojamas `PerenualService`, todėl `PerenualClient` lifeline nepridėtas.
- Codebase neturi custom request klasės PA15 paieškai; naudojamas `Illuminate\Http\Request`.
- Paieškos rezultatų neradimo atveju codebase nenaudoja `PlantCareDefaults`; jis tik rodo tuščią UI būseną. Defaults pradedami naudoti tik preview/normalizavimo arba fallback draft srautuose.
- PA15 preview nesaugo DB įrašų. `CatalogPlant` / `PlantCare` saugojimas yra kitas katalogo valdymo veiksmas po naudotojo `handleSubmit()`.
