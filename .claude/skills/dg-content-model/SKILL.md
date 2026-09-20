---
name: dg-content-model
description: Step-by-step recipes for changing WHAT editors can edit on the Divadielko Galéria site — add, change or remove a field of an inscenácia / „Práve hráme“ item / termín / photo / video / history record / ensemble group, add an editable text or contact setting, add a whole new kind of content (entity), add or remove a page section, or add a new form field type to the in-page editor. Use this whenever a request touches includes/entities.php, or sounds like "pridaj pole", "pridaj možnosť zadať…", "nech sa dá upraviť…", "add a field", "make X editable", "new section", "nový typ obsahu", "show Y for each production" — even when the user only describes what they want to see on the page. The change always spans several files; this skill lists all of them so nothing is left half-wired.
---

# Changing the content model

Read `dg-architecture` first if you have not. The rule of this codebase:

> **new editable thing = `includes/entities.php` + a migration + labels in `includes/lang.php` + rendering**

`entities.php` generates the edit form, drives validation and is the whitelist of columns that may
be written. If a column is not described there it cannot be edited; if a field is described there
without a column, saving fails with a database error. `check.php` verifies both directions.

## Recipe: add a field to an existing entity

Worked example — a free-text "Réžia" on productions.

1. **Migration** — next free number, Slovak comment that says why (details: `dg-migration`):

   ```sql
   -- Réžia pri inscenácii (voľný text) — zobrazí sa v podrobnostiach inscenácie.

   ALTER TABLE productions ADD COLUMN director_sk varchar(120);
   ```
   Text a visitor reads → column `<field>_sk` and `'i18n' => true`. Anything else → plain `<field>`.

2. **`includes/entities.php`** — one line; its position in the array is its position in the form:

   ```php
   'director' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'hint' => 'hint_director'],
   ```

3. **`includes/lang.php`** — `'f_director' => 'Réžia'` (label; or point `'label' =>` at an existing key)
   and the hint key if you named one. Wording rules: `dg-ui-texts`. A missing key does not crash —
   the raw key shows up in the form, which is why `check.php` looks for it.

4. **Render it** where it belongs (`pages/site.php`, `pages/partials/now-playing.php`,
   `program-helpers.php`): `tr($p, 'director')` reads `director_sk`; always through `e()`; wrap optional
   values in `<?php if (tr($p, 'director') !== ''): ?>` so empty fields leave no empty markup.
   - A production is shown in **three** places: the „Práve hráme“ block (`now-playing.php`, also used by
     the wip / maintenance pages), the details sheet (`<template id="sheet-play-…">` in `site.php`) and
     the repertoire card. Decide for each; a request that says "in the details" usually means the first two.
   - Anything `now-playing.php` needs must be defined in `program-helpers.php` — `placeholder.php`
     includes the same two partials, and a helper defined only in `site.php` breaks the placeholders.
   - The `$facts` closure (age · duration · premiere chips) looks like the obvious home for a short
     fact, but it feeds all three places and the card prints its **first two** items — adding to it
     changes the cards. A labelled line of its own is usually better. For this example: a
     `$renderCredits` closure in `program-helpers.php` that prints a `<dl>` row (label from a
     `play_…` key, value through `e()`, nothing at all when empty), called after the description in
     `now-playing.php` and in the details sheet.

5. **Explicit column lists** — check whether the data reaches the template:
   - `runs` fields: add the column to the SELECT in `runs_for_page()` (content.php). Production columns arrive via `p.*`.
   - History: `history_years()` builds its own rows.
   - Archive labels: `cms_archive()` if the new field should appear there.

6. `includes/demo.php` — only if the column is NOT NULL without a default (then demo inserts break).
7. Docs: `README.md` („Úpravy obsahu“) when editors need to know; `dg-architecture/references/data-model.md`.
8. Verify — see the end of this file.

### Field types

| `type` | Column | Notes |
| --- | --- | --- |
| `text` | `varchar(n)` | `max` silently cuts to n characters — keep it equal to the column length |
| `textarea` | `text` | plain text; render with `paragraphs()` (blank line = new paragraph) |
| `richtext` | `text` | tiny editor; stored HTML is sanitized by `rich_html()`; over `max` = validation error (cutting would break tags); render with `rich_html()`, never `e()` |
| `number` | `smallint` / `integer` | whole numbers, `min` / `max` |
| `date` | `date` | |
| `datetime` | `timestamp` | UI offers 24 h time in quarter hours |
| `bool` | `boolean NOT NULL DEFAULT …` | `'default' =>` is the value in the "add" form |
| `url` | `varchar(500)` | `https://` is added when missing; diacritics are percent-encoded |
| `select` | `integer` FK (`'options' => '<entity>'`) or `varchar` (`'choices' => [value => lang key]`) | options come from `cms_options()` — archived rows are not offered |
| `file` | `varchar(255)` | `'accept' => image \| video \| audio \| any`; stores the file name in `assets/` |
| `files` | `jsonb NOT NULL DEFAULT '[]'` | ordered list of file names, `max` = how many |
| `people` | `jsonb NOT NULL DEFAULT '[]'` | `[{name, since}]` — specific to ensemble groups |

Options on any field: `required`, `i18n`, `max`, `min`, `default`, `accept`, `label` (lang key replacing
`f_<field>`), `hint` (lang key shown under the control).

Entity options: `order` (SQL), `sortable` (arrows; needs `sort`), `soft_delete` (bin → archive; needs
`deleted_at`), `new_first` (new rows on top), `move_scope` (SQL condition limiting which rows the arrows consider).

### Rules that span fields

Per-field validation is `cms_value()`. Rules between fields go into `cms_save()` right after the loop,
like the existing ones (video needs `url` or `file`; history needs a production or a title):

```php
if ($entity === 'history' && $data['production_id'] === null && $data['title_sk'] === null) {
    throw new CmsError(t('cms_err_history'), 'title_sk'); // 2nd argument = column the message appears under
}
```

### Saving is a full replace

`cms_save()` writes every field of the entity; a value missing from the request becomes NULL. The form
always sends everything, so this only matters when you call the API yourself (tests, scripts): fetch
`GET api.php?action=item`, change what you need, send the whole `item` back.

### Pre-filled "add" forms

`cms_add('performances', 'cms_add_performance', ['run_id' => $rid])` — the third argument pre-fills
columns in the new-record form. The label key needs a `cms_add_…` entry in `lang.php`.

## Recipe: add an editable text or setting

1. `entities.php` → `'settings'` → existing or new group:
   `'gallery_note' => ['type' => 'textarea', 'i18n' => true, 'max' => 400],`
2. `lang.php`: `f_gallery_note` (label), optionally `default_gallery_note` (text shown until someone
   saves their own), and `sg_<group>` when the group is new (title of the edit window).
3. Render: `setting_tr('gallery_note')` — empty means "show nothing", so guard the markup; headings that
   must never be empty use `setting_label()`. Multi-line → `paragraphs(setting_tr(…))`.
4. Pencil: `<?= cms_settings('<group>') ?>` inside an element with class `cms-zone`.
5. No migration — `settings` is key → value. A setting without `i18n` (number, URL, phone) is read with
   `setting('<key>', $default)`; `contact()` and `link_to()` are that with `config.php` as the default.

## Less common

- **New kind of content (entity)** — table, archive, admin labels, backups, options:
  [references/new-entity.md](references/new-entity.md)
- **New or removed page section**: [references/new-section.md](references/new-section.md)
- **New form field type** — four places must agree: `control()` in `static/js/cms.js` (the widget and
  its `get()`), `cms_value()` (validation → DB value), `cms_row_for_form()` + `cms_blank()` in `cms.php`
  (DB value → form value, and the empty value), plus the type list in the docblock of `entities.php`.
  `cms_form_schema()` forwards only `accept`, `min`, `max` to JavaScript — extend it if the widget needs
  more. Add the column type to `$typeOk` in `.claude/skills/dg-dev/scripts/check.php`.
- **Removing a field**: delete it from `entities.php` and the templates, drop the column in a migration,
  remove its `f_` / `hint_` keys. Order on the server matters when two installs share the database — `dg-deploy`.

## Verify

```
php .claude/skills/dg-dev/scripts/check.php                # labels exist? migration named right? (schema: notes only, see below)
php .claude/skills/dg-dev/scripts/testsite.php create      # applies your migration on an empty database
php .claude/skills/dg-dev/scripts/check.php --root="$(php .claude/skills/dg-dev/scripts/testsite.php path)"
php .claude/skills/dg-dev/scripts/testsite.php smoke
```

`check.php` against the repo root reads the developer's real database, which does **not** have your
new column yet — it reports that as a „pending migration“ note. The run with `--root=<test copy>` is
the strict one that proves migration and `entities.php` agree. Do not run `php setup.php` in the
repository to make the note go away; migrating the developer's own database is their call.

When the change includes a migration, also rehearse it on real content —
`testsite.php create --clone-db`, then the same `check --root` and `smoke` (details and what to look
for: `dg-migration`). On a clone, try the new field on a row you create yourself, or clone
`--with-assets`: existing rows reference media files the copy does not have, and saving them is refused.

Then look at it: `testsite.php serve`, log in as `tester`, open the pencil, save, reload as a visitor
(private window) and confirm hidden/empty states leave no markup behind. If the new behaviour is worth
protecting, add a check to `smoke.php`.
