# Adding a new kind of content (entity)

Example used below: `awards` — "Ocenenia" (year, title, optional image), sortable, with archive.
Go through the list in order; items marked ★ are the ones that fail silently when skipped.

## 1. Table (migration)

```sql
-- Ocenenia — zoznam ocenení súboru (rok, názov, nepovinne obrázok). Poradie sa mení šípkami,
-- kôš presunie záznam do archívu.

CREATE TABLE awards (
    id         serial PRIMARY KEY,
    year       smallint NOT NULL,
    title_sk   varchar(200) NOT NULL,
    image      varchar(255),
    sort       integer NOT NULL DEFAULT 0,        -- because 'sortable' => true
    deleted_at timestamptz,                       -- because 'soft_delete' => true
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now() -- ★ cms_save() always sets it
);
```

A NOT NULL column without a default must be filled by a `required` field (or be bool / files / people),
otherwise "add" fails with a database error. A foreign key to `productions` should say what happens on
purge: `ON DELETE CASCADE` (runs, history) or `ON DELETE SET NULL` (photos).

## 2. `includes/entities.php`

```php
// Ocenenia — čo sme získali a kedy.
'awards' => [
    'order'  => 'sort, id',
    'sortable' => true,
    'soft_delete' => true,
    'fields' => [
        'year'  => ['type' => 'number', 'min' => 1900, 'max' => 2100, 'required' => true],
        'title' => ['type' => 'text', 'i18n' => true, 'required' => true, 'max' => 200],
        'image' => ['type' => 'file', 'accept' => 'image'],
    ],
],
```

The entity key **is** the table name (it is interpolated into SQL — that is safe only because it comes
from this file, so never build it from input).

## 3. `includes/lang.php`

| Key | Used for |
| --- | --- |
| `ent_awards` | title of the edit window (singular: „Ocenenie“) |
| `f_<field>` for every field without `label` | form labels — many exist already (`f_year`, `f_title`, `f_image`) |
| `hint_…` | hints you referenced |
| `cms_add_award` | text of the "add" button (you choose the key, pass it to `cms_add()`) |
| `adm_arch_awards` ★ | heading in admin → Archív (only with `soft_delete`) |
| `bk_t_awards` | table name in admin → Zálohy |

## 4. Archive ★ — `cms_archive()` in `includes/cms.php`

The archive page is not generic: every soft-deleted entity has its own query there, returning
`id`, `label`, `image`, `deleted_at`. Without it the bin still works, but archived rows can never be
restored or purged.

```php
'awards' => db_all("SELECT id, year || ' — ' || title_sk AS label, image, deleted_at FROM awards WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"),
```

## 5. Page

Load in the data block at the top of `pages/site.php` and render inside a section:

```php
$awards = list_entity('awards');            // never returns archived rows; 2nd argument = extra SQL condition
```

```php
<?php foreach ($awards as $a): ?>
  <li class="award cms-item">
    <?= cms_controls('awards', (int) $a['id']) ?>
    <span class="award__year"><?= (int) $a['year'] ?></span>
    <span class="award__title"><?= e(tr($a, 'title')) ?></span>
  </li>
<?php endforeach; ?>
<?= cms_add('awards', 'cms_add_award') ?>
```

- The row element needs class `cms-item` (positioning context + hover outline for editors).
- Side-by-side layouts (carousels) pass `true` as the 4th argument of `cms_controls()` so the arrows
  point left / right; add `'cms-bar--corner'` as the 3rd to pin the bar into the item's corner.
- Think about the empty state twice: what a visitor sees (usually nothing or one friendly sentence
  from `lang.php`) and what an editor sees (the add button must still be reachable).
- If the entity has an `is_public` flag, filter in SQL for visitors:
  `list_entity('awards', $editor ? '' : 'is_public')`, and show `cms_flags(['hidden' => !$a['is_public']])`.

## 6. The rest

- `admin.php` → `$contentTables` in the backups tab: add the table so backup summaries mention it.
- `cms_options()` in `cms.php` — only when another entity selects from this one (`'options' => 'awards'`).
  The generic branch reads a column called `name`; add a `case` like the ones for productions and runs.
- `includes/demo.php` → a few demo rows, so `setup.php --demo` shows the new block.
- `static/css/site.css` → styles (see `dg-frontend`).
- `.claude/skills/dg-dev/scripts/smoke.php` → at least: row renders for a visitor, hidden state does not.
- `README.md` and `dg-architecture/references/data-model.md`.

File usage in admin → Súbory and backups/restore need nothing: both read `entities.php` /
`information_schema` generically.
