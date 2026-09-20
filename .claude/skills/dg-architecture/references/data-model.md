# Data model (after migration 014)

PostgreSQL. This is the schema that migrations 001–014 produce; when a migration is added, update
this file in the same commit. To see the live truth: `php .claude/skills/dg-dev/scripts/check.php`
compares `entities.php` with `information_schema`.

## Conventions

- Text a visitor reads lives in `<name>_sk` columns (the site is Slovak-only since 014; the `_sk`
  suffix stayed so `tr($row, 'name')` and `'i18n' => true` keep working). Non-text columns have no suffix.
- `id serial PRIMARY KEY`, `created_at` / `updated_at timestamptz NOT NULL DEFAULT now()`.
  `cms_save()` always writes `updated_at = now()`, so every entity table must have it.
- `sort integer NOT NULL DEFAULT 0` on sortable entities. New rows get `max + 1`, or `min − 1` when the
  entity has `'new_first' => true` (photos, videos). Arrows renumber 1…n.
- `deleted_at timestamptz` = in the archive (soft delete). NULL = live.
- `is_public boolean NOT NULL DEFAULT false` — new content starts hidden.
- Lists of things without their own identity are `jsonb NOT NULL DEFAULT '[]'` (`productions.images`,
  `ensemble_groups.people`).
- Media columns (`varchar(255)`) hold a file name inside `assets/`, never a path or URL.
- `performances.starts_at` is `timestamp` **without** time zone = local time in Europe/Bratislava.

## Content tables (all described in `includes/entities.php`)

### productions — Repertoár
`title_sk` varchar(200) NOT NULL · `subtitle_sk` varchar(200) · `description_sk` text · `image` ·
`age_sk` varchar(60) · `duration_sk` varchar(60) (free text: „od 4 rokov“, „50 minút“) · `premiere` date ·
`trailer` (uploaded video) · `trailer_url` varchar(500) (YouTube) · `images` jsonb (file names) ·
`is_public` · `is_retired` · `sort` · `deleted_at`

### runs — „Práve hráme“
`production_id` integer NOT NULL → productions **ON DELETE CASCADE** · `poster` · `price_sk` varchar(60) ·
`venue_sk` varchar(200) · `venue_url` varchar(500) · `venue_map_url` varchar(500) · `is_public` · `sort` · `deleted_at`

Templates receive a run as *production columns + `run_id`, `run_public`, `poster`, `price_sk`, `venue_sk`,
`venue_url`, `venue_map_url`* — see `runs_for_page()`; that column list is explicit.

### performances — Termíny
`run_id` integer NOT NULL → runs **ON DELETE CASCADE** · `starts_at` timestamp NOT NULL · `venue_sk` (only
when this date plays somewhere else than the run) · `note_sk`. No soft delete, no sort (ordered by time).
Index `(run_id, starts_at)`.

### ensemble_groups — Súbor
`name_sk` varchar(120) NOT NULL · `people` jsonb `[{"name": "…", "since": 2006 | null}, …]` · `sort` · `deleted_at`

### photos
`image` NOT NULL · `caption_sk` varchar(300) · `production_id` → productions **ON DELETE SET NULL** · `taken_on` date · `sort` · `deleted_at`

### videos
`title_sk` · `url` varchar(500) (YouTube / Instagram / any link) · `file` (uploaded) · `poster` · `sort` · `deleted_at`.
One of `url` / `file` is required (checked in `cms_save()`). A YouTube poster is downloaded into `assets/` on save.

### history — História
`year` smallint NOT NULL · `production_id` → productions **ON DELETE CASCADE** (nullable) · `title_sk`
(nullable) · `place_sk` varchar(200) · `text_sk` text · `image` · `sort` · `deleted_at`.
CHECK `history_production_or_title`: a row is either a production or an event with its own title.
The History section = these rows **plus** rows derived from past performances (`history_years()`);
a manual row with the same year and production merges into the derived one.

## Other tables

| Table | Purpose | Notes |
| --- | --- | --- |
| `settings` | `key` varchar(80) PK, `value` text, `updated_at` | free texts (`<key>_sk`), contact, links, `site_mode`, `section_order`, `founded` |
| `messages` | contact form | `status` new/read/archived/spam, `mailed`, `ip_hash` char(64) — the IP itself is never stored |
| `users` | `username` UNIQUE, `email`, `password_hash`, `role` admin/editor, `active`, `last_login_at` | no registration; created in admin or by `setup.php` |
| `login_attempts` | failed logins per `ip_hash` | throttling: 8 failures / 15 min; never in a restore |
| `migrations` | `name` PK, `applied_at` | bookkeeping of `db_migrate()`; never in a restore |

## Deleting — what cascades

Purging a production (admin → Archív → Zmazať natrvalo) deletes its runs, their performances and its
history rows; photos keep existing with `production_id = NULL`. Archiving a production only hides it:
queries join `productions p` with `p.deleted_at IS NULL`, so its runs vanish from the page without
being touched and come back when it is restored.

## Settings keys in use

`site_mode`, `section_order`, `founded` · texts: `hero_badge_sk`, `hero_title_sk`, `tagline_sk`,
`tickets_note_sk`, `program_empty_sk`, `about_title_sk`, `about_text_sk` (sanitized HTML),
`<gallery|ensemble|repertoire|history|contact>_title_sk`, `…_intro_sk`, `nav_<section>_sk` ·
contact: `phone_display`, `phone_tel`, `email`, `street`, `city`, `map_url` · links: `facebook`,
`instagram`, `youtube`, `msks`. The authoritative list is the `'settings'` part of `entities.php`.
