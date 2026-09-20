---
name: dg-architecture
description: How the Divadielko Galéria website is built — request flow, the three site modes (wip / maintenance / live), settings and default texts, sections and menu, visibility rules (hidden, retired, archived), the in-page CMS pipeline, media, backups, code conventions and a Slovak↔code glossary. Read this BEFORE any non-trivial change in this repository and whenever the question is "how does X work", "where is X", "why does the site show 503 / the placeholder", "ako funguje…", "kde je…", "prečo sa nezobrazuje…", or when a Slovak term from the UI or README (inscenácia, Práve hráme, termín, súbor, ceruzka, kôš, archív, ostrá stránka) has to be mapped to tables and functions. The other dg-* skills assume this one.
---

# Divadielko Galéria — architecture

Plain PHP 8 + PostgreSQL, procedural, no dependencies, no build. About 12 000 lines; reading the
file you are about to change is always affordable — do it, the Slovak docblocks explain intent.

Exact tables and columns: [references/data-model.md](references/data-model.md).

## Mental model

One server-rendered page. A visitor gets static-looking HTML and no cookie. A logged-in editor gets
the same page plus pencils: PHP prints small buttons (`data-cms-action`), `static/js/cms.js` opens a
`<dialog>` whose form is generated from a schema that `api.php` builds from `includes/entities.php`,
saves through the API and reloads the page (scroll position is restored). There is no client-side
rendering of content — after every save PHP renders everything again.

`entities.php` is the single description of what is editable. The form, the validation
(`cms_value()`), the SQL column list (`cms_save()`) and the "where is this file used" map all read it.

## Request flow (`index.php`)

1. `includes/bootstrap.php` — `config()`, then `db.php`, `i18n.php`, `auth.php`, `content.php`. The
   database connection is lazy: nothing connects until the first query.
2. A path that looks like a static file (`.png`, `.css`, `.json` …) → plain `404`, before any DB work.
3. `site_mode()`: `config('mode')` if set (forces it, the admin switch is then disabled) → if the DB
   is unreachable: `wip` when `db.user` is empty (not installed yet), else `maintenance` → otherwise
   `settings.site_mode` (default `wip`). `db_available()` means "connects AND has a `migrations`
   table", so a brand-new database shows the maintenance page until `setup.php` has run.
4. Not live and not logged in → `pages/placeholder.php` with `$variant` — HTTP **503 + Retry-After**
   on every path. A logged-in user always gets the live page; `/?preview=wip|maintenance` shows them
   the public view.
5. Live: any path other than `/` → `301 /` (old URLs of the previous site).
6. `pages/site.php` runs inside `ob_start()` + `try`. Any `Throwable` → logged as `[site] …` and the
   visitor gets the maintenance placeholder instead of half a page.

Other entry points: `api.php` (JSON; everything except `action=contact` needs login, POST needs the
`X-CSRF-Token` header), `admin.php` (POST `do=…` → redirect back with a flash message; admin-only
actions start with `$admin || forbid();`), `login.php`, `setup.php` (CLI, or browser with `?key=`).

## Visitor vs. editor

`current_user()` starts a session only when the `dg_session` cookie is already there, so anonymous
requests never touch sessions. `cms_on()` is "logged in and pencils allowed"; `cms_on(false)` switches
pencils off for the rest of the request (placeholders do this). `$editor = cms_on()` in templates.

All `cms_*` render helpers in `content.php` return `''` for visitors, so templates call them
unconditionally: `cms_controls($entity, $id, $class, $horizontal)` (pencil, arrows, bin),
`cms_add($entity, $labelKey, $preset)`, `cms_settings($group)`, `cms_edit_link()`, `cms_flags([...])`.
The element that carries controls needs class `cms-item` (a row) or `cms-zone` (a text group).

### Who sees what

| Content | Visitor | Editor |
| --- | --- | --- |
| `productions.is_public = false` | nowhere — not in Repertoár, not in História | everywhere, flag „Skryté“ |
| `productions.is_retired` | shown greyed | + flag „Už nehráme“ |
| `runs.is_public = false` | no | yes, flagged |
| public run of a hidden production | **yes** — publishing the run is the explicit decision | yes |
| `deleted_at` set (archive) | no | only admin → Archív |
| performance older than start + 2 h (`PERFORMANCE_PAST_AFTER`) | stays, greyed | same |
| História rows built from past performances | all runs count, even hidden and archived ones; hidden or archived *productions* do not | hidden ones flagged |
| ensemble group without people | no | yes |
| „O nás“ with empty text / empty Repertoár | section and its menu item disappear | shown |
| tickets note | only when non-empty and an upcoming date exists | always |

"Hidden" must mean *absent from the HTML*, not styled away — filter in SQL (`$withHidden` parameters
of `runs_for_page()`, `now_playing()`, `history_years()`), the way `list_entity('productions', $editor ? '' : 'is_public')` does.

## Settings and default texts

Table `settings` is key → value. Four readers, three behaviours:

- `setting($key, $default)` — stored non-empty value, else `$default`.
- `setting_tr($key)` — reads `<key>_sk`. **Row exists → its value, even when empty (empty = "do not
  show"). No row yet → `default_<key>` from `lang.php`.** This is how texts can be deliberately blanked.
- `setting_label($key)` — like `setting_tr` but never empty (headings, menu labels fall back to the default).
- `contact($key)`, `link_to($key)`, `founded()` — setting, else `config.php`. Side effect: a contact or
  social link cannot be emptied from the UI (empty means "use the config default").

`%d` inside a default text is replaced by the founding year. Editable groups are declared under
`'settings'` in `entities.php`; the pencil is `cms_settings('<group>')`.

## Sections and menu

`SECTIONS` (content.php) lists section keys = URL anchors: `domov, onas, galeria, subor, repertoar,
historia, kontakt`. `site.php` renders each section into `$html[$key]` with output buffering and then
prints them in `section_order()` (saved in `settings.section_order`; Domov is always first; a key
missing from the saved order is inserted after its default neighbour, so new sections need no data
migration). The same order feeds the top menu and the footer. `$visible` decides which exist for the visitor.

## Saving (api.php → cms.php)

`GET item|blank|settings` → `{title, item, schema}`; `POST save` `{entity, id, values}`; `POST delete`,
`move`, `settings_save`, `upload`. `cms_save()` walks **every** field of the entity and writes it —
a value missing from the request becomes `NULL`. It is a full replace, never a patch; callers send the
whole form. Validation errors are `CmsError($message, $column)` → HTTP 422 `{error, field}` and the
form shows the message under that field. Bin = `deleted_at = now()` for `soft_delete` entities, a real
`DELETE` for `performances`. Arrows renumber `sort` 1…n among non-archived rows.

## Media

Chunked upload → `storage/uploads/<user>-<id>.part` → `media_ingest()` checks MIME and image header →
original to `assets_original/<slug>.<ext>` (never served) → web version in `assets/`: images → AVIF
(max edge 2400), video → MP4 H.264 + Opus + `<slug>.avif` poster, audio → Opus. If the server cannot
convert, the original is copied when browsers can show it. The database stores only the **file name**
in `assets/`; print it with `media_url()`. `media_usage_map()` finds usages through the `file` /
`files` fields of `entities.php`.

## Backups

`includes/backup.php`: whole database as JSON in `storage/backups/`, created by hand (admin → Zálohy,
`php setup.php --backup`), automatically before pending migrations, and before every restore. A backup
remembers a schema fingerprint; `backup_compat()` grades it `same` / `ok` / `blocked`. Restore runs in
one transaction, skips `migrations` and `login_attempts` always and `users` unless asked. Media files
are not in backups.

## Conventions

- `declare(strict_types=1);` and a Slovak file docblock in every PHP file. Comments say *why*.
- Procedural, functions prefixed by module (`cms_`, `media_`, `backup_`, `rich_html_`, `db_`, `mail_`,
  `contact_`). The only class is `CmsError`. No namespaces, no autoloading — `require` what you need.
- Templates use alternative syntax (`<?php if (…): ?>`) and `<?= e(…) ?>`. Reusable fragments are
  `static function` closures assigned to variables (`$renderDate`, `$carouselOpen`), because templates
  are `require`d more than once per request in some paths — named functions would be redeclared.
- DB: `db_all / db_one / db_value / db_exec` with `?` parameters, `RETURNING id`, booleans sent as
  `'t'` / `'f'`. Table and column names are interpolated only when they come from `entities.php`.
- Texts through `t('key', …args)`; content columns through `tr($row, 'title')` (reads `title_sk`).
- Log as `error_log('[module] message')`; show users a translated message, never the exception.
- Dates: `performances.starts_at` is a local `timestamp`; PHP and the PG session both run in
  Europe/Bratislava. `format_date()` / `format_time()` work without `intl`.
- PHP 8.2 locally, 8.5 in production: avoid anything deprecated after 8.2 (`imagedestroy`,
  `curl_close`, implicit nullable parameters, non-canonical casts).

## Glossary

| Slovak (UI / README) | In code |
| --- | --- |
| inscenácia, repertoár | `productions` |
| „Práve hráme“ (položka) | `runs` — a production currently played, with own poster, price, venue |
| termín (predstavenia) | `performances` (belongs to a run) |
| súbor (ľudia), skupina | `ensemble_groups`, people in the `people` jsonb — **but „súbor“ also means a file** (admin → Súbory = media) |
| história, záznam | `history` + rows derived from past performances (`history_years()`) |
| ceruzka / šípky / kôš | edit / move / delete buttons (`cms_controls()`) |
| archív | rows with `deleted_at`; admin → Archív restores or purges |
| zobraziť verejnosti / skryté | `is_public` |
| už nehráme | `is_retired` |
| ostrá stránka / pripravujeme / údržba | mode `live` / `wip` / `maintenance` |
| redaktor / administrátor | role `editor` / `admin` |
| voľné texty, nastavenia | `settings` table, groups in `entities.php` |
| plagát (banner) / obrázok | `runs.poster` / `productions.image` |
| ukážka | trailer (`productions.trailer` file or `trailer_url` YouTube) |

## Things that bite

1. `runs_for_page()` selects `p.*` plus an **explicit list** of run columns — a new `runs` column is
   invisible to templates until it is added there. `history_years()` and `cms_archive()` are explicit too.
2. `cms_archive()` names every soft-deleted entity by hand. Forget a new one and its archived rows can
   never be restored (`check.php` catches this).
3. `cms_options()` has special cases for `productions` and `runs`; its generic branch expects a `name` column.
4. Every entity table needs `updated_at` (`cms_save()` sets it), `sort` if sortable, `deleted_at` if soft-deleted.
5. A NOT NULL column without a default makes older backups un-restorable (`blocked`) — see `dg-migration`.
6. Main site and test subdomain share **one database** but not `assets/` — see `dg-deploy`.
7. Where ffmpeg exists, videos become MP4 with **Opus** audio by design — if someone reports silent
   video on an older iPhone, suspect this first (`media_video_to_mp4()`). The production hosting has
   **no ffmpeg**, so there videos are served exactly as uploaded (`dg-deploy`).
8. `history` has a `sort` column but the entity is not sortable; order is `year, sort, id`.
9. Login throttling and the contact rate limit key on `REMOTE_ADDR`. Behind Cloudflare that may be an
   edge address shared by many visitors unless the host restores the real IP.
