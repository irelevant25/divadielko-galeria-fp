# Divadielko Galéria — web

Website of a puppet theatre (Nové Mesto nad Váhom). **Plain PHP 8 + PostgreSQL**: no framework,
no Composer, no npm, no build step. One public page (`pages/site.php`) whose content logged-in
editors change in place ("ceruzky" — pencils), a small admin (`admin.php`), a JSON API (`api.php`).
Hosting: Websupport (Apache, PHP 8.5) behind Cloudflare. `README.md` is the user-facing manual (Slovak).

## Which skill for what

This is not an Angular project — ignore the global `fe*`, `create-*`, `add-route`, `fix-bugs`,
`analyze`, `refactor`, `security-check` and `ab-fe-team-angular:*` skills here. Use these instead:

| Task | Skill |
| --- | --- |
| Understand how something works, where it lives, what a Slovak UI term maps to | `dg-architecture` |
| Add / change an editable field, entity, settings text, section, field type | `dg-content-model` |
| Any change to the database schema or data | `dg-migration` |
| CSS, JS, templates, carousels, overlays, accessibility | `dg-frontend` |
| Any text a visitor or editor reads (`includes/lang.php`, hints, errors) | `dg-ui-texts` |
| Touching login, sessions, API, uploads, mail, headers, `.htaccess`; security review | `dg-security` |
| Run locally, test a change, smoke tests, static checks | `dg-dev` |
| Upload to the hosting, run migrations there | `dg-deploy` |

Agents: `dg-reviewer` (read-only review of a change against the project's invariants) and
`dg-tester` (runs the checks and the smoke suite in the isolated test copy and reports).

## Rules that apply to every change

- **Language:** UI texts, code comments and docblocks are Slovak; commit messages are English,
  imperative ("Add …", "Fix …"). Match the file you are editing.
- **Git:** commit directly on `main`, no feature branches. Push only when asked.
- **Everything editable goes through `includes/entities.php`.** It drives the edit form, validation
  and saving; columns not described there are never written. New field = entities.php + migration
  + `lang.php` label + rendering.
- **Never edit a migration that is committed on `main`** — it may already be applied on the server,
  and the runner only looks at file names. Add the next number instead.
- **Escape on output:** `e()` for text, `paragraphs()` for multi-line plain text, `rich_html()` for
  editor HTML, `media_url()` for files. SQL values always as `?` parameters.
- **No external resources, no inline scripts** — the CSP (`security_headers()`) blocks them, and
  the privacy promise is that nothing loads from third parties until a visitor starts a video.
- **Visitors get no cookie.** A session starts only on login. Do not add anything that sets one.
- **Visitor vs. editor:** whatever is hidden (`is_public = false`, archived, empty) must stay out of
  the HTML sent to visitors — not merely be hidden with CSS.
- **Secrets** live in `includes/config.local.php` (git-ignored). Never print, copy into the repo or
  commit its values. The origin server's address stays out of the repository too (it is public on GitHub).
- **Do not test against the developer's real local database.** Use the isolated copy:

```
php .claude/skills/dg-dev/scripts/check.php              # lint, migrations, lang keys, entities ↔ schema
php .claude/skills/dg-dev/scripts/testsite.php create    # own folder + own database (…_claude_test)
php .claude/skills/dg-dev/scripts/testsite.php smoke     # ~270 end-to-end checks in under a minute
```

Run `check.php` after every change and the smoke suite before calling work done. When a change
adds behaviour worth protecting, add a check for it to `smoke.php`. (With a new migration, the
repo-root `check.php` only *notes* schema differences — the developer's database is behind on
purpose; the strict run is `check.php --root="$(php …/testsite.php path)"`. See `dg-dev`.)

## Map

```
index.php            entry: static-file 404 → mode (wip / maintenance / live) → placeholder or site
pages/site.php       the live one-page site; sections rendered into buffers, printed in saved order
pages/placeholder.php  wip + maintenance pages (HTTP 503 + Retry-After), reuse „Práve hráme"
pages/partials/      now-playing, program helpers (closures), overlays, admin bar, icons
api.php              JSON API for cms.js (login + CSRF) and the public contact form
admin.php            messages, files, archive, sections & menu, users, backups, mode, account
login.php setup.php  login/logout; install + migrations (CLI, or browser with ?key=)
cron.php             hosting scheduler entry (?key=): works on unfinished video conversions
router.php           dev only — stands in for .htaccess under `php -S`
includes/bootstrap.php  config(), e(), paragraphs(), rich_html(), headers, sign()
includes/content.php    settings, sections, site_mode(), now_playing(), history_years(), cms_* pencils
includes/entities.php   ← what is editable (entities + settings groups)
includes/cms.php        validation + save/delete/move/archive behind the API
includes/media.php      chunked upload, AVIF / Opus conversion, running ffmpeg, file listing
includes/video.php      video → WebM (AV1 + Opus) as a resumable job: exact segments, join, poster
includes/backup.php     JSON backups, compatibility check, restore
includes/auth.php mail.php db.php i18n.php lang.php demo.php
includes/migrations/    NNN_name.sql, applied in order by db_migrate()
static/css/  site.css · placeholder.css · cms.css (editors) · admin.css      static/js/  site.js · cms.js
assets/ (public, converted)   assets_original/ (private originals)   storage/ (uploads tmp, mail, backups)
```
