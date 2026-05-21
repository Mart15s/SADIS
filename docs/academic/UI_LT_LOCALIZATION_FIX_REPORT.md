# UI lietuvinimo ir matavimo vienetų sutvarkymo ataskaita

## 1. Kas pakeista

| Sritis | Pakeitimas | Failai | Statusas |
| --- | --- | --- | --- |
| Bendri UI tekstai | Lokalizuoti bendri loading, empty state, toast, dialog, navigacijos, lentelių ir įrankių tekstai. | `frontend/src/components`, `frontend/src/App.jsx` | Atlikta |
| Puslapiai | Lokalizuoti matomi tekstai pagrindiniuose naudotojo moduliuose: auth, dashboard, sklypai, augalai, kalendorius, inventorius, bendruomenė, analitika, dalijimasis ir administravimas. | `frontend/src/pages` | Atlikta |
| Sklypo redaktorius | Lokalizuoti plano, ribų, zonų, sluoksnių, inspektorių, PDF eksportavimo ir juodraščio veiksmų tekstai. | `frontend/src/pages/plot`, `frontend/src/components/plot`, `frontend/src/components/garden` | Atlikta |
| Enum rodymas | Pridėti rodymo helperiai būsenoms, prioritetams, rolėms, inventoriaus tipams, augalų būklei ir augimo etapams. | `frontend/src/lib/constants.js` | Atlikta |
| Matavimo vienetai | Pridėti ploto, ilgio, kiekio ir inventoriaus vienetų formatavimo helperiai su Lietuvos naudotojams įprastais vienetais. | `frontend/src/lib/constants.js`, `frontend/src/lib/plotMeasurements.js` | Atlikta |
| Backend pranešimai | Lokalizuoti naudotojui matomi auth/password reset/logout pranešimai. | `backend/app/Http/Controllers/User`, `backend/tests/Feature/Auth/AuthenticationTest.php` | Atlikta |

## 2. Išversti UI moduliai

- Auth: prisijungimas, registracija, slaptažodžio atkūrimas, profilis.
- Dashboard: santraukos, tuščios būsenos, greiti veiksmai.
- Sidebar/Navbar: pagrindinė navigacija ir viršutinės antraštės.
- Sklypai: sąrašas, kortelės, kūrimas, redagavimas, PDF veiksmai.
- Sklypo redaktorius: ribos, zonos, sluoksniai, inspektoriai, juodraščio veiksmai.
- Augalai: sąrašas, forma, detalės, būklės istorija.
- Augalų katalogas: rankinis įvedimas ir Perenual importo UI.
- Kalendorius: užduotys, būsenos, prioritetai, orų šaltiniai, inventoriaus įspėjimai.
- Inventorius: forma, sąrašas, vienetai, tipai, papildymo veiksmai.
- Derlius: registravimas, istorija, lentelės.
- Rotacija: juodraštis, sprendimai, istorija, rekomendacijų paaiškinimai.
- Istorija: planavimo versijos, metrika, augalų sąrašai.
- Analitika: analizės pasirinkimai, rezultatai, no-data ir warning būsenos.
- Bendruomenė: įrašų kūrimas, filtrai, kortelės.
- Dalijimasis: prieigos rolės ir veiksmai.
- Administravimas: naudotojų valdymas ir rolės.

## 3. Sutvarkyti matavimo vienetai

Sukurti ir pritaikyti helperiai:

- `formatArea(valueInSquareMeters)`
- `formatLength(valueInMeters)`
- `formatQuantity(value, unit)`
- `formatInventoryUnit(unit)`
- `formatSquareMetersValue(value)`

Palaikomi vienetai: `m²`, `a`, `ha`, `m`, `cm`, `kg`, `g`, `l`, `ml`, `vnt.`, `pak.`, `maiš.` ir suderinamumo žemėlapiai senoms reikšmėms, pvz. `pcs`, `units`, `liters`, `meters`.

Plotai rodomi taip:

- iki 100 m²: tik `m²`;
- nuo 100 m²: `m²` ir `a`;
- nuo 10 000 m²: `m²`, `a` ir `ha`.

Pritaikyta sklypų kortelėse, sklypo kūrime/redagavime, plano redaktoriuje, zonų matmenyse, istorijos peržiūroje, PDF/preview susijusiame UI ir inventoriaus/derliaus kiekiuose.

## 4. Kas nekeista

- API route pavadinimai nekeisti.
- DB lentelės, migracijos ir modelių struktūra nekeista.
- DB enum reikšmės nekeistos; verčiamas tik jų rodymas UI sluoksnyje.
- Verslo logika nekeista.
- Geometrijos skaičiavimo logika nekeista; atnaujintas tik realių plotų ir matmenų pateikimas.

## 5. Patikros rezultatai

- `npm run build` praėjo.
- `npm run test -- --testTimeout=15000` praėjo: 16 failų, 61 testas.
- `php artisan migrate --force` praėjo: nėra naujų migracijų.
- `php artisan test` praėjo: 241 testas, 1515 assertion.
- Likutinė rizika: kai kurie backend rotacijos diagnostiniai tekstai tebėra generuojami anglų kalba pagal esamus testus, todėl UI lygyje išversti tik dažniausi naudotojui matomi paaiškinimai; DB ir backend algoritmo kontraktai nepakeisti.

Verdiktas: UI dabar gerokai tinkamesnis Lietuvos naudotojams ir BPP gynimui; pagrindiniai naudotojui matomi tekstai ir matavimo vienetai sutvarkyti lietuviškai, nekeičiant sistemos verslo logikos.
