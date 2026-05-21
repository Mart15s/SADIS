# PA8 Pa?ym?ti augal? rotacij? realizacija projekto klas?mis - supaprastinimo auditas

## Pakeitimo esm?

Sek? diagrama atnaujinta pagal nauj? veiklos diagram?, i?laikant projekto realizacijos lyg?. Paliktos dalykin?s ?akos: rotacijos istorijos buvimas / nebuvimas, planavimo istorijos per?i?ra, laikino plano generavimas, rankinis redagavimas, patvirtinimas ir atmetimas.

## Kas supaprastinta

- AccessService owner/shared access vidiniai query pakeisti ? `ensureUserCanViewPlot()` ir `ensureUserCanEditPlot()`.
- Eloquent relation, query-builder ir kolekcij? detal?s nerodomos.
- Augal? ir zon? vertinimas rodomas auk?to lygio `RotationPlannerService` operacijomis: `getRotatablePlantOrder()`, `checkZoneOccupancy()`, `checkSameTypeConflict()`, `checkRestInterval()`, `checkSoilCompatibility()`, `calculateZoneScore()`.
- Patvirtinimo ?aka rodo tik dalykinius veiksmus: perkelti augalus, atnaujinti zonas, i?saugoti istorij?, ?ra?yti snapshot, pa?alinti laikin? plan?.

## Galutin? apimtis

- ?inu?i? skai?ius: 94.
- Visi sinchroniniai `call` turi `reply`.
- `alt`, `loop` ir `opt` fragmentai atitinka veiklos diagramos verslo sprendimus.

## ?Vis? sek? s?ra?as?

HTML apa?ioje esanti lentel? generuojama i? to paties `messages` ir `fragments` rinkinio kaip SVG diagrama, tod?l joje matomos tos pa?ios sekos bei fragment? prad?ios / pabaigos.
