# Neaiškūs klausimai ir rankinės peržiūros vietos

## Reikia rankinio sprendimo

1. **Specifikacijos ir dabartinio kodo neatitikimas dėl `plants.fk_plant_care_id`.**  
   AGENTS/spec fragmentas nurodo `plants.fk_plant_care_id -> plant_care.id`, tačiau dabartiniame kode `Plant` modelio `$fillable` tokio lauko neturi, o migracija `2026_04_20_120000_remove_redundant_plant_care_from_plants_table.php` rodo, kad tiesioginis ryšys buvo šalintas. Dabartinis kodas priežiūrą gauna per `Plant -> CatalogPlant -> PlantCare` ir `Plant::effectivePlantCare()`. Diagramose parodyta faktinė kodo struktūra.

2. **`GardenOwner` ir legacy FK laukų semantika.**  
   Modelyje yra ir naujesni laukai (`user_id`), ir legacy laukai (`id_user`, `fk_profile_id`), taip pat `plots()` / `inventoryItems()` ryšiai kaip `BelongsTo`, o pagrindiniams sąrašams naudojami `ownedPlots()` / `ownedInventoryItems()`. Diagramose naudoti aiškiausi dalykiniai ryšiai, bet DB normalizavimo istoriją verta patikrinti rankiniu būdu.

3. **`ConditionHistoryController` naudojimas.**  
   Klasė egzistuoja ir paveldi `PlantConditionController`, bet `routes/api.php` jos nemapina. Diagramoje ji pažymėta kaip egzistuojanti, tačiau funkciniame modelyje pagrindinis kontroleris yra `PlantConditionController`.

4. **`PlantStateService` naudojimas.**  
   Klasė turi viešą `simulatePlantState()`, bet analizuotuose maršrutuose pagrindinė fazių simuliacija eina per `PlantLifecyclePhaseService`. `PlantStateService` įtrauktas į būklės posistemės aprašymą kaip egzistuojanti pagalbinė paslauga, bet detalioje veikimo grandinėje jo naudojimas neaiškus.

5. **PDF šablonas nėra PHP klasė.**  
   `backend/resources/views/pdf/plot-report.blade.php` įtrauktas kaip `<<boundary>>`, nes tai realus ataskaitos generavimo ribinis failas. Jei darbo metodika reikalauja rodyti tik PHP/JS klases, jį galima palikti tik aprašyme, o ne UML diagramoje.

## Nerasta atskirų posistemių / klasių

- `backend/app/Policies` katalogo su politikų klasėmis nerasta.
- `backend/app/Jobs` klasių nerasta.
- `backend/app/Console` komandų klasių nerasta.
- `backend/app/Observers` klasių nerasta.
- Laravel maršrutai nevaizduoti kaip klasės, nes nėra custom router klasių.

## Sąmoningai neįtraukta

- Testai (`*.test.jsx`, `backend/tests/*`), nes jie nėra sistemos vykdymo klasės.
- Smulkūs UI komponentai iš `frontend/src/components/ui`, nes jie neturi atskiros dalykinės atsakomybės sistemos klasių modeliui.
- `PlantCareDebugController` ir `/api/dev/*` endpointai, nes tai derinimo funkcijos, o ne pagrindinės sistemos use case klasės.
- Migracijos kaip UML klasės. Jos naudotos atributams, enum apribojimams ir ryšiams patikrinti.
- `HasPlot`, `HasInventory`, `UsedOn` daugumoje detalių diagramų, nes tai daugiausia jungiamųjų / legacy ryšių modeliai; jie aprašyti inventoriuje.

## PlantUML peržiūros pastabos

- PlantUML vykdymas lokaliai neįvykdytas, nes komanda `plantuml -version` šiame kompiuteryje nerasta. Sintaksė peržiūrėta rankiniu būdu ir papildomai patikrinta, kad visi `.puml` failai turi po vieną `@startuml` ir `@enduml`.
- Kai vienodi klasių pavadinimai egzistuoja skirtingose namespace, diagramose naudoti aliasai, pvz. `"User\\AccountController" as UserAccountController` ir `"Admin\\AccountController" as AdminAccountController`.
