# PA Valdyti rekomendacinį kalendorių realizacija projekto klasėmis - supaprastinimo auditas

## Pakeitimo esmė

Sekų diagrama perkelta iš žemo lygio kodo pėdsako į projekto realizacijos lygį. Lentelė „Visų sekų sąrašas“ generuojama iš to paties `messages` masyvo kaip SVG diagrama, todėl jos sutampa.

## Kas supaprastinta

- AccessService vidinės owner/shared prieigos patikros pakeistos į ensureUserCanViewPlot() ir ensureUserCanEditPlot().
- TaskCalendar, Task, WeatherForecast query-builder detalės pakeistos į listCalendarsForPlot(), loadCalendarDetails(), saveGeneratedCalendar() ir listTasksForDate().
- Plant care, weather, inventory ir task completion vidiniai helperiai sutraukti į dalykines service operacijas.
- Frontend state, drawer ir formos techniniai metodai pakeisti naudotojo veiksmų ir atsakymų žinutėmis.

## Galutinė apimtis

- Žinučių skaičius: 59.
- Visi sinchroniniai `call` turi `reply`.
- `alt`, `loop` ir `opt` fragmentai palikti tik naudotojui arba dalykinei logikai reikšmingose vietose.

## „Visų sekų sąrašas“

HTML puslapio apačioje esanti lentelė rodo tas pačias supaprastintas sekas ir fragmentų pradžias / pabaigas kaip diagrama.
