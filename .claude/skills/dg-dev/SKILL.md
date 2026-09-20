---
name: dg-dev
description: How to run, check and test the Divadielko Galéria site locally — the static checker (lint, migration numbering, missing translation keys, entities.php vs database), the isolated test copy with its own throw-away database, and the ~200-check end-to-end smoke suite, plus how to log in, call the API and debug from a shell, and the Windows / Git Bash traps (path mangling, non-UTF-8 request bodies). Use whenever you need to start the site, try a change, verify a fix, reproduce a bug, "otestuj to", "spusti to lokálne", "over, že to funguje", run or extend tests, investigate a 500 / 503 / blank page / PHP warning, or before declaring any change to this repository finished. There is no PHPUnit and no CI here — these scripts are the safety net, so use them.
---

# Running, checking, testing

Requirements: PHP 8.x CLI with `pdo_pgsql`, `gd` (AVIF) or `imagick`, `mbstring`, `fileinfo`, `curl`;
a local PostgreSQL; optionally `ffmpeg` (path in `config.local.php`) and `node` (JS syntax check only).
Local settings and credentials are in `includes/config.local.php` — read what you need from it in
PHP, never print or copy its values.

## The three commands

```
php .claude/skills/dg-dev/scripts/check.php               # seconds — run after every change
php .claude/skills/dg-dev/scripts/testsite.php create     # ~45 s — isolated copy + own database
php .claude/skills/dg-dev/scripts/testsite.php smoke      # ~15 s — end-to-end suite against the copy
```

### `check.php` — static checks

`lint` (every PHP file, JS through `node --check`), `migrations` (names, numbering), `lang` (every
key the code or `entities.php` refers to exists; `--unused` lists suspects for deletion), `schema`
(every entity has its table, columns of the right type, `updated_at` / `sort` / `deleted_at`, NOT NULL
columns covered by required fields, soft-deleted entities present in `cms_archive()`).

The schema part reads the database of the install given by `--root` (default: the repository, i.e.
the developer's real database — read-only, `information_schema` only). Right after a migration is
written that database is behind on purpose, so there the checker reports schema differences as
*notes* („pending migration“) and stays green. The strict run — the one that proves migration and
`entities.php` agree — is against the freshly migrated test copy:

```
php .claude/skills/dg-dev/scripts/check.php --root="$(php .claude/skills/dg-dev/scripts/testsite.php path)"
```

`--no-db` skips the schema part. Exit code 1 on any failure.

### `testsite.php` — the isolated copy

**Never test against the developer's real local database** — it holds real content. The copy lives in
the system temp folder, has its own `config.local.php` (same DB credentials, database
`<dbname>_claude_test`, mail to files, analytics off) and an admin **`tester` / `tester-heslo-123`**.

| Command | What it does |
| --- | --- |
| `create` | fresh copy, empty database, all migrations, demo content. `--clone-db` clones the real database instead and applies only pending migrations (how to rehearse a migration — needs nobody else connected to the real DB); `--with-assets` copies `assets/` too; `--no-demo` |
| `sync` | copy changed working-tree files into the copy (also removes files you deleted) and apply migrations that are new to the copy's database |
| `serve` | sync (as above), then `php -S 127.0.0.1:8765 router.php` with errors displayed (`--port=`). It blocks: run it as a background command |
| `smoke` | sync (as above), start a server on a free port, run the suite, stop the server. `--only=api,upload`, `--verbose`, `--no-sync` |
| `status` / `path` / `destroy` | where it is / just the folder / drop the database and delete the folder |

A `--clone-db` copy references the real media by name but has an empty `assets/` unless you add
`--with-assets`: pages show broken images, and **saving an existing row that has a file field fails
with „Súbor sa nenašiel“** (the file must exist to be accepted). Use `--with-assets` when you intend to
edit cloned content; the smoke suite does not need it (it brings its own fixtures).

It refuses to work when `config.local.php` points at a non-local database server, and the only
database it ever creates or drops ends in `_claude_test`.

Keep the copy while a task is in progress — `create` is the slow part, and `sync` / `smoke` keep it
current, new migrations included. Run `create` again only for a clean slate or to switch between
empty and cloned content. Whoever owns the task runs `destroy` at the very end, so no stray database
is left on the developer's PostgreSQL; a sub-agent asked to test leaves the copy for its caller.

### `smoke.php` — what the suite covers

Sections: `units` (slug, sanitizer, URL/date validation, YouTube detection, mail names) · `public`
(503 + Retry-After, no visitor cookie, CSP, closed folders, 401/404s) · `login` (CSRF, wrong password,
open-redirect guard, cookie flags) · `editor` (live page + every admin tab render without PHP
warnings) · `roles` (every admin-only action is 403 for an editor) · `api` (whitelist, CSRF, mass
assignment, validation, rich-text sanitising, output escaping) · `upload` (chunk protocol, AVIF,
disguised PHP, size lies) · `order` (new-first, arrows, archive → restore → purge) · `visibility`
(hidden production / run absent from visitor HTML and JSON-LD, no editor markup, old URLs) ·
`placeholder` („Práve hráme“ on wip / maintenance pages) · `contact` (token, honeypot, time trap,
header injection, rate limit, no-JS fallback) · `backup` (create → change → restore round trip) ·
`throttle` (brute force).

It creates its own fixtures (everything is marked `SMOKE`), so it works on demo and on cloned real
content, and it restores the database and removes its files when done. The server runs with
`display_errors=1`, and **any PHP warning or notice in a rendered page fails the run** — that is
usually how a typo in a template gets caught.

**Extend it when you add behaviour worth protecting** — a new admin action goes into the `roles`
list, a new visibility rule gets a fixture and an assertion in `visibility`, a new validation rule a
line in `api` or `units`. Pattern: `$t->ok($condition, 'sentence that states the rule', $detailOnFailure)`,
`$t->same($expected, $actual, …)`, `$t->status(403, $response, …)`, `$t->clean($response, 'page name')`.
After adding a check, prove it can fail: break the behaviour in the *copy* (`testsite.php path`),
run `smoke --no-sync`, see red, then `sync`.

Limits to keep in mind: it exercises `router.php`, not Apache / `.htaccess`; PHP 8.2 locally vs 8.5
on the hosting; no browser — CSS and JavaScript behaviour still need eyes (`dg-frontend`).

## Working with the developer's own install

`php -S localhost:8000 router.php` from the repository root serves the real local content — fine for
*looking*, not for experiments. `php setup.php` applies pending migrations there (with an automatic
backup); that is the developer's call, not something to do as a side effect of testing.
Other switches: `--admin=… --password=…` (create / reset an admin), `--demo`, `--backup`, `--create-db`.

## Poking at it from a shell

- **One-off questions** are easiest in PHP with the app loaded — run from the install you mean:
  `cd "$(php .claude/skills/dg-dev/scripts/testsite.php path)" && php -r 'require "includes/bootstrap.php"; print_r(db_all("SELECT id, title_sk FROM productions"));'`
- **Anything longer than one line: write a `.php` file with the file-writing tool** and run it with
  the copy as working directory (`require getcwd() . '/includes/bootstrap.php';`). Put it in the
  session scratchpad or the system temp folder — not in the repository, and not inside the copy
  (`create` wipes it). `php -r` and shell heredocs mangle `$`, backslashes, quotes and diacritics in
  ways that look like PHP parse errors or application bugs; do not fight them.
- **HTTP by hand**: log in by fetching `/login.php`, reading the hidden `csrf` field, posting
  `csrf`, `username`, `password` with a cookie jar. API calls then need `X-CSRF-Token` — the token is
  in the page's `<script id="cms-config">` JSON (`"csrf":"…"`). `smoke.php` shows all of it in ~20 lines (`SmokeHttp`, `$loginAs`, `$api`).
- **Git Bash on Windows mangles arguments**: a value starting with `/` (`next=/admin.php`) is rewritten
  into `C:/Program Files/Git/admin.php`; set `MSYS_NO_PATHCONV=1`. `curl -d '{"title_sk":"Kráľ"}'` sends
  the bytes in the console code page, not UTF-8 — the API then cannot parse the JSON and answers
  „Neznáma požiadavka“. Put bodies in a UTF-8 file (`--data-binary @body.json`) or, better, use PHP.
  Both traps produce errors that look like application bugs.
- `cd` in a shell tool call persists; prefer absolute paths or `git -C`.

## When something is broken

| Symptom | Look at |
| --- | --- |
| Visitors see „Máme krátku prestávku“ (503) though mode is live | `site.php` threw — `index.php` catches it and shows the maintenance page. The reason is in the PHP error log as `[site] …` (test copy: `storage/php-error.log`). Typical cause: code deployed, migration not yet run |
| 503 „Opona sa čoskoro dvíha“ | mode is `wip`, or `db.user` is empty in the config |
| One domain shows a placeholder, the other (same database) does not | `'mode' => '…'` is forced in that install's `includes/config.local.php` — it overrides the shared admin setting (`dg-deploy`) |
| Maintenance page right after a fresh install | the database has no `migrations` table yet → run `setup.php` |
| A raw key like `f_director` on the page | missing entry in `lang.php` → `check.php` |
| Save fails with „Chyba databázy“ | field in `entities.php` without a column, or a NOT NULL column nobody fills → `check.php` schema section; details in the error log as `[api] …` |
| Save says „Neznáma požiadavka“ | entity / group not in `entities.php`, or the request body was not valid UTF-8 JSON |
| „Platnosť formulára vypršala“ (419) | session expired or the CSRF header is missing |
| Upload stops at 100 % with an error but the file appears later | conversion outlived a proxy timeout (Cloudflare ~100 s); the server finishes anyway (`ignore_user_abort`) |
| Images stay JPG/PNG | the server cannot write AVIF — admin → Súbory shows „AVIF nie“ |
| admin → Súbory says „ffmpeg nie“ on a server that has ffmpeg | the program cannot be *started*: the error log line `[media] ffmpeg sa nepodarilo spustiť … Pokusy: …` carries PHP's reason (typically `open_basedir`). `dg-deploy/scripts/ffmpeg-check.php` shows which way of starting it works on that server |
| admin → Súbory says „ffmpeg áno, ale nevie kódovať AV1“; `.mov` / `.mkv` uploads are refused, `.mp4` goes to the web unconverted | the ffmpeg build has neither `libsvtav1` nor `libaom-av1` — log line `[media] tento ffmpeg nemá kodér AV1`. Point `'ffmpeg'` in the config at a build that has one, or put it into `tools/` |
| A visitor reports a video that shows only „Toto video sa na vašom zariadení nedá prehrať“ | expected on devices without an AV1 hardware decoder in Safari (iPhones before 15 Pro, Macs before M3): videos are AV1-only by the owner's decision (`dg-architecture`, trap 7) |
| Text change in `lang.php` has no effect | an editor saved their own text; it lives in `settings` (`dg-ui-texts`) |
