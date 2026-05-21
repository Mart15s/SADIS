# Gynimo techniniai paaiskinimai

Sis dokumentas skirtas trumpai paaiskinti DB ir roliu klausimus, kurie gali kilti recenzuojant sistema pagal BPP aprasyma.

## Technines ir pagalbines DB lenteles

Galutine BPP lentele apraso pagrindines dalykines sistemos esybes. Kode taip pat yra kelios technines arba suderinamumo lenteles, kurios nekuria nauju funkciniu reikalavimu.

| Lentele | Paaiskinimas |
| --- | --- |
| `personal_access_tokens` | Laravel Sanctum technine lentele API autentifikacijos tokenams. Ji reikalinga prisijungimui, atsijungimui ir `auth:sanctum` apsaugotiems REST API route. |
| `password_reset_tokens` | Laravel slaptazodzio atkurimo lentele. Ji naudojama forgot/reset password srautui ir nera atskira darzo domeno esybe. |
| `has_plot` | Sena / suderinamumo jungiamoji lentele tarp savininko/profilio ir sklypo. Dabartinis pagrindinis ownership rysys yra per `plots.garden_owner_id`, taciau lentele palikta migraciju ir senesniu duomenu suderinamumui. |
| `has_inventory` | Sena / suderinamumo jungiamoji lentele tarp savininko/profilio ir inventoriaus iraso. Dabartinis pagrindinis rysys yra per `inventory_items.garden_owner_id`. |
| `used_on` | Technine jungiamoji lentele, nurodanti, kokiai zonai/sklypui taikoma uzduotis. Ji padeda susieti uzduociu kalendoriaus ir zonu konteksta, bet nekeicia pagrindinio BPP duomenu modelio logikos. |

Sios lenteles netrinamos pries pridavima, nes jos naudojamos arba gali buti reikalingos migraciju, suderinamumo ir autentifikacijos stabilumui.

## Naudotojo roles ir sklypo prieigos teises

Sistema turi du skirtingus teisiu lygius:

| Lygmuo | Reiksmes | Kur naudojama |
| --- | --- | --- |
| Paskyros lygmens role | `owner`, `admin` | `users.role`; nusako, ar naudotojas yra darzo savininkas, ar administratorius. |
| Sklypo bendradarbiavimo teise | `viewer`, `editor` | `access_rights.role`; nusako konkretaus sklypo skaitymo arba redagavimo teise. |

„Bendradarbis“ rašto darbe reiskia naudotoja, kuriam suteikta konkretaus sklypo prieigos teise per `access_rights`. Tai nera trecia `users.role` reiksme.

Praktinis paaiskinimas gynime:

```text
Paskyros lygmeniu sistema turi tik owner ir admin roles. Bendradarbiavimas sprendziamas ne kuriant papildoma naudotojo role, o per konkretaus sklypo prieigos irasa: viewer arba editor. Todel tas pats naudotojas gali buti savininkas savo sklypuose ir kartu viewer/editor kitame sklype.
```
