# BPP audito taisymo ataskaita

## 1. Kas buvo sutvarkyta

| Audito rizika | Atliktas veiksmas | Failai | Statusas |
| --- | --- | --- | --- |
| PHPUnit testai naudojo tik SQLite, nors BPP akcentuoja PostgreSQL | Sukurtas atskiras PostgreSQL testu env pavyzdys ir PHPUnit subseto konfiguracija; PostgreSQL fixture klaidos testuose pataisytos be verslo logikos keitimo | `backend/.env.testing.pgsql.example`, `backend/phpunit.pgsql.xml`, `backend/tests/Feature/AccessRightsTest.php`, `backend/tests/Feature/CommunityTest.php`, `docs/POSTGRESQL_TEST_VERIFICATION.md` | Atlikta |
| NFR2 ikelimo laikas <= 3 s neturejo matavimo artefakto | Sukurtas rankines patikros checklist su puslapiais, route, desktop/mobile ir screenshot laukais | `docs/NFR_VERIFICATION_CHECKLIST.md` | Atlikta, realius laikus reikia uzpildyti demo aplinkoje |
| NFR3 responsive atitiktis neturejo irodymu | I ta pati checklist itraukta 1366 px ir 390 px patikra bei screenshot instrukcija | `docs/NFR_VERIFICATION_CHECKLIST.md` | Atlikta, screenshotus reikia surinkti pries gynima |
| Render/demo aplinka priklauso nuo env reiksmiu | Papildyti env pavyzdziai ir Render dokumentacija: `APP_KEY`, `APP_URL`, `DATABASE_URL`, `DB_CONNECTION=pgsql`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE`, `PERENUAL_API_KEY`, `METEO_LT_BASE_URL`, mailer, migraciju ir demo seederio flag'ai | `backend/.env.example`, `.env.render.example`, `DEPLOY_RENDER.md` | Atlikta |
| Password reset demonstravimas priklauso nuo SMTP | Dokumentuotas saugus `MAIL_MAILER=log` variantas be realiu SMTP slaptazodziu | `.env.render.example`, `DEPLOY_RENDER.md` | Atlikta |
| Demo dokumentuose buvo pasenusios paskyros | Atnaujinti demo prisijungimai ir dataset aprasymas pagal `CurrentVersionDemoSeeder` | `DEMO_ACCOUNT.md`, `DEMO_DATA.md` | Atlikta |
| Gynime gali kilti klausimu del techniniu lenteliu | Sukurtas paaiskinimas apie `personal_access_tokens`, `password_reset_tokens`, `has_plot`, `has_inventory`, `used_on` | `docs/GYNIMO_TECHNINIAI_PAAISKINIMAI.md` | Atlikta |
| Gynime gali kilti klausimu del „bendradarbio“ kaip roles | Atskirtos paskyros roles `owner/admin` nuo sklypo teisiu `viewer/editor` | `docs/GYNIMO_TECHNINIAI_PAAISKINIMAI.md` | Atlikta |
| Demo duomenu pakankamumas | Patikrinta, kad `CurrentVersionDemoSeeder` jau sukuria 2 sklypus, 16 zonu, 27 augalus su katalogu/`plant_care`, inventoriu, oru fallback, kalendoriu, atlikimo/resursu scenarijus, bukles istorija, derliu, rotacija, snapshotus, bendruomene ir viewer/editor prieigas | `backend/database/seeders/CurrentVersionDemoSeeder.php`, `FullFlowDemoAccountSeeder.php`, `DemoDataSeeder.php` | Kodo keisti nereikejo |

Pakeisti failai sio taisymo metu:

- `BPP_AUDIT_FIX_REPORT.md`
- `.env.render.example`
- `DEPLOY_RENDER.md`
- `DEMO_ACCOUNT.md`
- `DEMO_DATA.md`
- `backend/.env.example`
- `backend/.env.testing.pgsql.example`
- `backend/phpunit.pgsql.xml`
- `backend/tests/Feature/AccessRightsTest.php`
- `backend/tests/Feature/CommunityTest.php`
- `docs/POSTGRESQL_TEST_VERIFICATION.md`
- `docs/NFR_VERIFICATION_CHECKLIST.md`
- `docs/GYNIMO_TECHNINIAI_PAAISKINIMAI.md`

## 2. Kas nebuvo keista ir kodel

- FR funkcionalumas nekeistas, nes audite visi 1.1-1.30 FR pazymeti kaip ATITINKA.
- Migracijos nekeistos destruktyviai ir senos technines lenteles netrintos, nes tai rizikinga pries pridavima ir gali pazeisti suderinamuma.
- `CurrentVersionDemoSeeder` verslo duomenu logika nekeista, nes esamas seederis jau dengia audito reikalauta demo scenariju rinkini.
- Realiu API raktu, SMTP slaptazodziu ar Render `DATABASE_URL` neprideta del saugumo.
- Nauja Playwright ar kita sunki E2E priklausomybe neprideta, nes projekte jos nera, o audito tikslui pakanka rankinio irodymu checklist.

## 3. Kaip patikrinti PostgreSQL testine aplinka

Sukurti atskira testine DB:

```powershell
psql -U postgres -h 127.0.0.1 -p 5432 -c "CREATE DATABASE sad_test;"
```

Paruosti env:

```powershell
cd backend
Copy-Item .env.testing.pgsql.example .env.testing
php artisan key:generate --env=testing --show
```

Irasyti sugeneruota `APP_KEY` i `.env.testing`, patikrinti, kad DB yra tik testine:

```env
DB_CONNECTION=pgsql
DB_DATABASE=sad_test
DB_USERNAME=postgres
DB_PASSWORD=postgres
DB_URL=
```

Paleisti migracijas ir audito subseta:

```powershell
php artisan config:clear --env=testing
php artisan migrate:fresh --env=testing --database=pgsql
vendor\bin\phpunit --configuration phpunit.pgsql.xml
```

Lokali patikra siame taisyme:

- `vendor\bin\phpunit --configuration phpunit.pgsql.xml` - OK, 161 tests, 755 assertions.
- Pirmas PostgreSQL subseto paleidimas parode, kad `AccessRightsTest` ir `CommunityTest` fixture duomenyse truko `garden_owner_id`; testu duomenys pataisyti, pakartotinis paleidimas praejo.
- `psql` komanda siame terminale nebuvo PATH'e, todel DB kurimo komandos per `psql` nebuvo vykdytos, bet PHP/PDO PostgreSQL testinis rysys su `sad_test` veikė.

## 4. Kaip patikrinti NFR2 ir NFR3

1. Paleisti demo aplinka arba atidaryti Render URL.
2. Prisijungti `demo.owner@example.test` / `password`.
3. Chrome DevTools -> Network -> ijungti `Disable cache`.
4. Pirma karta atnaujinti puslapi, kad serveris atsibustu.
5. Antra karta spausti `Ctrl+R` ir fiksuoti warm start ikelimo laika.
6. Kartoti route: `/login`, `/`, `/plots`, `/plots/{plotId}`, `/plots/{plotId}/calendar`, `/inventory`, `/plots/{plotId}/analytics`, `/catalog-plants`, `/community`.
7. DevTools device toolbar patikrinti 1366 px ir 390 px plocius.
8. Rezultatus ir screenshot failu pavadinimus irasyti i `docs/NFR_VERIFICATION_CHECKLIST.md`.

Lighthouse galima naudoti kaip papildoma irodymo saltini, bet BPP lentelei svarbiausia uzfiksuoti praktini warm start laika ir responsive screenshotus.

## 5. Demo paruosimo checklist

- Render env: nustatyti `APP_KEY`, `APP_URL`, `DATABASE_URL`, `DB_CONNECTION=pgsql`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE=true`.
- Demo seederis: `RUN_MIGRATIONS=true`, `RUN_DEMO_SEEDER=true`, `DEMO_SEEDER_CLASS=CurrentVersionDemoSeeder`; po sekmingo redeploy grazinti `RUN_DEMO_SEEDER=false`.
- Perenual: jei demonstruojamas live importas, nustatyti `PERENUAL_API_KEY`.
- Meteo.lt: palikti `METEO_LT_BASE_URL=https://api.meteo.lt/v1`, demo sklypams naudoti atpazistama miesta, pvz. Vilnius.
- Password reset: naudoti realu SMTP arba saugu `MAIL_MAILER=log`.
- PDF eksportas: prisijungus owner/editor/viewer sugeneruoti viena sklypo PDF.
- Kalendorius: parodyti weather context, pending/completed/buy/resource scenarijus.
- Inventorius: parodyti likuti ir bent viena uzduoties atlikima, kuris normaliai sumazina medziagu kieki.
- Istorija: parodyti `plot_snapshots`, bukles istorija, derliaus istorija ir rotacija.
- Analitika: sugeneruoti planning, condition ir harvest analizes.
- Mobile vaizdas: pries gynima uzfiksuoti bent pagrindiniu puslapiu 390 px screenshotus.

## 6. Likutines rizikos

- NFR2/NFR3 realus laikas ir screenshotai dar turi buti surinkti konkrečioje demo aplinkoje.
- Render sekme priklauso nuo realiu env reiksmiu, ypac `APP_KEY`, `DATABASE_URL`, `SANCTUM_STATEFUL_DOMAINS` ir HTTPS cookie nustatymu.
- Perenual live demonstracija priklauso nuo realaus API rakto ir tiekejo prieinamumo; be rakto galima rodyti lokalu katalogo/fallback scenariju.
- Password reset el. laisko gavimas priklauso nuo SMTP; be jo naudoti `MAIL_MAILER=log`.
- Frontend build praejo, bet Vite perspejo apie didesni nei 500 kB JS chunk'a. Tai nera funkcine klaida, taciau NFR2 reikia pagristi realiu warm start matavimu.
- Darbo medyje buvo kitu, siame taisyme neliestu pakeitimu; jie nebuvo revertinti ar refactorinti.

## Patikros rezultatai

- `php artisan route:list` - OK, 87 routes.
- `vendor\bin\phpunit --configuration phpunit.pgsql.xml` - OK, 161 tests, 755 assertions, PostgreSQL `sad_test`.
- `php artisan test --filter "AuthenticationTest|AccountProfileTest|PlotManagementTest|GeometryPersistenceTest|PlantManagementApiTest|CatalogPlantApiTest|CalendarGenerationTest|InventoryCalendarWorkflowTest|HarvestTest|RotationRecommendationTest|AnalyticsTest|AccessRightsTest|CommunityTest|PdfExportTest"` - OK, 161 tests, 755 assertions, default test profile.
- `php artisan test --filter "AccessRightsTest|CommunityTest"` - OK, 30 tests, 101 assertions.
- `npm run build` - OK, Vite chunk-size warning.
- `npm test -- --run` - OK, 16 files, 61 tests.
- Secret pattern scan over changed env/docs - OK, real secrets nerasta.

Verdiktas: po siu pakeitimu sistema yra geriau paruosta BPP gynimui pagal rasto darba. Pagrindines recenzijos rizikos del PostgreSQL testinio irodymo, NFR irodymu sablono, demo env, demo paskyru ir techniniu DB/roliu paaiskinimo yra uzdarytos arba paverstos aiskiais patikros zingsniais.
