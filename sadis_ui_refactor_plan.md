# SADIS UI sutvarkymo ir suvienodinimo planas Codex darbui

Šis dokumentas skirtas naudoti kartu su Codex, kad visa „Personal Garden Information System“ / „SADIS“ sąsaja būtų sutvarkyta nuosekliai, nepraleidžiant paslėptų panelių, drawer’ių, modalų, tabų, empty state’ų, filtrų, formų ir darbo ekranų. Tikslas nėra pavienis „pagražinimas“, o sisteminis UI/UX refaktorizavimas per bendrą dizaino sistemą.

---

## 1. Pagrindinis tikslas

Sutvarkyti visos sistemos UI taip, kad ji atrodytų kaip vienas vientisas, profesionalus, produkto lygio SaaS / garden planner įrankis.

Sistema turi išlaikyti šiltą „garden planner“ identitetą, tačiau turi būti labiau disciplinuota, aiški ir operacinė. Darbo langai neturi atrodyti kaip landing page. Pagrindinis naudotojo darbas turi būti matomas pirmiausia, o aiškinamasis tekstas, dekoratyviniai blokai ir perteklinės kortelės neturi trukdyti atlikti veiksmų.

---

## 2. Griežti apribojimai

Codex negali:

- keisti backend verslo logikos;
- keisti API endpoint’ų;
- keisti duomenų modelių;
- keisti autentifikacijos logikos;
- keisti calendar generation logikos;
- keisti weather rules, inventory coverage, plant placement, zone geometry ar draft save logikos;
- šalinti funkcionalių mygtukų;
- pašalinti esamų route’ų;
- pridėti naujos UI bibliotekos be būtinybės;
- palikti debug tekstų, console log’ų ar laikinų komentarų;
- taisyti tik vieną screenshotuose matomą vietą ir ignoruoti pasikartojančius pattern’us.

Leidžiama:

- centralizuoti UI komponentus;
- sutvarkyti CSS / SCSS / Tailwind klases;
- sutrumpinti perteklinius tekstus;
- pagerinti layout, spacing, tipografiją, korteles, mygtukus, badge;
- perkelti UI blokus į aiškesnę vietą, jei funkcionalumas išlieka toks pats;
- refaktorizuoti pasikartojančius UI pattern’us į bendrus komponentus;
- pagerinti responsive elgseną.

---

## 3. Bendras darbo metodas Codex’ui

Codex turi dirbti etapais, o ne chaotiškai keisti puslapius.

### 3.1. Pirmas etapas – inventorizacija

Prieš bet kokius pakeitimus Codex turi peržiūrėti frontend struktūrą ir susidaryti UI žemėlapį.

Reikia identifikuoti:

- `AppShell` arba pagrindinį layout komponentą;
- `Sidebar`;
- `TopBar` / global header;
- `PageHeader`;
- breadcrumbs komponentus;
- tabs komponentus;
- bendrus arba dubliuotus `Button`, `Card`, `Badge`, `Input`, `Select`, `Modal`, `Drawer`, `Toolbar` komponentus;
- visus puslapius ir route’us;
- visus paslėptus arba sąlyginius UI komponentus:
  - modalus;
  - drawers;
  - accordions;
  - dropdowns;
  - popovers;
  - inspectors;
  - empty states;
  - loading states;
  - error states;
  - confirm dialogs.

### 3.2. Antras etapas – design system sluoksnis

Prieš masinį puslapių tvarkymą reikia sukurti arba sutvarkyti bendrus UI komponentus:

- `AppShell`
- `Sidebar`
- `TopBar`
- `PageHeader`
- `Breadcrumbs`
- `Tabs`
- `SectionCard`
- `MetricCard`
- `InfoPanel`
- `InlineNotice`
- `InspectorPanel`
- `Toolbar`
- `Button`
- `Badge`
- `FilterBar`
- `FormField`
- `Input`
- `Select`
- `DateField`
- `EmptyState`
- `LoadingState`
- `ErrorState`
- `Modal`
- `Drawer`
- `ConfirmDialog`

Jeigu tokie komponentai jau egzistuoja, naujų dublių kurti negalima. Reikia sutvarkyti esamus ir migruoti puslapius į juos.

### 3.3. Trečias etapas – puslapiai

Puslapiai turi būti tvarkomi po vieną, bet naudojant tuos pačius bendrus komponentus ir taisykles:

1. Dashboard
2. Community
3. Plots list
4. Plot detail / editor
5. Plot calendar
6. Plot history
7. Plot harvests
8. Plot analytics
9. Plot sharing
10. Plot rotation
11. Plants / plant catalog
12. Inventory
13. Account
14. Login / register / password reset
15. Visi modalai, drawer’iai, inspectors, empty states, loading states ir error states

### 3.4. Ketvirtas etapas – regressions ir vizualinė peržiūra

Po pakeitimų Codex turi patikrinti ne tik matomus puslapius, bet ir sąlygines būsenas:

- kai yra duomenų;
- kai nėra duomenų;
- kai kraunama;
- kai API grąžina klaidą;
- kai forma turi validacijos klaidų;
- kai yra unsaved draft;
- kai pasirinkta zona;
- kai zona nepasirinkta;
- kai kalendorius sugeneruotas;
- kai kalendoriaus nėra;
- kai yra inventory shortage;
- kai yra connected / disconnected būsena;
- kai naudotojas yra owner;
- kai naudotojas turi viewer/editor teises;
- kai yra mobilus arba siauresnis ekranas.

---

## 4. Dizaino sistemos taisyklės

### 4.1. Spalvų semantika

Spalvos turi turėti vienodą reikšmę visoje sistemoje.

| Spalvos paskirtis | Naudojimas |
|---|---|
| Primary / orange | pagrindinis veiksmas, aktyvus navigacijos elementas, pagrindinis CTA |
| Success / green | connected, saved, active, completed |
| Warning / amber/brown | shortage, fallback, unsaved draft, inventory blocker |
| Danger / red | delete, destructive, remove |
| Info / blue | selected, synced, neutral information |
| Neutral / sand/gray | background, card, border, muted surfaces |

Taisyklės:

- Oranžinė turi reikšti pagrindinį veiksmą, o ne visus įmanomus akcentus.
- Žalia neturi būti naudojama atsitiktiniam dekorui, jei ji reiškia active/success.
- Warning spalva turi būti naudojama trūkumams, fallback’ams ir nesaugotoms būsenoms.
- Badge spalva turi atitikti statuso semantiką.
- Spalvų kontrastas turi būti pakankamas tekstui skaityti.

### 4.2. Spacing sistema

Naudoti vieningą 4 px bazės ritmą.

| Token | Reikšmė |
|---|---|
| 4 px | micro spacing |
| 8 px | small spacing |
| 12 px | compact gap |
| 16 px | default gap |
| 24 px | card / section internal gap |
| 32 px | section gap |
| 48 px | major layout separation |

Taisyklės:

- Page padding turi būti vienodas pagrindiniuose puslapiuose.
- Card padding paprastai 20–24 px.
- Compact card padding 12–16 px.
- Section gap 24–32 px.
- Kortelės viduje elementų tarpai 12–16 px.
- Nenaudoti atsitiktinių `margin-top`, `padding`, `gap`, kurie neatitinka sistemos ritmo.

### 4.3. Radius ir shadows

Reikia vienodų radius/shadow taisyklių.

| Elementas | Radius / shadow principas |
|---|---|
| Main cards | vienodas radius, subtilus border, minimalus shadow |
| Nested cards | mažesnis shadow, daugiau border logikos |
| Buttons | vienodas radius pagal variantą |
| Inputs | vienodas radius ir height |
| Badges | pill arba rounded, bet nuosekliai |
| Modals/drawers | aiškus radius, bet ne dekoratyviai per didelis |

Taisyklė: kortelės neturi atrodyti paimtos iš skirtingų UI bibliotekų.

### 4.4. Tipografija

Rekomenduojama hierarchija:

| Tipas | Dydis / svoris |
|---|---|
| Page title | 28–32 px, 700 |
| Section title | 20–22 px, 700 |
| Card title | 16–18 px, 700 |
| Body text | 15–16 px, 400–500 |
| Muted text | 14 px, 400 |
| Label | 12–13 px, 600 |
| Button text | 14–15 px, 600 |

Taisyklės:

- Dekoratyvus šriftas neturi būti naudojamas ilgam body tekstui.
- Uppercase naudoti tik statusams, trumpiems label’iams ir badges.
- Ilgesni tekstai turi būti lengvai skaitomi.
- Teksto eilutės plotis turi būti ribojamas, kai tekstas ilgesnis.
- Kortelėse vengti ilgų paaiškinamųjų pastraipų.

---

## 5. Bendrų komponentų reikalavimai

### 5.1. Button

Variantai:

- `primary`
- `secondary`
- `ghost`
- `danger`
- `icon`

Reikalavimai:

- Pagrindinis veiksmas ekrane turi būti aiškus.
- Secondary veiksmai neturi konkuruoti su primary.
- Danger veiksmai turi būti vizualiai atskirti.
- Mygtuko tekstas neturi lūžti į neprofesionalias vertikalias eilutes.
- Jeigu elementas nėra spaudžiamas, jis neturi atrodyti kaip mygtukas.

### 5.2. Badge / Chip

Variantai:

- `status`
- `count`
- `category`
- `filter`
- `role`

Pavyzdžiai:

- `CONNECTED`
- `OWNER`
- `UNSAVED DRAFT`
- `ACTIVE`
- `SHARED`
- `4 POINTS`
- `162 TASKS`

Reikalavimai:

- Badge neturi atrodyti kaip button.
- Vienodos paskirties badge turi atrodyti vienodai visuose puslapiuose.
- Statuso spalva turi atitikti semantiką.

### 5.3. SectionCard

Naudojama visiems pagrindiniams puslapio blokams.

Variantai:

- default;
- muted;
- nested;
- interactive;
- danger;
- compact.

Reikalavimai:

- Vienodas border/radius/shadow/padding.
- Kortelės title/action alignment turi būti nuoseklus.
- Nested kortelės neturi atrodyti sunkesnės už parent kortelę.

### 5.4. MetricCard

Naudojama skaitinėms reikšmėms:

- plots;
- zones;
- plants;
- inventory;
- plot area;
- perimeter;
- side lengths;
- tasks count.

Reikalavimai:

- Vienodas dydis, label, value, optional accent.
- Ilgos reikšmės turi tvarkingai wrap’intis arba būti sutrumpintos.
- Metric card neturi užimti daugiau vietos nei jos informacinė vertė.

### 5.5. InspectorPanel

Ypač svarbus Plot Editor puslapiui.

Reikalavimai:

- Vienas panelis vietoje daug atskirų atsitiktinių kortelių.
- Sticky desktop režime.
- Responsive režime gali tapti drawer arba persikelti po canvas.
- Turi turėti aiškius skyrius:
  - selected object;
  - metrics;
  - actions;
  - related items;
  - preview;
  - advanced.

### 5.6. FilterBar

Naudojama Community, Plants, Inventory, Calendar ir kitur.

Reikalavimai:

- Search input;
- select/filter controls;
- result count;
- optional clear filters;
- vienodas aukštis, spacing ir alignment.

### 5.7. FormField

Reikalavimai:

- label;
- input;
- helper text;
- error text;
- disabled state;
- focus state;
- vienodas height.

### 5.8. EmptyState / LoadingState / ErrorState

Reikalavimai:

- visi puslapiai turi turėti tvarkingas tuščias, krovimo ir klaidos būsenas;
- tekstas trumpas;
- turi būti aiškus kitas veiksmas, jei toks yra;
- empty state neturi atrodyti kaip klaida.

---

## 6. AppShell ir navigacijos reikalavimai

### 6.1. Header overlay problema

Tai kritinė problema.

Reikalavimai:

- TopBar negali dengti turinio.
- Sticky/fixed header turi turėti teisingą layout offset.
- Page content negali prasidėti po header’iu.
- Scrollinant tab’ai, titles, formos, kalendoriaus grid ar editor toolbar negali būti nukirsti.

Acceptance criteria:

- Atidarius bet kurį route’ą, pirmas puslapio turinio blokas matomas pilnai.
- Scrollinant niekas nelenda po header’iu taip, kad būtų sunku skaityti ar naudoti.

### 6.2. Sidebar

Reikalavimai:

- aktyvus meniu punktas aiškus, bet ne per sunkus;
- sidebar neturi konkuruoti su darbo zona;
- naudotojo informacija apačioje tvarkinga;
- email truncation turi atrodyti profesionaliai;
- mažesniuose ekranuose sidebar turi susitraukti arba tapti drawer.

### 6.3. TopBar

Reikalavimai:

- kompaktiškas;
- nenurodo per daug pasikartojančio teksto;
- dešinėje connection status + account;
- nekonkuruoja su PageHeader;
- globalus sistemos aprašymas neturi užimti per daug vietos kiekviename darbo puslapyje.

---

## 7. Puslapių reikalavimai

## 7.1. Dashboard

Problemos:

- per didelis hero blokas;
- per daug marketinginio teksto;
- dubliuojami veiksmai;
- metrikos integruotos nevienodai;
- Active plots ir Today’s garden work kortelės turi skirtingą ritmą.

Reikalavimai:

- Dashboard turi atrodyti kaip operacinė suvestinė.
- Hero bloką sumažinti arba pakeisti į compact summary.
- Metrikas iškelti į vienodą 4 kortelių grid’ą.
- Palikti vieną aiškų pagrindinį veiksmą.
- Active plots ir Today’s garden work naudoti tą pačią `SectionCard` sistemą.
- Tekstus sumažinti 40–60 %.

Acceptance criteria:

- Pagrindiniai duomenys matomi be perteklinio scroll.
- Puslapis neatrodo kaip landing page.
- Cards, badges, buttons sutampa su bendra design system.

---

## 7.2. Community

Problemos:

- filtrai išsidėstę netolygiai;
- post kortelės per plačios ir tuščios;
- author badge atsietas;
- shared plot layers preview atrodo kaip tuščia dėžė;
- trūksta aiškaus veiksmo.

Reikalavimai:

- naudoti bendrą `FilterBar`;
- post kortelės struktūra:
  - title;
  - badges;
  - author/date;
  - description;
  - snapshot/preview;
  - action;
- description riboti iki 2–3 eilučių;
- preview turi turėti aiškią struktūrą;
- jei preview duomenų nėra, rodyti compact empty state;
- result count turi būti vienoje filtrų eilutėje.

Acceptance criteria:

- Community atrodo kaip tvarkingas sąrašo puslapis.
- Post kortelės turi aiškų hierarchy ir action.
- Filtrai atrodo kaip bendros sistemos dalis.

---

## 7.3. Plots list

Reikalavimai:

- plot kortelės turi atitikti bendrą `InteractiveCard` arba `SectionCard` pattern;
- plot statusai, role badges, metrics turi naudoti vieningus badge/metric komponentus;
- actions turi būti aiškūs ir nedubliuoti vienas kito;
- empty state, jei nėra plots, turi būti tvarkingas.

Acceptance criteria:

- Plots list vizualiai sutampa su Dashboard active plots ir Community card sistema.

---

## 7.4. Plot detail / Plot Editor

Tai svarbiausias darbo ekranas.

Dabartinės problemos:

- canvas nėra pagrindinis darbo paviršius;
- per daug didelių tekstų ir metrikų prieš canvas;
- toolbar, layers, stats ir canvas atrodo kaip atskiri blokai;
- dešinysis panelis fragmentuotas;
- dideli tušti plotai;
- kai kurios panelės ir `Optional planting data` atsiranda neaiškiai;
- `Add plant to draft` mygtukas lūžta vertikaliai.

Reikalavimai:

### Header

Sutraukti į compact plot header:

- Back;
- breadcrumbs;
- title;
- badges;
- actions;
- tabs.

Ilgą aprašymą sutrumpinti arba pašalinti.

### Workspace

Naudoti aiškią darbo struktūrą:

```text
[Compact plot header]
[Tabs]
[Workspace grid]
  [Canvas area]
    [Toolbar]
    [Compact metrics/status row]
    [Canvas]
  [Inspector panel]
```

### Canvas

- turi būti matomas iš karto atidarius editor tab;
- turi užimti apie 60–70 % pagrindinės darbo zonos;
- neturi būti nustumtas žemyn dėl didelių metrikų;
- toolbar turi būti prijungtas prie canvas;
- layers turi būti compact chips;
- zoom indicator turi būti compact.

### Inspector

Vienas `InspectorPanel` su skyriais:

- Selected zone;
- metrics;
- actions;
- Plants in zone;
- Boundary preview;
- Advanced / Optional planting data.

### Veiksmai

Turi veikti kaip anksčiau:

- Select/edit;
- Draw zone;
- Fit to view;
- Reset layout;
- Snap to grid;
- Show dimensions;
- Add plant to draft;
- New zone draft;
- Delete zone;
- Save plot changes;
- Discard draft;
- Export PDF;
- Edit metadata.

Acceptance criteria:

- Atidarius editor puslapį canvas matomas iš karto.
- Dešinysis inspector atrodo kaip vienas panelis.
- Nėra didelių tuščių plotų.
- Mygtukai nelūžta į negražias vertikalias formas.
- Esamas funkcionalumas išlieka.

---

## 7.5. Plot Calendar

Problemos:

- kalendoriaus grid geras, bet aplinka per sunki;
- kairysis rail per daug tekstinis;
- instrukciniai blokai rodomi net kai kalendorius jau egzistuoja;
- layers kortelė per didelė;
- kai kur kalendorius nukirstas header’io;
- statusų spalvos konkuruoja.

Reikalavimai:

### Layout

Naudoti:

```text
[Plot header + tabs]
[Calendar workspace]
  [Control rail 300–340 px]
  [Month grid panel]
```

### Control rail

Turi būti funkcionalus:

- Generate calendar;
- Start date;
- End date;
- Generate button;
- Generated calendars;
- Filters:
  - Plant;
  - Zone;
  - optional status/priority.

### Empty/generated būsenos

- Jeigu kalendoriaus nėra, galima rodyti instrukcinį empty state.
- Jeigu kalendorius jau sugeneruotas, pagrindinis objektas turi būti mėnesio grid.
- „Choose the planning window“, „Generate the recommendation run“, „Open a day...“ neturi būti rodomi kaip pagrindinis turinys sugeneruoto kalendoriaus būsenoje.

### Month grid

- day cells turi išlikti aiškūs;
- Planned, Busy, Shortage, Selected statusai turi turėti vienodą semantiką;
- selected day neturi būti per agresyviai užpildytas;
- workload bars turi būti skaitomi;
- layers turi būti compact eilutė, ne didelė kortelė.

Acceptance criteria:

- Atidarius kalendorių, mėnesio grid yra pagrindinis turinys.
- Kairysis rail neužima per daug vietos.
- Nėra header overlay.
- Filters, generated calendars ir date range išlieka funkcionalūs.

---

## 7.6. History

Reikalavimai:

- naudoti bendras cards;
- snapshot/history entries turi turėti vienodą struktūrą;
- tuščia būsena turi būti aiški;
- veiksmų mygtukai neturi konkuruoti su statusais;
- ilgi tekstai turi būti clamp’inami arba struktūruoti.

---

## 7.7. Harvests

Reikalavimai:

- harvest įrašai turi naudoti tą pačią list/card sistemą;
- formos turi naudoti `FormField`;
- metrics turi naudoti `MetricCard`;
- empty state turi turėti aiškų CTA.

---

## 7.8. Analytics

Reikalavimai:

- charts/panels turi naudoti bendrą card sistemą;
- metrics turi būti vienodi;
- tekstiniai paaiškinimai trumpi;
- jei nėra duomenų, rodyti empty state;
- chart legends neturi atrodyti kaip atsitiktiniai badges.

---

## 7.9. Sharing

Reikalavimai:

- roles, access rights, invited users turi naudoti bendrus badges;
- action buttons turi būti aiškūs;
- destructive/revoke actions turi naudoti danger variantą;
- paaiškinamieji tekstai turi būti trumpi;
- modalai / confirm dialogs turi būti patikrinti.

---

## 7.10. Rotation

Reikalavimai:

- rotation plan cards turi sutapti su kitų modulių cards;
- warning/status badges turi naudoti semantines spalvas;
- ilgi paaiškinimai turi būti sutrumpinti;
- empty state turi būti aiškus;
- action area turi būti nuosekli.

---

## 7.11. Plants / Plant Catalog

Reikalavimai:

- filtrai turi naudoti `FilterBar`;
- plant cards arba lentelės turi būti vieningos;
- plant care details turi būti lengvai skaitomi;
- Perenual / normalized data statusai turi būti aiškūs;
- modalai/drawer’iai katalogo kūrimui/redagavimui turi naudoti bendrą `Modal`/`Drawer`;
- form fields turi būti vienodi.

---

## 7.12. Inventory

Reikalavimai:

- inventory items turi naudoti bendrą list/card/table pattern;
- shortage / low stock / available statusai turi naudoti semantines spalvas;
- actions turi būti aiškūs;
- filters turi naudoti `FilterBar`;
- forms turi naudoti `FormField`;
- empty/loading/error states turi būti tvarkingi.

---

## 7.13. Account

Reikalavimai:

- account form cards turi sutapti su kitų puslapių card sistema;
- profile/user statusai turi naudoti badges;
- logout / destructive actions turi būti aiškiai atskirti;
- form labels, inputs, helper/error texts turi būti vieningi.

---

## 7.14. Auth pages

Patikrinti:

- login;
- register;
- password reset request;
- password reset confirmation;
- validation error states;
- loading states.

Reikalavimai:

- auth puslapiai turi turėti tą pačią brand kryptį;
- form fields turi būti vienodi;
- klaidos turi būti aiškios;
- buttons turi naudoti bendrą sistemą;
- nėra debug tekstų.

---

## 8. Paslėptų ir sąlyginių UI elementų kontrolinis sąrašas

Codex turi specialiai surasti ir sutvarkyti šiuos elementus, nes jie dažniausiai pamirštami:

### 8.1. Modalai

Patikrinti:

- create plot modal;
- edit metadata modal;
- add plant modal;
- plant catalog modal;
- inventory item modal;
- sharing/access modal;
- confirm delete modal;
- auth related modal, jei yra.

Reikalavimai:

- vienodas radius, shadow, header, body, footer;
- primary/secondary/danger actions;
- aiškus focus state;
- scroll modal body, jei turinys ilgas;
- mobile responsive.

### 8.2. Drawer’iai / side panels

Patikrinti:

- day details drawer;
- plant details drawer;
- zone inspector;
- task details;
- any right side panel.

Reikalavimai:

- drawer neturi persidengti su TopBar netvarkingai;
- header/footer turi būti sticky, jei turinys ilgas;
- actions turi būti aiškios;
- mobile gali tapti full-screen arba bottom sheet.

### 8.3. Dropdowns / popovers

Patikrinti:

- account dropdown;
- filter dropdowns;
- action menus;
- select menus;
- date pickers.

Reikalavimai:

- z-index turi būti teisingas;
- dropdown neturi būti nukirstas;
- hover/focus states vienodi;
- spacing ir typography sutampa su design system.

### 8.4. Accordions / details

Patikrinti:

- Optional planting data;
- advanced fields;
- help sections;
- grouped settings.

Reikalavimai:

- accordion turi aiškų header;
- default būsena logiška;
- neturi atsirasti atsitiktinai virš pagrindinio turinio;
- spacing turi būti vieningas.

### 8.5. Empty states

Patikrinti:

- no plots;
- no community posts;
- no plants;
- no inventory items;
- no calendars;
- no history;
- no harvests;
- no analytics data;
- no sharing users;
- no tasks.

Reikalavimai:

- trumpas tekstas;
- aiškus CTA;
- neatrodo kaip error;
- sutampa su bendra card sistema.

### 8.6. Loading states

Patikrinti:

- page loading;
- card loading;
- calendar generation loading;
- plot save loading;
- form submit loading;
- filters loading.

Reikalavimai:

- neturi keisti layout aukščio;
- mygtukai loading būsenoje neturi šokinėti;
- skeleton/spinner turi būti nuoseklus.

### 8.7. Error states

Patikrinti:

- API error;
- validation error;
- failed calendar generation;
- failed save draft;
- failed plant fetch;
- failed inventory update.

Reikalavimai:

- error spalva danger;
- tekstas aiškus;
- jei galima, turi būti retry veiksmas;
- klaidos neturi atrodyti kaip debug output.

---

## 9. Responsive reikalavimai

Tikrinami breakpoint’ai:

- 1920 px desktop;
- 1440 px desktop;
- 1366 px laptop;
- 1024 px tablet / small laptop;
- 768 px tablet;
- 390–430 px mobile.

Reikalavimai:

- iki 1024 px sidebar turi susitraukti arba tapti drawer;
- TopBar turi likti kompaktiškas;
- PageHeader neturi užimti pusės ekrano;
- Plot Editor inspector turi persikelti po canvas arba į drawer;
- Calendar grid turi turėti horizontal scroll arba compact list/day/week režimą;
- mygtukai neturi lūžti į vertikalias teksto kolonas;
- formos turi būti patogios;
- nėra atsitiktinio horizontal overflow.

---

## 10. Konkrečios problemos, kurias būtina ištaisyti

### 10.1. Header dengia turinį

Būtina rasti priežastį ir sutvarkyti layout lygmenyje, o ne kiekviename puslapyje atskirai.

### 10.2. Plot Editor canvas nustumtas žemyn

Canvas turi būti matomas iš karto.

### 10.3. Per daug kortelių stilių

Visi moduliai turi naudoti tą pačią card sistemą.

### 10.4. Per daug tekstų

Darbo puslapiuose sumažinti aiškinamąjį tekstą.

### 10.5. Plot Editor dešinysis panelis fragmentuotas

Sujungti į vieną `InspectorPanel`.

### 10.6. Calendar control rail per sunkus

Padaryti kompaktišką ir funkcionalų.

### 10.7. Mygtukai ir badge maišosi

Statusai neturi atrodyti kaip spaudžiami mygtukai.

### 10.8. Filter bars nevienodi

Community, Plants, Inventory, Calendar turi naudoti vienodą filtrų pattern’ą.

### 10.9. Modalai/drawer’iai gali būti pamiršti

Privaloma peržiūrėti visus conditional render komponentus.

---

## 11. Testavimo planas po UI refaktorizavimo

Codex turi paleisti projekto komandas, kurios egzistuoja repo:

- install, jei reikia;
- lint;
- build;
- test, jei yra;
- typecheck, jei yra.

Papildomai rankiniu būdu arba pagal komponentų struktūrą patikrinti route’us:

- `/dashboard` arba pagrindinis dashboard route;
- `/account`;
- `/community`;
- `/plots`;
- `/plots/:id`;
- `/plots/:id/calendar`;
- `/plots/:id/history`;
- `/plots/:id/harvests`;
- `/plots/:id/analytics`;
- `/plots/:id/sharing`;
- `/plots/:id/rotation`;
- `/plants`;
- `/inventory`;
- login/register/password reset route’us.

Patikrinti veiksmus:

- login/logout;
- open plots;
- create/edit plot metadata;
- select/edit zone;
- draw zone;
- fit to view;
- reset layout;
- snap to grid;
- show dimensions;
- add plant to draft;
- remove plant;
- new zone draft;
- delete zone;
- save plot changes;
- discard draft;
- export PDF;
- generate calendar;
- select generated calendar;
- filter by plant/zone;
- open/select calendar day;
- community search/filter;
- inventory add/edit/delete;
- plant catalog add/edit/fetch, jei yra.

---

## 12. Reikalaujama Codex ataskaita po darbo

Codex turi pateikti ataskaitą:

1. Kokius failus pakeitė.
2. Kokius bendrus UI komponentus sukūrė arba sutvarkė.
3. Kaip sutvarkė header overlay problemą.
4. Kaip pertvarkė Plot Editor.
5. Kaip pertvarkė Calendar.
6. Kaip suvienodino cards, buttons, badges, forms, spacing ir typography.
7. Kaip patikrino paslėptus modalus, drawers, accordions ir empty/loading/error states.
8. Kokias komandas paleido.
9. Ar build/lint/test praėjo.
10. Kokios rizikos liko arba ką reikėtų dar peržiūrėti rankiniu būdu.

---

## 13. Acceptance criteria visam darbui

Darbas laikomas atliktu tik tada, kai:

- visa sistema atrodo vizualiai vientisa;
- Header niekur nedengia turinio;
- Plot Editor canvas matomas iš karto;
- Calendar grid yra pagrindinis Calendar puslapio objektas;
- Dashboard atrodo kaip operacinė suvestinė;
- Community turi tvarkingą filter bar ir post cards;
- visos kortelės naudoja vieną sistemą;
- visi mygtukai naudoja vieną sistemą;
- visi badge/chip naudoja vieną sistemą;
- input/select/date fields atrodo vienodai;
- modalai ir drawer’iai nėra pamiršti;
- empty/loading/error states sutvarkyti;
- responsive režime nėra akivaizdžių layout lūžių;
- funkcionalumas nenukentėjo;
- build/lint/test nekrenta.

---

# Rekomenduojamas Codex promptas

Naudok šį promptą Codex’e kartu su šiuo `.md` failu.

```text
Turiu React / Vite frontend sistemą „Personal Garden Information System“ / „SADIS“. Pridedu failą `sadis_ui_refactor_plan.md`. Jame aprašytas pilnas UI/UX auditas, design system reikalavimai, puslapių reikalavimai, paslėptų modalų/drawer’ių/empty states kontrolinis sąrašas ir acceptance criteria.

Tavo užduotis – ne pavieniui „pagražinti“ kelis screenshotuose matomus puslapius, o nuosekliai sutvarkyti visos sistemos UI per bendrą design system sluoksnį.

Labai svarbu:
- nekeisk backend logikos;
- nekeisk API endpoint’ų;
- nekeisk route’ų;
- nekeisk domeno/verslo logikos;
- nekeisk calendar generation, weather rules, inventory coverage, plant placement, zone geometry ar draft save logikos;
- nepašalink jokio esamo funkcionalumo;
- nepamiršk paslėptų UI vietų: modalų, drawer’ių, popover’ių, dropdown’ų, accordions, empty states, loading states, error states, confirm dialogs;
- netaisyk tik matomų vietų – pirmiausia identifikuok ir centralizuok pasikartojančius UI pattern’us.

Dirbk tokia tvarka:

1. Perskaityk `sadis_ui_refactor_plan.md`.
2. Peržiūrėk visą frontend struktūrą ir sudaryk UI inventorizaciją:
   - AppShell/layout;
   - Sidebar;
   - TopBar;
   - PageHeader;
   - Tabs;
   - Cards;
   - Buttons;
   - Badges;
   - Forms;
   - Filter bars;
   - Modals;
   - Drawers;
   - Inspectors;
   - Empty/loading/error states;
   - Dashboard, Community, Plots, Plot Editor, Plot Calendar, History, Harvests, Analytics, Sharing, Rotation, Plants, Inventory, Account, Auth pages.
3. Pirmiausia sutvarkyk bendrą design system sluoksnį:
   - Button;
   - Badge/Chip;
   - SectionCard;
   - MetricCard;
   - InfoPanel/InlineNotice;
   - InspectorPanel;
   - Toolbar;
   - FilterBar;
   - FormField/Input/Select/DateField;
   - PageHeader;
   - Tabs;
   - EmptyState/LoadingState/ErrorState;
   - Modal/Drawer/ConfirmDialog.
4. Sutvarkyk AppShell layout problemą, kad TopBar/header niekur nedengtų turinio.
5. Tik tada migruok visus puslapius į bendrus komponentus ir vienodą spacing/typography/color sistemą.
6. Ypač kruopščiai sutvarkyk Plot Editor:
   - canvas turi būti matomas iš karto;
   - toolbar turi būti prijungtas prie canvas;
   - metrics turi būti compact;
   - dešinysis inspector turi būti vienas nuoseklus panelis;
   - Optional planting data turi būti accordion/advanced dalyje;
   - mygtukai neturi lūžti vertikaliai.
7. Kruopščiai sutvarkyk Plot Calendar:
   - kairysis rail turi būti kompaktiškas;
   - mėnesio grid turi būti pagrindinis objektas;
   - instrukciniai blokai turi būti rodomi tik empty state, kai kalendoriaus nėra;
   - layers turi būti compact;
   - statusų spalvos turi būti semantiškos.
8. Sutvarkyk Dashboard, Community, Plots list, Plants, Inventory, Account ir visus plot tab’us pagal failo reikalavimus.
9. Specialiai surask ir sutvarkyk visus conditional/paslėptus UI komponentus: modalus, drawers, dropdowns, popovers, accordions, empty/loading/error states.
10. Patikrink responsive breakpoint’us: 1920, 1440, 1366, 1024, 768, 390–430 px.
11. Paleisk projekto lint/build/test/typecheck komandas, kurios egzistuoja repo.
12. Pateik aiškią ataskaitą:
    - ką pakeitei;
    - kokius komponentus sukūrei/sutvarkei;
    - kaip išsprendei header overlay;
    - kaip pertvarkei Plot Editor;
    - kaip pertvarkei Calendar;
    - kaip patikrinai paslėptus UI elementus;
    - kokias komandas paleidai;
    - ar liko rizikų.

Svarbiausias tikslas: po pakeitimų sistema turi atrodyti kaip vientisas, profesionalus, produkto lygio SaaS / garden planner įrankis, o ne kaip atskirų puslapių rinkinys. Funkcionalumas privalo likti nepažeistas.
```
