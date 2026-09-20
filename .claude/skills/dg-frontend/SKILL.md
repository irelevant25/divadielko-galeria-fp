---
name: dg-frontend
description: Conventions for the look and behaviour of the Divadielko Galéria site — the four stylesheets and who loads them, design tokens, class naming, the carousel contract, overlays (photo lightbox, video player, details sheet), progressive enhancement, accessibility, the dependency-free ES5 JavaScript style, and what the Content-Security-Policy forbids. Use for ANY change to static/css, static/js, the HTML in pages/ or the markup printed by admin.php / login.php — "zmeň vzhľad", "uprav CSS", "pridaj karusel", "na mobile to vyzerá zle", "pridaj animáciu", "pridaj tlačidlo", new section layout, new widget in the edit dialog, embedding anything external (fonts, maps, scripts, iframes). Read it before adding any script, font, icon library or third-party embed, because most of those are blocked here on purpose.
---

# Frontend

No build, no dependencies, no framework — files in `static/` are served as written. That is a feature:
the site must stay editable by opening a file, and nothing may load from third parties.

## Files and who loads them

| File | Loaded by | For |
| --- | --- | --- |
| `static/css/site.css` | live page; placeholders only when „Práve hráme“ has something to show | the dark theatre theme |
| `static/css/placeholder.css` | wip / maintenance pages (after site.css when both) | marionette, message, links |
| `static/css/cms.css` | editors only: live page when logged in, and `admin.php` | pencils, edit dialog, uploader, admin bar |
| `static/css/admin.css` | `login.php`, `admin.php`, `setup.php` | light "paper" theme |
| `static/js/site.js` | live page, placeholders with „Práve hráme“ | menu, scroll-spy, carousels, tabs, overlays, contact form |
| `static/js/cms.js` | editors only (via `cms_client_config()`) | edit dialog, rich-text editor, file picker, chunked upload |

Editor-only styles and scripts stay in `cms.*` — visitors must not download them, and their absence
from visitor HTML is asserted by the smoke test. Always link assets through `asset_version('static/…')`:
it appends `?v=<mtime>`, and `.htaccess` lets browsers cache CSS/JS for 30 days.

## What the CSP forbids (`security_headers()` in bootstrap.php)

`default-src 'self'` with narrow exceptions. In practice:

- **No inline `<script>` and no `onclick=` attributes** — they simply do not run. Data for JavaScript
  travels in `data-*` attributes (`e(json_encode(…))`) or in a JSON block (`<script type="application/json">`,
  see `cms_client_config()`); `application/ld+json` is data too and is fine.
- **Nothing from other hosts**: no Google Fonts, no CDN, no icon fonts, no map embeds, no analytics
  except the self-hosted Umami. Fonts are the system stacks in `--serif` / `--sans`; icons are inline SVG.
- Images: own files, `data:` and `i.ytimg.com` (YouTube poster fallback). Frames: only
  `youtube-nocookie.com`, created by `site.js` after the visitor clicks. Media: own files.
- `style="…"` attributes work (`'unsafe-inline'` for styles) but belong in the stylesheet.

If a request truly needs a new origin, that is a change to `security_headers()` and a privacy
decision ("nothing loads from third parties until a visitor starts a video" is promised in the
README) — raise it with the user instead of quietly widening the policy. See `dg-security`.

## CSS conventions

- **Tokens** are custom properties on `:root` of each stylesheet — site: `--stage`, `--stage-deep`,
  `--curtain`, `--gold`, `--gold-soft`, `--cream`, `--muted`, `--faint`, `--line`, `--line-soft`, `--card`,
  `--serif`, `--sans`, `--radius`, `--topbar-h`; editor: `--cms-*`; admin: `--a-*`. Use them; do not
  introduce new raw colours for things the palette already covers.
- **Naming**: `block__element--modifier` (`play-card__title`, `carousel--photos`), state as `is-*` /
  `has-*` (`is-past`, `is-retired`, `is-hidden-item`, `has-cms`). Hooks for JavaScript are `data-*`
  attributes (`data-carousel`, `data-lightbox`, `data-sheet`), not class names — so styling and behaviour
  can change independently.
- **Layout of the file**: blocks under `/* ---------- názov ---------- */` dividers with Slovak comments
  that explain the *idea* of a layout (see the carousel and „súbor“ blocks). Put new rules in the block
  they belong to, new blocks before `/* ---------- prístupnosť ---------- */`.
- **Mobile first**, `min-width` queries next to the component they affect. Breakpoints are
  per-component on purpose (a card grid and the credits roll break at different widths) — there are
  no global breakpoint variables to reuse; pick what the component needs.
- **`hidden` needs help**: an element that gets `display: flex/grid` ignores the `hidden` attribute, so
  every such component has a `.thing[hidden] { display: none; }` rule. Add one when you add a toggled element.
- Layers: topbar 30, valance 31, skip link 60, admin bar 80, details sheet 90, lightbox / player 100,
  toast 200. The edit dialog is a native `<dialog>` (browser top layer).
- Motion is decoration only and is switched off wholesale under `prefers-reduced-motion`; JavaScript
  checks the same media query (`reducedMotion`) before smooth-scrolling or animating.

## Carousels

Markup comes from two closures in `site.php`: `$carouselOpen($modifier, $ariaLabel, $trackAttrs)` …
`<li class="carousel__item …">` items … `$carouselClose()`. CSS decides how much fits on a page:

```css
.carousel--awards { --cols: 1; --gap: 1rem; }              /* optional: --rows: 2 */
@media (min-width: 640px)  { .carousel--awards { --cols: 2; } }
@media (min-width: 1000px) { .carousel--awards { --cols: 3; } }
```

`site.js` reads `--cols` / `--rows`, computes pages, shows dots (a „3 / 17“ counter above 12 pages) and
arrows, and aligns the last page to the right edge so it is always full. Without JavaScript the track
is a scroll-snap strip — keep it that way; do not hide overflow or depend on `.is-ready`.

## Overlays

All three live in `pages/partials/overlays.php` (+ the sheet at the end of `site.php`) and share one
stack in `site.js` (`openOverlay` / `closeOverlay`): Esc closes the top one, focus is trapped inside
and returns to the opener.

| Trigger attribute | Opens |
| --- | --- |
| `data-lightbox="<url>"` inside a `data-lightbox-group` | photo viewer over the whole group, with „3 / 9“ |
| `data-lightbox-single="<url>"` | one image (poster, history photo) |
| `data-lightbox-set='[{"src","caption"},…]'` | a list carried by the button itself („Galéria“ of a production) |
| `data-player='{"kind": …, "src": …}'` with kind `youtube` or `file` (+ `data-player-group`) | video window; the iframe / `<video>` is created on click |
| `data-sheet="<template id>"` | details sheet filled from a `<template>` next to the item |

Captions come from `data-caption`, titles from `data-title`. Anything new that opens over the page
should join this stack instead of bringing its own Esc / focus handling.

## Works without JavaScript, works for everyone

- Every interactive piece has a no-JS state: carousels scroll, both history tabs show (the tablist is
  `hidden` until `site.js` reveals it), the contact form posts normally and the server redirects back
  with `?cf=…`, the menu is laid out by a `<noscript>` style. Build new pieces the same way: render the
  usable state in PHP, let JavaScript upgrade it.
- Real elements: `<button type="button">` for actions, `<a>` for navigation, headings in order,
  `aria-labelledby` on sections, `aria-current` / `aria-selected` / `aria-expanded` kept in sync by the
  scripts, decorative SVG with `aria-hidden="true" focusable="false"`, state that is only colour gets a
  `.visually-hidden` text (a past date says „odohrané“).
- Focus is visible (`:focus-visible` gold outline) — never remove outlines without a replacement.
- Images: meaningful `alt` from the caption, `alt=""` when the caption is printed next to it,
  `loading="lazy"` except the first poster (`fetchpriority="high"`). Videos without a poster load a
  frame only when scrolled into view (`data-src`).

## JavaScript style

Both files are one IIFE with `'use strict'`, written in **ES5 style — `var`, function expressions, no
arrow functions, no `let` / `const`, no modules, no libraries**. Keep new code in the same dialect so
the files stay uniform. Delegate events from `document` (`e.target.closest('[data-…]')`) rather than
binding per element — content is re-rendered by PHP after every save. Feature-detect optional APIs
(`'IntersectionObserver' in window`) and degrade to the no-JS state.

`cms.js` builds DOM with the `el(tag, attrs, children)` helper and takes every visible string from
`S` = the `js_*` keys of `lang.php` (exported by `t_prefix('js_')`); `s('key', arg)` fills `%s`. Never
hard-code Slovak text in JavaScript. Text from users goes in through `textContent` / the `text`
attribute of `el()`. The rich-text editor is the one place that assigns `innerHTML`, and only after
`rteClean()` (allow-list rebuild) or `rteHighlight()` (escaping) — keep it that way.

## Check your work

`php .claude/skills/dg-dev/scripts/testsite.php serve`, then look at 360 px, ~768 px and ≥1280 px;
tab through the page with the keyboard; try it with JavaScript off and with reduced motion; log in and
check that pencils still sit where they should (`.cms-item` / `.cms-zone` are their positioning
context). Finish with `testsite.php smoke` — it fails on any PHP warning in rendered pages and on
editor markup leaking to visitors.

Nobody at the keyboard to look? Headless Chrome / Edge can screenshot the served copy:
`chrome --headless=new --user-data-dir=<temp dir> --screenshot=<file.png> --window-size=768,1400 http://127.0.0.1:8765/`.
Always pass `--headless=new` **and** a throw-away `--user-data-dir` — without them the command opens
a tab in the developer's own browser session. Headless windows do not go below ~500 px wide, so a
true phone width still needs a person (say so in your report). The details sheet only exists after a
click (it is cloned from a `<template>`): to capture it, save the page HTML, move the template's
content into `#sheet .sheet__content`, drop the `hidden` attribute and screenshot that file with
`<base href="http://127.0.0.1:8765/">` added. Ask the user to look whenever layout really matters.
