# PostgreSQL testines patikros instrukcija

Si instrukcija skirta irodyti, kad pagrindiniai BPP scenarijai veikia su PostgreSQL, o ne tik su PHPUnit numatytuoju SQLite `:memory:` profiliu.

Svarbu: naudokite tik atskira testine duomenu baze, pvz. `sad_test`. Niekada neleiskite siu komandu pries production, Render ar aktyvia lokalia darbo baze.

## 1. Paruosti atskira PostgreSQL DB

Windows PowerShell pavyzdys, kai `psql` pasiekiamas per PATH:

```powershell
psql -U postgres -h 127.0.0.1 -p 5432 -c "CREATE DATABASE sad_test;"
```

Jei baze jau yra ir norite pradeti nuo tuscios testines DB:

```powershell
psql -U postgres -h 127.0.0.1 -p 5432 -c "DROP DATABASE IF EXISTS sad_test;"
psql -U postgres -h 127.0.0.1 -p 5432 -c "CREATE DATABASE sad_test;"
```

Pries `DROP DATABASE` dar karta patikrinkite, kad komandoje tikrai nurodyta `sad_test`, o ne darbo ar production DB.

## 2. Paruosti Laravel testu env

Is projekto saknies:

```powershell
cd backend
Copy-Item .env.testing.pgsql.example .env.testing
php artisan key:generate --env=testing --show
```

Sugeneruota rakta irasykite i `.env.testing` kaip `APP_KEY`. Jei PostgreSQL naudotojas ar slaptazodis skiriasi, pakeiskite tik siuos laukus:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sad_test
DB_USERNAME=postgres
DB_PASSWORD=postgres
DB_URL=
```

## 3. Patikrinti konfiguracija

```powershell
php artisan config:clear --env=testing
php artisan migrate:fresh --env=testing --database=pgsql
```

Komanda turi veikti tik pries `sad_test`. Jei gaunate prisijungimo klaida, nestumkite testu toliau - sutvarkykite DB host/user/password.

## 4. Paleisti BPP scenariju testu subseta

Tam pridetas atskiras PHPUnit config failas `backend/phpunit.pgsql.xml`. Jis naudoja `DB_CONNECTION=pgsql` ir apima pagrindinius audite nurodytus feature testus.

```powershell
vendor\bin\phpunit --configuration phpunit.pgsql.xml
```

Jei norite paleisti tik viena sriti:

```powershell
vendor\bin\phpunit --configuration phpunit.pgsql.xml --filter AuthenticationTest
vendor\bin\phpunit --configuration phpunit.pgsql.xml --filter CalendarGenerationTest
vendor\bin\phpunit --configuration phpunit.pgsql.xml --filter PdfExportTest
```

Pastaba: siame projekte `php artisan test --configuration=...` gali perduoti PHPUnit konfiguracija du kartus. Del to PostgreSQL profiliui rekomenduojamas tiesioginis `vendor\bin\phpunit` kvietimas.

Subsete yra:

- `AuthenticationTest`
- `AccountProfileTest`
- `PlotManagementTest`
- `GeometryPersistenceTest`
- `PlantManagementApiTest`
- `CatalogPlantApiTest`
- `CalendarGenerationTest`
- `InventoryCalendarWorkflowTest`
- `HarvestTest`
- `RotationRecommendationTest`
- `AnalyticsTest`
- `AccessRightsTest`
- `CommunityTest`
- `PdfExportTest`

## 5. Ka fiksuoti kaip irodyma

I gynimo medziaga arba `BPP_AUDIT_FIX_REPORT.md` galima irasyti:

```text
Data:
Aplinka:
DB: PostgreSQL sad_test
Komanda: vendor\bin\phpunit --configuration phpunit.pgsql.xml
Rezultatas:
```

Jei testai nepasileidzia del lokaliai neveikiancio PostgreSQL serverio, tai nera kodo taisymo signalas. Tokiu atveju pazymekite, kad reikia sukurti `sad_test` DB, suvesti `.env.testing` prisijungimo duomenis ir pakartoti komanda.
