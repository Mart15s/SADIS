# NFR2 ir NFR3 patikros checklist

Tikslas: surinkti rankinius irodymus, kad pagrindiniai puslapiai po warm start isikrauna per 3 s ir yra naudojami desktop bei mobiliame vaizde.

Warm start reiskia: serveris jau paleistas, pirmas letas Render ar lokalaus serverio uzkrovimas jau ivyko, puslapis atnaujinamas antra karta.

## Patikros lentele

| Puslapis | URL / route | Desktop 1366px patikra | Mobile 390px patikra | Ikelimo laikas po warm start | Rezultatas <= 3 s | Pastabos | Screenshot / irodymo failas |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Login | `/login` |  |  |  |  |  |  |
| Dashboard / pagrindinis langas | `/` |  |  |  |  |  |  |
| Plots | `/plots` |  |  |  |  |  |  |
| Plot detail / sklypo plano redaktorius | `/plots/{plotId}` |  |  |  |  |  |  |
| Calendar | `/plots/{plotId}/calendar` |  |  |  |  |  |  |
| Inventory | `/inventory` |  |  |  |  |  |  |
| Analytics | `/plots/{plotId}/analytics` |  |  |  |  |  |  |
| Catalog plants | `/catalog-plants` |  |  |  |  |  |  |
| Community | `/community` |  |  |  |  |  |  |

## Chrome DevTools matavimas

1. Paleiskite backend ir frontend arba atidarykite Render demo URL.
2. Prisijunkite demo savininku, pvz. `demo.owner@example.test`.
3. Atidarykite Chrome DevTools su `F12`.
4. Network skiltyje ijunkite `Disable cache`.
5. Pirma karta atnaujinkite puslapi ir palaukite, kol serveris pilnai atsibus.
6. Antra karta paspauskite `Ctrl+R`; tai yra warm start matavimas.
7. Network apacioje uzfiksuokite `Finish` arba pagrindinio dokumento/API uzkrovimo laika.
8. I lentele irasykite laika sekundemis ir ar rezultatas `<= 3 s`.
9. Padarykite screenshot su matomu puslapiu ir, jei imanoma, Network laiku.

## Lighthouse variantas

1. DevTools atidarykite `Lighthouse`.
2. Pasirinkite `Desktop` arba `Mobile`.
3. Paleiskite patikra po pirmojo serverio uzsildymo.
4. Fiksuokite `Performance` rezultata ir `Largest Contentful Paint` / `Total Blocking Time`, bet BPP lentelei svarbiausias praktinis puslapio ikelimo laikas.

## Responsive patikra

1. DevTools paspauskite `Toggle device toolbar`.
2. Desktop patikrai naudokite 1366 px ploti.
3. Mobile patikrai naudokite 390 px ploti.
4. Patikrinkite, ar nera horizontalaus scroll, persidengiancio teksto, neprieinamu mygtuku arba pasleptu pagrindiniu veiksmu.
5. Kiekvienam puslapiui issaugokite screenshot pavadinimu, pvz. `nfr-login-mobile-390.png`.

## Automatizavimo pastaba

Siame projekte frontend testams naudojamas Vitest ir React Testing Library. Playwright priklausomybes siuo metu nera, todel naujos sunkios E2E priklausomybes nepridedamos. Jei veliau Playwright jau bus idiegtas, minimalus variantas butu:

- prisijungti demo naudotoju;
- atidaryti auksciau nurodytus route;
- nustatyti viewport `1366x768` ir `390x844`;
- issaugoti screenshot artefaktus;
- patikrinti, kad puslapio pagrindinis heading arba turinio konteineris matomas.
