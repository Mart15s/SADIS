# SADiS

Asmeninio sodo ar daržo informacinė sistema, skirta sklypų, zonų, augalų, darbų kalendoriaus, inventoriaus, derliaus ir bendruomenės funkcijoms valdyti. Sistema įgyvendinta kaip React vieno puslapio aplikacija ir Laravel REST API.

## Technologijos

- Backend: Laravel, Eloquent ORM, Laravel Sanctum
- Frontend: React, Vite
- Duomenų bazė: PostgreSQL
- Integracijos: Meteo.lt orų prognozė, Perenual augalų priežiūros duomenys, el. pašto serveris slaptažodžio atkūrimui
- PDF generavimas: Dompdf
- Diegimas: Docker / Render konfigūracija repo šaknyje

## Pagrindinės funkcijos

- Naudotojų registracija, prisijungimas, atsijungimas ir slaptažodžio atkūrimas
- Sklypų, augalų zonų ir augalų valdymas
- Vizualus sklypo plano redagavimas ir PDF eksportas
- Augalų būklės, derliaus, rotacijos ir planavimo istorijos sekimas
- Rekomendacinis darbų kalendorius su Meteo.lt orų duomenimis
- Inventoriaus ir sunaudotų medžiagų valdymas
- Sklypų bendrinimas su peržiūros arba redagavimo teisėmis
- Bendruomenės įrašai ir administratoriaus naudotojų valdymas

## Aplinkos kintamieji

Backend pavyzdys pateiktas faile `backend/.env.example`. Lokaliai sukurkite `backend/.env` ir užpildykite PostgreSQL, el. pašto ir išorinių API reikšmes.

Svarbiausi laukai:

- `APP_KEY`
- `APP_URL`
- `DB_CONNECTION=pgsql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `SANCTUM_STATEFUL_DOMAINS`
- `MAIL_*`
- `PERENUAL_API_KEY`
- `METEO_LT_BASE_URL`

Produkcijai skirtų Render kintamųjų pavyzdžiai pateikti `.env.render.example`. Realūs raktai, slaptažodžiai ir duomenų bazės prisijungimai į repozitoriją neturi būti keliami.

Vercel + Render architektūra, saugus migracijų ir demo duomenų paleidimas bei rollback aprašyti [`DEPLOYMENT.md`](DEPLOYMENT.md).

## Paleidimas lokaliai

Backend:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Frontend atskirame terminale:

```powershell
cd frontend
npm install
npm run dev
```

## Migracijos ir demo duomenys

Įprastas migracijų paleidimas:

```powershell
cd backend
php artisan migrate
```

Demo duomenys nėra kuriami automatiškai be aiškaus pasirinkimo. Gynimui skirtą demonstracinį rinkinį galima paleisti taip:

```powershell
cd backend
php artisan db:seed --class=CurrentVersionDemoSeeder
```

Papildoma informacija apie demonstracinius duomenis pateikta `DEMO_DATA.md` ir `DEMO_ACCOUNT.md`.

## Patikros komandos

Backend:

```powershell
cd backend
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan migrate
php artisan test
```

Frontend:

```powershell
cd frontend
npm run lint
npm test
npm run build
```
