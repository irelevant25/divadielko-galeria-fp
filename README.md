# divadielko-galeria-fp

Web **Divadielka Galéria** — bábkové divadielko pri Mestskom kultúrnom stredisku
v Novom Meste nad Váhom. Čisté PHP + PostgreSQL, bez composeru a bez buildu.
Stránka je len po slovensky (texty rozhrania v `includes/lang.php`, obsah
v databáze v stĺpcoch `*_sk`).

## Tri režimy v jednom kóde

| Režim | Čo vidí návštevník | Súbor |
| --- | --- | --- |
| `wip` | „Opona sa čoskoro dvíha" — pripravujeme novú stránku | [pages/placeholder.php](pages/placeholder.php) |
| `maintenance` | „Máme krátku prestávku" — údržba (bábka s prilbou a kľúčom) | [pages/placeholder.php](pages/placeholder.php) |
| `live` | ostrá jednostránková stránka | [pages/site.php](pages/site.php) |

Režim sa prepína v **administrácii → Nastavenia**. Pevne ho dá nastaviť aj
`'mode'` v `includes/config.local.php` (má prednosť — hodí sa pri nasadzovaní).
Dočasné stránky posielajú **HTTP 503 + Retry-After**, aby ich vyhľadávače
nezaindexovali natrvalo. Kým databáza nie je nastavená, zobrazuje sa
„pripravujeme" (nový kód sa dá nahrať aj pred jej založením); keď je nastavená,
ale nebeží, zobrazí sa údržba.

Keď je čo hrať, dočasné stránky majú navrchu **Práve hráme** — ten istý blok
ako na ostrej stránke (plagát, údaje, ukážka, galéria, termíny), až pod ním bábka,
oznam, odkazy a kontakt. Bez zverejnenej položky vyzerajú ako predtým.

**Prihlásený používateľ vidí vždy ostrú stránku** — aj keď verejnosť ešte vidí
„pripravujeme". Obsah sa tak dá naplniť pred spustením. To, čo vidí verejnosť,
ukáže `/?preview=wip` alebo `/?preview=maintenance` (odkaz je v lište dole).

## Stránka

Jedna stránka so sekciami: **Domov** (úvod a Práve hráme — plagáty a termíny)
· **O nás** · **Galéria** (fotky + videá) · **Súbor** · **Repertoár** ·
**História** (prehľad po rokoch + „Celá história") · **Kontakt** (údaje a formulár).

**Poradie sekcií** (okrem Domova, ten je vždy prvý) a **názvy položiek v menu**
sa menia v administrácii → **Sekcie a menu**; horné menu aj pätička
idú v rovnakom poradí ako sekcie. Nadpis a text pod nadpisom každej sekcie sa
menia ceruzkou priamo na stránke — v úvode aj riadok „Na scéne od roku …"
a veľký názov (ten istý riadok je aj v pätičke).

Aby stránka nebola pri desiatkach fotiek a inscenácií nekonečná, zoznamy
sú **karusely so stránkami** (bodky + šípky, na mobile aj potiahnutím prstom).
Koľko sa zmestí na jednu stranu, závisí od šírky obrazovky:

| Karusel | Mobil → široká obrazovka |
| --- | --- |
| Repertoár | 2 → 3 → 4 → 5 kariet (obrázok, údaje, začiatok popisu) |
| Videá | 1 → 2 → 3 |
| Fotografie | 2 → 3 → 4 |
| História — prehľad po rokoch | 1 → 2 → 3 → 4 roky (najnovší prvý) |

**Súbor** karusel nemá — vypisuje sa ako **záverečné titulky vo filme**: vľavo
úloha (skupina), vpravo jej ľudia pod sebou (na mobile úloha nad menami, všetko
na stred). Skupiny sa pri scrollovaní objavujú postupne.

Pri veľa stranách sa namiesto bodiek ukáže počítadlo „3 / 17". Klik na
inscenáciu otvorí **okno s podrobnosťami** (celý popis, ukážka, galéria
s prehliadačom „3 / 9").

## Úpravy obsahu

- **Prihlásenie:** `/login.php` — na stránke naň nevedie žiadny odkaz, treba ho
  napísať do adresy. Registrácia neexistuje, účty zakladá administrátor.
- Po prihlásení má každý obsah **ceruzku** (upraviť), šípky (poradie) a kôš.
- Pri obrázku sa otvorí **výber súborov** zo `assets/` s náhľadmi, hľadaním
  a tlačidlom na nahratie nového súboru (s ukazovateľom priebehu).
- **Súbor** je rozdelený do **skupín** (úloh, napr. Réžia, Vodič, Čítač). Skupina má
  názov a zoznam ľudí — meno a nepovinne rok („od 2006"). Ten istý človek
  môže byť vo viacerých skupinách. Šípky pri skupine menia poradie skupín;
  ceruzka otvorí okno, kde sa mení názov a ľudia (poradie ↑ ↓, odobratie ×,
  „Pridať človeka" na konci). Novú skupinu pridá tlačidlo pod súborom, kôš ju
  presunie do archívu. Prázdnu skupinu návštevník nevidí.
- **Galéria:** nové fotografie a videá sa pridávajú na začiatok (najnovšie prvé),
  poradie sa mení šípkami. Klik na fotku otvorí prehliadač, klik na video
  väčšie okno, kde sa video spustí; v oboch sa listuje šípkami („3 / 12").
- **Termín predstavenia:** dátum + hodina a minúty (24 h, po štvrťhodinách);
  pod poľom je dátum slovami, nech je jasné, ktorý deň to je.
- **Repertoár** je katalóg inscenácií: názov, podnázov, popis, vlastný
  **obrázok** (napr. fotka z inscenácie), vek, dĺžka, premiéra. Voliteľne
  **ukážka** (nahraté video, alebo odkaz na YouTube) a **galéria obrázkov** —
  v karuseli je karta so začiatkom popisu, všetko ostatné v okne
  s podrobnosťami (ukážka a galéria len vtedy, keď je čo ukázať). Príznaky:
  - **Zobraziť verejnosti** — kým nie je zaškrtnuté, inscenáciu vidia len
    prihlásení (štítok „Skryté"); nové inscenácie začínajú skryté;
  - **Už nehráme** — v repertoári sa zobrazí sivo (prihlásení vidia štítok).
- **Práve hráme** je samostatný zoznam: každá položka má povinný výber
  inscenácie z repertoáru, vlastný **plagát (banner)**, **vstupné**, **kde sa
  hrá** (zobrazí sa raz nad termínmi; termín, ktorý sa hrá inde, môže mať
  vlastné miesto) — nepovinne s **odkazom** (miesto je potom odkazom, napr. na
  stránku miesta) a **odkazom na mapu** (pod miestom „Zobraziť na mape“),
  vlastné **Zobraziť verejnosti** a vlastné termíny. Popis a ostatné údaje berie
  z repertoáru. Hotovú položku pripravíte skrytú a zverejníte, keď je hotová.
  Položiek môže byť viac (poradie = šípky). Odohrané termíny nezmiznú, len zošednú.
- **O nás** — dlhší text o súbore (ceružka pri nadpise sekcie) v jednoduchom
  **editore**: tučné, kurzíva, odkaz, odrážkový zoznam, zrušenie formátovania
  a tlačidlo **HTML** na úpravu kódu. Enter = nový odsek, Shift+Enter = nový riadok;
  text vložený z Wordu či webu príde bez formátovania. Server uloží len povolené
  značky (odseky, tučné, kurzíva, odkazy, zoznamy — `rich_html()` v
  [includes/bootstrap.php](includes/bootstrap.php)), ostatné odstráni. Editor sa dá
  použiť aj pri inom poli: v [includes/entities.php](includes/entities.php) typ
  `richtext`. Kým je text prázdny, návštevník sekciu (ani položku v menu) nevidí.
- **História** má dve záložky s **tými istými údajmi**, len inak zobrazenými:
  **Prehľad po rokoch** (karty rokov — čo a kde, najnovší prvý) a **Celá história**
  (časová os od najstaršieho roku, aj s textami a obrázkami). Roky z „Práve hráme"
  sa skladajú samy z odohraných termínov (aj zo skrytých položiek a z archívu).
  Inscenáciu, ktorá nemá zaškrtnuté „Zobraziť verejnosti", vidí v histórii len
  prihlásený (so štítkom „Skryté") — návštevník až po zverejnení.
  Ručne sa pridáva **záznam**: rok + inscenácia z repertoáru alebo udalosť
  s vlastným názvom, nepovinne miesto, text a obrázok. Záznam s rovnakým rokom
  a inscenáciou sa pripojí k jej odohraným termínom — tak sa doplní text alebo
  fotka aj k automatickému roku.
- **Kôš** presunie obsah do **archívu**: zo stránky zmizne, v administrácii →
  Archív sa dá obnoviť alebo (administrátor) zmazať natrvalo. Archivované
  položky „Práve hráme" tvoria **históriu hrania** (a ostávajú v prehľade po
  rokoch). Výnimka: jednotlivý termín sa košom zmaže hneď (je to len oprava).
- Lišta dole: prepnutie „skryť ceruzky" (náhľad bez nich), administrácia, odhlásenie.

## Administrácia (`/admin.php`)

| Záložka | Kto | Čo |
| --- | --- | --- |
| Správy | všetci | správy z kontaktného formulára (nová / prečítaná / archív / spam) |
| Súbory | všetci | nahrávanie; mazanie len administrátor; stiahnutie originálu; konverzia súborov nahratých cez FTP |
| Archív | všetci | história hrania a všetko presunuté do koša; obnoviť môže každý, natrvalo zmazať len administrátor |
| Sekcie a menu | všetci | poradie sekcií na stránke (= poradie menu a pätičky) a názvy položiek v menu |
| Zálohy | administrátor | záloha celej databázy jedným klikom (s poznámkou), zoznam záloh — kedy, prečo, kto, verzia databázy, čo obsahuje, veľkosť — obnovenie, stiahnutie a zmazanie |
| Používatelia | administrátor | zakladanie účtov, role, heslá |
| Nastavenia | administrátor | režim stránky |
| Môj účet | všetci | zmena hesla |

**Zálohy** sú súbory JSON v `storage/backups/` (z webu neprístupné, v gite
ignorované). Okrem ručných sa záloha urobí **sama pred každou migráciou**
(`setup.php` pri nasadení novej verzie) — keď sa nepodarí, databáza sa
neaktualizuje. Z príkazového riadku: `php setup.php --backup`. Obrázky a videá
(`assets/`) v zálohe nie sú.

**Obnovenie zo zálohy** je v tej istej záložke. Každá záloha si pamätá odtlačok
štruktúry databázy, takže je pri nej vidieť, či sedí na dnešnú verziu:

| Štítok | Čo to znamená |
| --- | --- |
| Zhodná štruktúra | rovnaká databáza ako teraz — obnoví sa všetko |
| Zlučiteľná | staršia verzia; čo medzitým pribudlo, dostane predvolené hodnoty, zrušené stĺpce sa preskočia |
| Nezlučiteľná | záloha nemá povinný údaj — obnoviť sa nedá (tlačidlo tam nie je) |

Pred obnovou sa ukáže, čo sa zmení (koľko záznamov je teraz a koľko bude po
obnove), a urobí sa **záloha súčasného stavu**, takže sa dá vrátiť späť.
Účty a heslá sa štandardne nechávajú tak, ako sú (aby ste sa nevyhodili
z administrácie); obnoviť sa dajú zaškrtnutím. Obnova beží v jednej transakcii —
keď čokoľvek zlyhá, v databáze sa nezmení nič. Prehľad migrácií sa neobnovuje.

Roly: **redaktor** upravuje obsah, nahráva súbory a číta správy;
**administrátor** navyše spravuje používateľov, maže súbory a prepína režim.

## Súbory

```
assets/            verzia pre web (verejná)
assets_original/   originály tak, ako prišli (verejne nedostupné, stiahnuť sa dajú v administrácii)
```

- Obrázky → **AVIF** (dlhšia strana najviac 2400 px, otočenie podľa EXIF).
- Video → **MP4 (H.264 + zvuk Opus)**, najviac 1920 px, + náhľad `.avif`.
- Zvuk → **Opus**.
- Pri nahrávaní je voľba **„Po konverzii zmazať originál"** (platí pre všetky druhy).
- Súbor sa posiela **po kúskoch** (4 MB), takže limit hostingu na veľkosť
  požiadavky nevadí; najväčší súbor je 1 GB (`upload.max_size`).
- Keď server nevie konvertovať (chýba AVIF v GD/Imagick alebo ffmpeg), na web
  ide originál — pokiaľ ho prehliadače zobrazia (JPG/PNG/WebP/MP4…).
  Administrácia → Súbory ukazuje, čo server vie.
- Konverzia je prevzatá z anotoki (`php/api/media_convert.php`).

### Videá

Odporúčanie: **YouTube kanál** (videá môžu byť aj „nezaradené") a na web vložiť
odkaz. Stránka si pri uložení stiahne náhľad k sebe a prehrávač
(youtube-nocookie.com) sa načíta až po kliknutí — kým návštevník video nespustí,
nič sa nenačíta z Google. Odkaz na Instagram sa zobrazí ako dlaždica, ktorá
otvorí príspevok. Pri videu bez vlastného náhľadu (banneru) sa ukáže náhľad
z YouTube, pri nahratom videu záber priamo z videa. Krátke klipy sa dajú aj nahrať — vtedy ich skonvertuje ffmpeg,
čo na zdieľanom hostingu pri dlhom videu nemusí stihnúť časový limit.

## Lokálne spustenie

Treba PHP 8.x (`pdo_pgsql`, `gd` s AVIF alebo `imagick`, `mbstring`, `fileinfo`),
PostgreSQL a voliteľne ffmpeg.

1. Vytvorte `includes/config.local.php` (necommituje sa):

   ```php
   <?php return [
       'secret' => '…aspoň 32 náhodných znakov…',
       'db'     => ['dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=divadielko_galeria', 'user' => 'postgres', 'password' => '…'],
       'mail'   => ['driver' => 'file'],   // správy sa ukladajú do storage/mail/
       'ffmpeg' => 'C:/cesta/k/ffmpeg.exe', // nepovinné
   ];
   ```

2. Databáza, tabuľky a prvý administrátor:

   ```
   php setup.php --create-db --admin=admin --password=dlhe-heslo
   php setup.php --demo        # nepovinné: ukážkový obsah s vygenerovanými obrázkami
   ```

3. Server:

   ```
   php -S localhost:8000 router.php
   ```

   `router.php` zastupuje `.htaccess` (na hostingu sa nepoužíva).

### Kontroly a testy

Projekt nemá PHPUnit ani CI — namiesto nich sú tri skripty (čisté PHP, bez závislostí):

```
php .claude/skills/dg-dev/scripts/check.php            # syntax, číslovanie migrácií, chýbajúce texty v lang.php, entities.php ↔ databáza
php .claude/skills/dg-dev/scripts/testsite.php create  # samostatná kópia webu s vlastnou databázou (…_claude_test)
php .claude/skills/dg-dev/scripts/testsite.php smoke   # ~200 kontrol cez HTTP: práva, CSRF, nahrávanie, viditeľnosť, zálohy, formulár…
```

Testovacia kópia je v dočasnom priečinku systému a na skutočné lokálne údaje nesiaha
(`create --clone-db` si ich len skopíruje — tak sa dá nová migrácia vyskúšať na ostrom obsahu).
`testsite.php serve` ju spustí na `http://127.0.0.1:8765` (prihlásenie `tester` / `tester-heslo-123`),
`testsite.php destroy` ju zmaže. Návody pre Claude Code sú v [CLAUDE.md](CLAUDE.md)
a v [.claude/skills/](.claude/skills/).

## Nasadenie na websupport.sk

1. V administrácii Websupportu vytvorte **PostgreSQL databázu** a **e-mailovú
   schránku** na doméne webu (napr. `web@divadielkogaleria.sk`) — z nej sa
   posielajú správy z formulára (`mail.from`).
2. Nahrajte obsah repozitára do `web/` (bez `includes/config.local.php`,
   `assets/*`, `assets_original/*`, `storage/*`, `router.php`, `.claude/`,
   `CLAUDE.md` a `README.md` — `.htaccess` súbory v týchto priečinkoch nahrajte).
   Zoznam súborov a kontrolu veľkostí po nahratí pripraví
   `php .claude/skills/dg-deploy/scripts/deploy.php plan --target=test|main`.
3. Otvorte `https://…/setup.php` — kým `includes/config.local.php` neexistuje,
   ukáže sa **inštalácia**: zadáte údaje k databáze, skript ich overí a súbor
   sám zapíše (aj s tajným kľúčom a `setup_key`). Ďalej pokračujte bodom 4.

   Súbor sa dá vytvoriť aj ručne — vtedy ho nahrajte ako `includes/config.local.php`:

   ```php
   <?php return [
       'secret'    => '…aspoň 32 náhodných znakov…',
       'db'        => ['dsn' => 'pgsql:host=…;port=5432;dbname=…', 'user' => '…', 'password' => '…'],
       'mail'      => ['driver' => 'mail', 'from' => 'web@divadielkogaleria.sk'],
       'setup_key' => '…dlhý náhodný reťazec…',
       // skúšobná inštalácia (test.divadielkogaleria.sk): nepatrí do vyhľadávačov
       'noindex'   => true,
   ];
   ```

4. Otvorte `https://…/setup.php?key=…` — vytvorí tabuľky a prvého administrátora.
   Po aktualizácii kódu s novou migráciou stačí otvoriť tú istú adresu znova.
   (S SSH robí to isté `php setup.php`.)
5. Priečinky `assets/`, `assets_original/` a `storage/` musia byť zapisovateľné pre PHP.
6. Blok `HTTPS` v [.htaccess](.htaccess) presmeruje `http://` na `https://`. Kým doména
   nemá aktívny SSL certifikát, zakomentujte ho — inak by presmerovanie stránku rozbilo.
7. Stránka začína v režime `wip`. Obsah naplňte prihlásený, potom administrácia →
   Nastavenia → **Ostrá stránka**.

## Čo kde meniť

| Chcem zmeniť | Súbor |
| --- | --- |
| Texty rozhrania, predvolené texty | [includes/lang.php](includes/lang.php) |
| Čo sa dá upravovať (polia formulárov) | [includes/entities.php](includes/entities.php) + migrácia v [includes/migrations/](includes/migrations/) |
| Predvolený kontakt, odkazy, limity nahrávania, analytika | [includes/config.php](includes/config.php) |
| Vzhľad stránky / dočasných stránok / úprav / administrácie | [static/css/](static/css/) |
| Rozloženie ostrej stránky | [pages/site.php](pages/site.php) |

Kontakt, odkazy na sociálne siete a všetky voľné texty sa menia priamo na
stránke ceruzkou — hodnoty v `config.php` sú len predvolené.

## Bezpečnosť a súkromie

- `login.php` a `admin.php` nie sú nikde odkazované, majú `noindex`
  a nie sú ani v `robots.txt`. Prihlasovanie má obmedzený počet pokusov.
- Návštevník nedostane žiadnu cookie — session sa otvára
  len pri prihlásení. Umami meria bez cookies. → **netreba cookie lištu.**
- Kontaktný formulár: skryté pole a časová pasca na roboty, 5 správ za hodinu
  z jednej IP (IP sa neukladá, len jej odtlačok). Správa sa vždy uloží do
  databázy, e-mail je navyše.
- Content-Security-Policy, CSRF tokeny, nahrávanie podľa zoznamu povolených
  prípon s kontrolou obsahu; v `assets/` sa nič nespúšťa.

## TODO pred spustením ostrého webu

- [ ] Vymazať ukážkový obsah (ak bol použitý `--demo`): súbory `demo-*`
      v administrácii a záznamy s „(demo)" na stránke.
- [ ] Doplniť `canonical_base` v `config.local.php`, keď je doména finálna.
- [ ] Odkazy postované na FB/IG označiť UTM parametrami
      (napr. `?utm_source=instagram&utm_medium=bio`).
- [ ] Založiť YouTube kanál pre videá (pozri vyššie).
