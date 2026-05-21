# SADiS

Asmeninio sodo ar daržo informacinė sistema:

- Laravel REST API su Sanctum autentifikacija kataloge `backend/`
- React SPA kataloge `frontend/`
- PostgreSQL duomenų bazė
- Docker/Render production paleidimas iš repo šaknies

## Lokalūs veiksmai

Backend paruošimas:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Prieš migracijas `.env` faile nurodykite PostgreSQL prisijungimą. Production ir bendrinami example failai neturi realių raktų ar slaptažodžių.

Frontend paruošimas atskirame terminale:

```powershell
cd frontend
npm install
npm run dev
```

Frontend build:

```powershell
cd frontend
npm run build
```

## Demo duomenys

Numatytasis `php artisan db:seed` be env flagų demo duomenų nekuria. Aktualų demonstracinį rinkinį paleiskite aiškiai:

```powershell
cd backend
php artisan db:seed --class=CurrentVersionDemoSeeder
```

Jei demo rinkinį norite paleisti per bendrą `DatabaseSeeder`:

```powershell
$env:RUN_DEMO_SEEDER='true'
php artisan db:seed
```

`Demo1RichDataSeeder` paleidžiamas tik su `RUN_DEMO1_RICH_SEEDER=true` arba tiesioginiu `--class` kvietimu ir tik tada, kai jau yra jo tikslinis `demo1@gmail.com` demo pasaulis.

## Patikros

```powershell
cd backend
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan test
php artisan route:list
```

```powershell
cd frontend
npm run lint
npm test
npm run build
```

## Render

Render diegimas aprašytas [DEPLOY_RENDER.md](DEPLOY_RENDER.md). Svarbiausi env kintamieji:

- `APP_KEY`
- `APP_URL`
- `DATABASE_URL`
- `SANCTUM_STATEFUL_DOMAINS`
- `SESSION_SECURE_COOKIE`
- `PERENUAL_API_KEY`
- `RUN_MIGRATIONS`
- `RUN_DEMO_SEEDER`
- `RUN_DEMO1_RICH_SEEDER`

Saugūs pavyzdžiai pateikti `.env.render.example` ir `backend/.env.example`.

## Dokumentacija

Specifikacijos šaltinis yra `garden_system_spec.docx`. Architektūriniai, audito ir sugeneruoti akademiniai artefaktai laikomi `docs/`, kad repo šaknyje liktų tik vykdymui ir diegimui reikalingi failai.
