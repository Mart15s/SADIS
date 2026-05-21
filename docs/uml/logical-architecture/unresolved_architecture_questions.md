# Neišspręsti loginės architektūros klausimai

1. `backend/app/Policies` katalogo nėra. Prieigos kontrolė realizuota per `AuthorizesPlotAccess`, `AdminMiddleware` ir `App\Services\Plot\AccessService`, todėl diagramoje nerodomos Laravel Policy klasės.

2. `backend/app/Jobs`, `backend/app/Console` ir `backend/app/Observers` katalogų nerasta. Jei baigiamajame darbe reikia aptarti fonines užduotis ar observer'ius, reikia patikrinti, ar jų nėra planuojama pridėti vėliau.

3. `ConditionHistoryController` egzistuoja faile `backend/app/Http/Controllers/Plant/ConditionHistoryController.php`, bet `routes/api.php` naudoja `PlantConditionController`. Reikia rankiniu būdu patikrinti, ar `ConditionHistoryController` yra paliktas dėl suderinamumo, ar jau nebenaudojamas.

4. `PlantCareDebugController` turi `dev.only` maršrutus. Jis praleistas pagrindinėje diagramoje, nes atrodo kaip diagnostinis / vystymo valdiklis, ne kaip pagrindinės sistemos loginės architektūros dalis.

5. `PlantCareDefaults`, `PlantCareRepairService`, `WeatherForecastRepairService` ir `InventoryPlanningRepairService` atlieka pagalbines arba remonto funkcijas. Kai kurie jų įtraukti į inventorių, tačiau jų vietą galutinėje architektūros schemoje verta suderinti su tuo, kiek thesis tekste bus kalbama apie duomenų taisymo mechanizmus.

6. `Nominatim` atvirkštinis geokodavimas realiai naudojamas per `ReverseGeocodingService`, tačiau pirminė projekto specifikacija akcentuoja `Meteo.lt` ir `Perenual`. Reikia patikrinti, ar ši integracija turi būti aiškiai aprašyta baigiamajame darbe kaip papildoma integracija.

7. Pašto infrastruktūra realiai naudojama slaptažodžio atkūrimui per `EmailServerBoundary` ir `PasswordResetLinkMail`, tačiau konkretus produkcinis maileris priklauso nuo `MAIL_MAILER` ir aplinkos kintamųjų. Dabartinė `config/mail.php` numatytoji reikšmė yra `log`.

8. Frontend ir backend aiškiai atskirti katalogais (`frontend`, `backend`) ir Vite dev proxy naudoja `VITE_BACKEND_URL`, tačiau reikia rankiniu būdu patikrinti galutinį diegimą: ar produkcijoje jie diegiami kaip du atskiri artefaktai, ar frontend statiniai failai pateikiami per tą pačią infrastruktūrą.

9. PostgreSQL yra numatytoji Laravel duomenų bazės jungtis (`DB_CONNECTION` numatytoji reikšmė `pgsql`), bet `database.php` vis dar turi standartines Laravel `sqlite`, `mysql`, `mariadb` ir `sqlsrv` jungčių konfigūracijas. Produkcinėje aplinkoje reikia patvirtinti, kad naudojamas būtent PostgreSQL.

10. `HasPlot`, `HasInventory`, `UsedOn`, `InventoryUsageLog`, `TaskResourceRequirement` ir `RotationPlanDraft` yra Eloquent modeliai, bet jų vaidmuo loginėje architektūroje yra labiau pagalbinis / ryšio duomenų. Jie įtraukti į esybių paketą, tačiau sistemos klasių modelyje reikės nuspręsti, ar juos rodyti kaip atskiras klases ar kaip asociacinius / pagalbinius modelius.

11. Frontend geometrijos failai (`plotGeometry.js`, `plotDesigner.js`, `plotRender.js`, `plotMeasurements.js`, `plotWorkspaceDraft.js`) yra svarbūs sklypo redaktoriui, bet loginės architektūros diagramoje neįtraukti, kad schema liktų skaitoma. Jei darbo skyriuje reikės pabrėžti geometrijos apdorojimą frontend pusėje, galima pridėti atskirą „Sklypo geometrijos pagalbiniai moduliai“ paketą.

12. Failų saugyklos integracijos architektūroje nerasta kaip savarankiškos išorinės sistemos. `config/filesystems.php` egzistuoja kaip Laravel standartinė konfigūracija, bet pagal rastą kodą pagrindiniai srautai naudoja PDF atsakymą tiesiogiai, o ne atskirą failų saugyklos posistemę.
