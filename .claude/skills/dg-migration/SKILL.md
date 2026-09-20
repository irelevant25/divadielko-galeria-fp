---
name: dg-migration
description: Write and rehearse PostgreSQL migrations for the Divadielko Galéria site (includes/migrations/NNN_name.sql, applied by db_migrate() through setup.php). Use for ANY change to the database — new column or table, renamed or dropped column, constraint, index, data fix or data move, cleaning up settings — and whenever a change to includes/entities.php needs a column. Also use when a migration failed on the server, when asked how migrations or the automatic backup before them work, or "prečo sa databáza neaktualizovala". Covers the rules that are specific to this project — applied migrations are immutable, NOT NULL columns must have defaults or old backups stop being restorable, the live site and the test subdomain share one database, and how to rehearse against a clone of real content.
---

# Migrations

## How they run

`db_migrate()` (includes/db.php) applies every `includes/migrations/*.sql` that is not yet in the
`migrations` table, in file-name order, **each inside its own transaction** (the file + the
bookkeeping row commit together; an error rolls the whole file back and stops). It is triggered by
`php setup.php` locally and by opening `https://…/setup.php?key=…` on the hosting.

Before applying anything to a database that already has migrations, it writes a JSON backup to
`storage/backups/` (`backup-…-pred-NNN.json`). **If the backup fails, nothing is migrated** — so a
non-writable `storage/` on the server blocks deployments. The error says so.

## Rules

1. **Never edit a file that is committed on `main`.** Assume it has been applied somewhere (the
   server, the developer's database): the runner only looks at file names, so an edit would silently
   never run there while fresh installs get it — two different schemas. Fix forward with a new file.
   A migration you wrote in this task and have not committed yet is still yours to change.
2. **Name:** `NNN_snake_case.sql`, next free number, three digits. `check.php` complains about gaps and duplicates.
3. **Start with a Slovak comment that explains why**, not what — look at any existing file. Future
   readers see the SQL; they cannot see the conversation that led to it. When data is moved or
   dropped, say what happens to it („sú v zálohe, ktorá sa urobí automaticky pred touto migráciou“).
4. **No transaction control and nothing that refuses to run in one**: no `BEGIN` / `COMMIT`, no
   `CREATE INDEX CONCURRENTLY`, no `VACUUM`. Several statements and `DO $$ … $$` blocks are fine.
5. **A new NOT NULL column gets a DEFAULT.** Two reasons: existing rows need a value, and restore.
   `backup_compat()` marks a backup as `blocked` (no restore button) when it lacks a column that is
   NOT NULL *without* a default and the table has rows. A default keeps every older backup restorable
   („Zlučiteľná“ — missing values are filled in). If new rows should start differently from old ones,
   use the two-step idiom from 005:

   ```sql
   ALTER TABLE productions ADD COLUMN is_public boolean NOT NULL DEFAULT true;  -- existujúce ostanú verejné …
   ALTER TABLE productions ALTER COLUMN is_public SET DEFAULT false;            -- … nové začínajú skryté
   ```
6. **Columns an entity needs:** `updated_at timestamptz NOT NULL DEFAULT now()` always; `sort integer
   NOT NULL DEFAULT 0` if sortable; `deleted_at timestamptz` if soft-deleted; visitor-facing text in
   `<name>_sk`; lists as `jsonb NOT NULL DEFAULT '[]'::jsonb`. Media columns are `varchar(255)` file names.
7. **Foreign keys say what a purge does**: `ON DELETE CASCADE` when the child is meaningless without
   the parent (runs, performances, history rows of a production), `ON DELETE SET NULL` when it survives (photos).
8. **Be careful with text functions.** The hosting database may use the "C" locale, where `lower()` /
   `upper()` leave `Š`, `Č`, `Ž` alone. Migration 010 uses `translate()` with explicit alphabets for
   that reason. Escape `_` in `LIKE` patterns (`'%\_en'`), it is a wildcard.
9. **Idempotent where it is cheap** (`IF NOT EXISTS`, `IF EXISTS`, `ON CONFLICT DO NOTHING`) — the
   transaction makes a rerun safe anyway, but databases drift (early installs had different 001 files;
   that is what 002–004 repair).
10. Moving data between shapes: copy → verify with a constraint → drop the old shape, all in one
    file, so a failure leaves the old shape intact. 005, 010 and 011 are the models to copy from.

## One database, two installs

The live site and `test.…` run **different code folders against the same database**. A migration
applied from one is instantly in effect for the other:

- *Additive* changes (new nullable column, new table) are safe in any order — old code ignores them.
- *Destructive* changes (drop / rename a column or table, tighten a constraint) break whichever install
  still runs old code that touches it. Visitors then get the maintenance page (index.php catches the
  error) until that install is updated. So: upload the new code to **both** folders first, then open
  `setup.php?key=…` **once**. If the old code must keep running for a while, split the work into two
  releases — stop using the column now, drop it later. The order of operations is in `dg-deploy`.

## Rehearse it

```
php .claude/skills/dg-dev/scripts/check.php                       # file name, numbering
php .claude/skills/dg-dev/scripts/testsite.php create             # empty database → all migrations from 001
php .claude/skills/dg-dev/scripts/testsite.php create --clone-db  # clone of the developer's real content → only the new one
php .claude/skills/dg-dev/scripts/check.php --root="$(php .claude/skills/dg-dev/scripts/testsite.php path)"
php .claude/skills/dg-dev/scripts/testsite.php smoke
```

- The **empty-database** run proves a fresh install still works.
- The **clone** run is the one that matters for production: it applies only the pending file to real
  rows (NULLs, odd legacy values, real volumes), and it exercises the automatic backup. PostgreSQL can
  clone a database only while nothing else is connected to it — stop the dev server and close pgAdmin.
  The clone has no media files unless you add `--with-assets`: images are broken, and saving an
  existing row that has a file field is refused („Súbor sa nenašiel“) — probe with a row you create.
- After the clone run, open admin → Zálohy in the test copy: the pre-migration backup should be
  „Zlučiteľná“ (restorable), not „Nezlučiteľná“. That is rule 5 verified.
- A failed migration leaves the database exactly as before (transaction). Fix the file, run `create` again.

Never rehearse on the developer's real database and never by hand-editing the `migrations` table.

## When the schema changes, also

- `includes/entities.php` + `lang.php` + templates — `dg-content-model`.
- `includes/demo.php` — demo inserts name their columns; a renamed or newly required column breaks `setup.php --demo`.
- Queries with explicit column lists: `runs_for_page()`, `history_years()`, `cms_archive()`, `cms_options()`.
- `.claude/skills/dg-architecture/references/data-model.md`.
- Dropping a table that old backups contain? Add a `bk_t_<table>` label in `lang.php` so the restore
  screen can name it („V zálohe je navyše …“).
