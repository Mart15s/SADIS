# PA12 Gauti orų prognozes - codebase auditas

## 1. Frontend boundary

PA12 neturi atskiro orų puslapio arba atskiro orų atnaujinimo UI. Rastas realus tėvinis boundary yra `PlotCalendarPage`, kuriame `handleGenerate(event)` kviečia `api.generateCalendar(plotId, generateForm)` ir inicijuoja rekomendacinio kalendoriaus generavimą.

Šaltiniai:
- `frontend/src/pages/calendar/PlotCalendarPage.jsx:367`
- `frontend/src/pages/calendar/PlotCalendarPage.jsx:372`
- `frontend/src/pages/calendar/PlotCalendarPage.jsx:600`

`PlotCalendarPage` nėra parodytas PA12 diagramoje kaip lifeline, nes pats PA12 orų gavimas prasideda vidiniame backend service metode.

## 2. Controlleriai

Rastas realus tėvinis controller yra `CalendarController::store()`. Jis validuoja kalendoriaus datos intervalą ir kviečia `TaskCalendarService::generate()`.

Šaltiniai:
- `backend/app/Http/Controllers/Calendar/CalendarController.php:36`
- `backend/app/Http/Controllers/Calendar/CalendarController.php:47`
- `backend/app/Http/Controllers/Calendar/CalendarController.php:61`

Atskiras `WeatherController` codebase nerastas. Todėl controller lifeline PA12 diagramoje nepridėtas.

## 3. Service dalyviai

Galutinėje diagramoje naudojami realūs service:
- `CalendarGenerationService`
- `WeatherService`
- `MeteoLtClient`

Tėviniame PA11 kelyje taip pat dalyvauja `TaskCalendarService`, bet PA12 diagramoje jis nerodomas, nes tiesiog deleguoja į `CalendarGenerationService::generateCalendar()`.

Šaltiniai:
- `backend/app/Services/Calendar/TaskCalendarService.php:16`
- `backend/app/Services/Calendar/CalendarGenerationService.php:42`
- `backend/app/Services/Calendar/WeatherService.php:28`
- `backend/app/Services/Integrations/MeteoLtClient.php:14`

## 4. Entity / modeliai

Galutinėje diagramoje naudojami realūs duomenų dalyviai:
- `TaskCalendar`
- `Plot`
- `WeatherData`
- `WeatherForecast`

`WeatherData` yra value object, ne Eloquent modelis, bet jis yra reali projekto klasė, per kurią `WeatherService` grąžina normalizuotą orų duomenų struktūrą ir per kurią `CalendarGenerationService` suformuoja `WeatherForecast` atributus.

Šaltiniai:
- `backend/app/Models/TaskCalendar.php`
- `backend/app/Models/Plot.php`
- `backend/app/ValueObjects/WeatherData.php`
- `backend/app/Models/WeatherForecast.php`

## 5. PA12 realizacijos tipas

PA12 realizuotas kaip vidinis backend service metodų srautas, o ne kaip atskiras UI veiksmas. Realus PA12 inicijavimo taškas diagramoje yra:

`CalendarGenerationService -> WeatherService: getForecastRange(city, startDate, endDate)`

Šaltinis:
- `backend/app/Services/Calendar/CalendarGenerationService.php:79`

## 6. Iš kokių PA / procesų kviečiamas PA12

Pagrindinis rastas kvietimo kelias:

`PlotCalendarPage::handleGenerate()` -> `api.generateCalendar()` -> `POST /plots/{plot}/calendars` -> `CalendarController::store()` -> `TaskCalendarService::generate()` -> `CalendarGenerationService::generateCalendar()` -> `WeatherService::getForecastRange()`

Taip pat rastas techninis repair kelias:
- `WeatherForecastRepairService` kviečia `WeatherService::getForecastRange()` taisydamas įtartinus anksčiau sugeneruotų kalendorių orų įrašus.
- `weather:repair-forecasts` console command yra administracinis/techninis kelias, ne pagrindinis PA12 naudotojo scenarijus.

Šaltiniai:
- `frontend/src/pages/calendar/PlotCalendarPage.jsx:367`
- `frontend/src/lib/api.js:303`
- `backend/routes/api.php:108`
- `backend/app/Http/Controllers/Calendar/CalendarController.php:47`
- `backend/app/Services/Calendar/TaskCalendarService.php:18`
- `backend/app/Services/Calendar/CalendarGenerationService.php:79`
- `backend/app/Services/Calendar/WeatherForecastRepairService.php:57`
- `backend/routes/console.php:27`

## 7. Meteo.lt vietos / miesto kodo gavimas

`WeatherService::fetchDailyForecasts()` kviečia `MeteoLtClient::findPlaceByCity($city)`. Klientas kviečia `get('/places')`, dekoduoja JSON ir parenka vietą per `resolvePlaceMatch($city, $places)`. Gautas `place['code']` vėliau naudojamas ilgalaikei prognozei.

Šaltiniai:
- `backend/app/Services/Calendar/WeatherService.php:190`
- `backend/app/Services/Integrations/MeteoLtClient.php:14`
- `backend/app/Services/Integrations/MeteoLtClient.php:23`
- `backend/app/Services/Integrations/MeteoLtClient.php:28`
- `backend/app/Services/Integrations/MeteoLtClient.php:92`

## 8. Meteo.lt prognozės gavimas

`WeatherService::fetchDailyForecasts()` kviečia `MeteoLtClient::getLongTermForecast((string) ($place['code'] ?? ''))`. Klientas kviečia Meteo.lt endpoint:

`GET /places/{placeCode}/forecasts/long-term`

Šaltiniai:
- `backend/app/Services/Calendar/WeatherService.php:191`
- `backend/app/Services/Integrations/MeteoLtClient.php:37`
- `backend/app/Services/Integrations/MeteoLtClient.php:39`
- `backend/app/Services/Integrations/MeteoLtClient.php:63`

## 9. Gautų duomenų validavimas

Validavimas nėra išskirtas į atskirą `validateForecastPayload()` metodą. Jis realizuotas tiesioginėmis patikromis ir exception srautu:
- `MeteoLtClient::findPlaceByCity()` tikrina, ar `/places` atsakymas yra ne tuščias masyvas.
- `MeteoLtClient::getLongTermForecast()` tikrina, ar payload yra masyvas ir ar `forecastTimestamps` yra netuščias masyvas.
- `WeatherService::fetchDailyForecasts()` papildomai tikrina `forecastTimestamps`, praleidžia netinkamus timestamp įrašus ir meta exception, jei nesusidaro nė viena tinkama dienos grupė.
- `WeatherService::getForecastRange()` pagauna `Throwable` ir pereina į fallback srautą.

Šaltiniai:
- `backend/app/Services/Integrations/MeteoLtClient.php:25`
- `backend/app/Services/Integrations/MeteoLtClient.php:41`
- `backend/app/Services/Integrations/MeteoLtClient.php:48`
- `backend/app/Services/Calendar/WeatherService.php:194`
- `backend/app/Services/Calendar/WeatherService.php:200`
- `backend/app/Services/Calendar/WeatherService.php:216`
- `backend/app/Services/Calendar/WeatherService.php:34`

## 10. Fallback į paskutinius saugomus sistemos duomenis

Fallback vyksta metode `WeatherService::storedFallbackForDate($city, $date)`.

Pirmas bandymas:
- `WeatherForecast::query()`
- `where('city', $city)`
- `whereDate('date', $date->toDateString())`
- `orderByDesc('id')`
- `first()`

Jeigu įrašas rastas, jis paverčiamas `WeatherData` per `weatherDataFromStoredForecast(..., SOURCE_STORED_CITY_DATE)`.

Šaltiniai:
- `backend/app/Services/Calendar/WeatherService.php:325`
- `backend/app/Services/Calendar/WeatherService.php:327`
- `backend/app/Services/Calendar/WeatherService.php:334`
- `backend/app/Services/Calendar/WeatherService.php:381`

## 11. Seasonal/default fallback

Jei nerandamas tos pačios miesto/datos įrašas, kodas ieško tos pačios datos įrašo, prioritetizuodamas tą patį miestą per `orderByRaw('CASE WHEN city = ? THEN 0 ELSE 1 END', [$city])`.

Jei ir toks įrašas nerandamas, kviečiamas `seasonalFallbackForDate($date)`, kuris pagal mėnesį parenka lokalius numatytuosius orų profilius ir grąžina `WeatherData` su `source = seasonal` ir `isSeasonalFallback = true`.

Šaltiniai:
- `backend/app/Services/Calendar/WeatherService.php:337`
- `backend/app/Services/Calendar/WeatherService.php:339`
- `backend/app/Services/Calendar/WeatherService.php:344`
- `backend/app/Services/Calendar/WeatherService.php:347`
- `backend/app/Services/Calendar/WeatherService.php:350`
- `backend/app/Services/Calendar/WeatherService.php:369`

## 12. Orų prognozės apdorojimas ir išsaugojimas

Meteo.lt timestamp įrašai grupuojami pagal dieną:
- `Carbon::parse($timestamp, 'UTC')->toDateString()`
- `aggregateDay(collect($dayEntries))`
- `selectConditionCode($conditionCodes)`
- `new WeatherData(...)`

`CalendarGenerationService::generateCalendar()` gauna `weatherByDate`, kiekvieną dieną paverčia į `WeatherData::fromArray($weather)`, apskaičiuoja `averageTemperature()` ir sukuria `WeatherForecast` įrašą.

Šaltiniai:
- `backend/app/Services/Calendar/WeatherService.php:211`
- `backend/app/Services/Calendar/WeatherService.php:222`
- `backend/app/Services/Calendar/WeatherService.php:228`
- `backend/app/Services/Calendar/WeatherService.php:263`
- `backend/app/Services/Calendar/CalendarGenerationService.php:81`
- `backend/app/Services/Calendar/CalendarGenerationService.php:82`
- `backend/app/Services/Calendar/CalendarGenerationService.php:84`
- `backend/app/Services/Calendar/CalendarGenerationService.php:86`

## 13. Route / API helper sluoksniai, kurie rasti, bet nerodomi lifeline

Rasti, bet diagramoje nerodomi:
- `routes/api.php` maršrutas `POST /plots/{plot}/calendars`
- `routes/api.php` dev maršrutas `GET /dev/plant-care-test/weather`
- `frontend/src/lib/api.js` helperis `generateCalendar(plotId, payload)`
- React Router / `App.jsx` kalendoriaus route

Šie elementai yra audito kontekstas, bet pagal užduoties taisykles jie nėra lifeline.

Šaltiniai:
- `backend/routes/api.php:46`
- `backend/routes/api.php:108`
- `frontend/src/lib/api.js:303`
- `frontend/src/App.jsx:254`

## 14. Galutinės diagramos žinučių skaičius

Galutinėje diagramoje yra 99 numeruotos žinutės.

## 15. Ar diagrama padalinta

Diagrama nepadalinta į kelias dalis. Ji pateikta kaip viena seka, nes realus PA12 procesas yra vienas vidinis service srautas:

1. Meteo.lt užklausa ir validavimas.
2. Dienų normalizavimas arba fallback.
3. Prognozių išsaugojimas į `WeatherForecast`.

## 16. Panaudoti loop / alt / opt fragmentai

Panaudoti fragmentai:
- `alt: Meteo.lt fetch result`
- `loop: [for each forecast timestamp]`
- `loop: [for each grouped forecast day]`
- `loop: [for each requested planning date]`
- `alt: Forecast data source for date`
- `alt: Stored forecast availability`
- `opt: [liveFetchFailed or mixed/non-api sources]`
- `loop: [for each returned weather date]`

Visi `alt` fragmentai turi aiškius operandus ir guard sąlygas.

## 17. Reply rodyklių taisyklė

- Kiekvienas sinchroninis `call` turi vėlesnį `reply`.
- `reply` nėra automatiškai po `call`; jis grįžta po vidinės logikos.
- `WeatherService::fetchDailyForecasts(city)` turi alternatyvius reply: sėkmės šakoje grąžina `dailyForecasts`, klaidos šakoje grąžina `exception`.
- UI event / sisteminis startas gali būti async ir be reply, bet PA12 galutinėje diagramoje async/UI event nėra naudojamas, nes PA12 nėra atskiras UI veiksmas.

## 18. Alt fragmentų taisyklė

- Kiekvienas `alt` turi operandus.
- Kiekvienas operand turi guard.
- Klaidos arba trūkstamos datos šakoje naudojamas fallback:
  - pirmiausia tos pačios miesto/datos `WeatherForecast`;
  - tada tos pačios datos kitų miestų `WeatherForecast`;
  - tada `seasonalFallbackForDate()`.
- Korektiškų duomenų šakoje vykdomas Meteo.lt payload dekodavimas, validavimas, timestamp grupavimas, dienos agregavimas ir `WeatherData` paruošimas.
- Bendras išsaugojimas į `WeatherForecast` iškeltas po `WeatherService::getForecastRange()` atsakymo, nes realiame kode saugo `CalendarGenerationService`, o ne `WeatherService`.

## 19. Visų sekų sąrašas HTML puslapio apačioje

HTML apačioje pateiktas pilnas sekų sąrašas, sugeneruotas iš to paties `messages` ir `fragments` modelio kaip SVG diagrama. Lentelėje yra:
- Iš
- Į
- Operacija
- Jungties tipas
- Fragmentas / pastaba
- Replies to

Sąraše taip pat matosi:
- `alt pradžia`
- operandų pradžia ir pabaiga
- `alt pabaiga`
- `loop pradžia` ir `loop pabaiga`
- `opt pradžia` ir `opt pabaiga`

## 20. Testų / elgsenos patvirtinimai

Rasti testai patvirtina:
- live Meteo.lt fake atsakymai naudojami kalendoriaus generavimui;
- Meteo.lt timeout atveju naudojamas saugomas `WeatherForecast`;
- trūkstamos datos nepildomos kopijuojant vieną prognozę visoms dienoms;
- jei saugomų duomenų nėra, naudojamas `seasonal` fallback.

Šaltiniai:
- `backend/tests/Feature/WeatherFallbackCalendarTest.php:45`
- `backend/tests/Feature/WeatherFallbackCalendarTest.php:145`
- `backend/tests/Feature/CalendarGenerationTest.php:687`
- `backend/tests/Feature/CalendarGenerationTest.php:735`
- `backend/tests/Feature/CalendarGenerationTest.php:736`
- `backend/tests/Feature/CalendarGenerationTest.php:737`

## 21. Nepatvirtintos vietos

Nepatvirtintų dalių neliko. Vienintelė modeliavimo pastaba: `WeatherData` nėra Eloquent modelis, bet diagramoje rodomas kaip «entity», nes tai reali domeno duomenų klasė, dalyvaujanti prognozės normalizavime ir išsaugojime.
