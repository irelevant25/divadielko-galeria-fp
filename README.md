# divadielko-galeria-fp

Dočasná (maintenance) stránka pre **Divadielko Galéria** — bábkové divadielko pri
Mestskom kultúrnom stredisku v Novom Meste nad Váhom.

Stránka je dvojjazyčná (SK / EN), bez závislostí a bez buildu — čisté PHP + CSS.

- Run locally

```
php -S localhost:8000
```

Potom otvorte <http://localhost:8000/>. Prepínanie jazyka: `?lang=sk`, `?lang=en`.

## Nasadenie na websupport.sk

Cez FTP / File Manager nahrajte **obsah** tohto priečinka do `web/` (document root):

```
index.php
robots.txt
.htaccess
assets/css/style.css
assets/img/favicon.svg
includes/config.php
includes/lang.php
```

Vyžaduje PHP 7.4+ (testované na PHP 8.5). Žiadna databáza, žiadny composer.

**Po vydaní SSL certifikátu** odkomentujte blok `HTTPS` v [.htaccess](.htaccess) —
predtým by presmerovanie stránku rozbilo.

## Čo kde meniť

| Chcem zmeniť | Súbor |
| --- | --- |
| Texty (SK aj EN) | [includes/lang.php](includes/lang.php) |
| Odkazy, kontakt, rok založenia | [includes/config.php](includes/config.php) |
| Vzhľad | [assets/css/style.css](assets/css/style.css) |
| Rozloženie, bábka (SVG) | [index.php](index.php) |

Nový jazyk = nový blok v `lang.php` + pridanie kódu do `languages` v `config.php`.
Prepínač v hlavičke sa vykreslí sám.

## Detaily

- **HTTP 503 + Retry-After** — kým je `send_503 => true` v `config.php`, stránka sa
  hlási ako dočasne nedostupná, takže si ju Google nezaindexuje namiesto ostrého
  webu. Po spustení ostrej stránky prepnite na `false`.
- **Voľba jazyka** — `?lang=` → cookie (`dg_lang`, 1 rok) → `Accept-Language`
  (češtinu berieme ako slovenčinu) → `sk`. Neznámy kód spadne na slovenčinu.
- **`.htaccess`** presmeruje všetky neexistujúce URL na `index.php`, takže staré
  adresy z pôvodného webu tiež zobrazia oznam.
- **Prístupnosť** — skip link, `aria-current` na aktívnom jazyku, viditeľný focus,
  rešpektuje `prefers-reduced-motion` (bábka sa prestane hojdať).
- **SEO / zdieľanie** — `hreflang` pre obe mutácie, Open Graph, JSON-LD
  (`PerformingGroup`) s adresou a odkazmi na sociálne siete.
- **Analytika (Umami)** — merací skript sa vkladá podľa `analytics` v
  `config.php`. Na `localhost` sa nevloží, takže vývoj nekazí štatistiky.
  Umami nepoužíva cookies ani osobné údaje → netreba cookie lištu.
  Zbiera aj referrer, čiže je vidieť, odkiaľ návštevníci prišli.

### TODO pred spustením ostrého webu

- [ ] Odkazy, ktoré postujeme na FB/IG, označiť UTM parametrami
      (napr. `?utm_source=instagram&utm_medium=bio`) — z in-app prehliadačov
      často nechodí referrer a návštevy by spadli pod „Direct“.
- [ ] Doplniť `canonical_base` v `config.php`, keď je doména finálna.
- [ ] Doplniť `og:image` (1200×630 px) — pri zdieľaní na Facebooku sa teraz
      ukáže len text bez obrázka.
