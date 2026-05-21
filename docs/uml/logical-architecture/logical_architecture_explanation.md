# Sistemos loginė architektūra

Asmeninio sodo ar daržo informacinė sistema logiškai padalinta į dvi pagrindines dalis: `React` vieno puslapio programą ir `Laravel` REST API posistemę. Toks padalijimas atitinka dabartinę projekto struktūrą, kur naudotojo sąsaja yra kataloge `frontend`, o serverinė dalis - kataloge `backend`.

Frontend dalis diagramoje modeliuojama kaip ribinių komponentų sluoksnis. Jai priskiriami puslapiai ir pagrindiniai sąsajos komponentai, kurie atsakingi už naudotojo veiksmus, navigaciją, sklypo plano redagavimą, augalų ir katalogo peržiūrą, kalendorių, inventorių, rotaciją, istoriją, derlių, analitiką, bendruomenę bei administravimą. Šie komponentai tiesiogiai nerealizuoja dalykinės logikos; jie kviečia `api.js` API klientą, kuris per HTTP/JSON perduoda užklausas į Laravel API.

Backend API dalis prasideda nuo `routes/api.php`, kuriame aprašyti REST maršrutai. Maršrutai nukreipia užklausas į Laravel valdiklius. Valdikliai priima užklausas, taiko serverinę validaciją, tikrina autentifikavimą ir prieigos teises, parenka API resursus atsakymams formuoti ir sudėtingesnius veiksmus perduoda servisams.

Dalykinės logikos sluoksnis diagramoje išskirtas atskiru paketu. Jame yra prieigos, sklypo plano, planavimo istorijos, PDF eksporto, augalų priežiūros, rekomendacinio kalendoriaus generavimo, oro prognozių, inventoriaus, derliaus, analitikos, bendruomenės ir išorinių integracijų servisai. Šis sluoksnis įgyvendina skaičiavimus, taisykles, generavimo algoritmus ir ryšius su išoriniais API.

Eloquent modeliai sudaro sistemos esybių ir persistencijos sluoksnį. Modeliai atspindi saugomas domeno esybes: naudotojus, profilius, sodininkus, sklypus, zonas, augalus, augalų priežiūros žinių bazę, būklės istoriją, kalendorius, užduotis, inventorių, rotaciją, derlių, bendruomenės įrašus, prieigos teises ir audito įrašus. Šie modeliai saugomi PostgreSQL duomenų bazėje, kuri diagramoje rodoma už Laravel paketų ribų kaip atskira infrastruktūros dalis.

Išorinės sistemos ir bibliotekos taip pat atskirtos nuo sistemos branduolio. `Meteo.lt` API naudojamas oro prognozėms, `Perenual` API - augalų informacijos ir priežiūros duomenims, `Nominatim` API - atvirkštiniam geokodavimui, `Dompdf` - PDF generavimui, `Laravel Sanctum` - API autentifikavimui, o Laravel Mail / SMTP infrastruktūra - slaptažodžio atkūrimo laiškams siųsti. Pagal rastą kodą šios integracijos kviečiamos per backend servisus, o ne tiesiogiai iš React puslapių.

Diagramoje sąmoningai nerodomi klasių atributai ir operacijos. Šis modelis skirtas loginės architektūros ir paketų sudėties paaiškinimui, todėl pateikiami tik pagrindiniai paketai, komponentai, valdikliai, servisai, resursai ir esybės. Detalus klasių turinys, atributai, operacijos ir ryšiai turi būti nagrinėjami atskirame sistemos klasių modelyje.
