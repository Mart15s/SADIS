# PA9 Per?i?r?ti planavimo istorij? realizacija projekto klas?mis - supaprastinimo auditas

## Pakeitimo esm?

Sek? diagrama i?laiko projekto realizacijos lyg?, bet gr??ina veiklos diagramoje nurodyt? sistemos inicijuot? scenarij?. ?is scenarijus parodytas kaip `opt [function_started_by_the_system]`: sistema gauna istorijos duomenis, rotacijos kontekst? ir pagal istorijos prieinamum? gr??ina rotacijai reikaling? istorijos duomen? rinkin? arba tu??i? istorijos kontekst?.

## Kas supaprastinta

- AccessRight ir AccessService vidiniai owner/shared access query kvietimai pakeisti ? `ensureUserCanViewPlot()`.
- Plot, PlantZone ir Plant Eloquent relation / load / count kvietimai pakeisti ? `loadPlotWithRelations()`.
- plot_snapshots query-builder grandin? pakeista ? `getHistorySnapshots()` ir `prepareHistoryPresentation()`.
- Sistemos inicijuotas rotacijos kontekstas rodomas auk?to lygio `evaluatePlot()` ir `getRotationContext()` operacijomis, be vidini? skai?iavimo ?ingsni?.
- React useAsyncData, useEffect, setState, find/map/read/render helperiai pakeisti ? `renderSelectedSnapshot()` ir naudotojui matomas b?senas.

## Galutin? apimtis

- ?inu?i? skai?ius: 44.
- Visi sinchroniniai `call` turi `reply`.
- Prid?tas `opt [function_started_by_the_system]` fragmentas.
- `alt`, `loop` ir `opt` fragmentai palikti tik naudotojui arba dalykinei logikai reik?mingose vietose.

## ?Vis? sek? s?ra?as?

HTML puslapio apa?ioje esanti lentel? rodo tas pa?ias supaprastintas sekas ir fragment? prad?ias / pabaigas kaip diagrama, ?skaitant sistemos inicijuot? `opt` ?ak?.
