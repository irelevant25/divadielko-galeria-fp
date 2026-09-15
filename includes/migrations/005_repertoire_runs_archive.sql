-- Repertoár, „Práve hráme" a archív
--
--  * Repertoár (productions) je katalóg inscenácií. Nové príznaky:
--      is_public   zobraziť verejnosti (nové inscenácie začínajú skryté)
--      is_retired  „už nehráme" — v repertoári sa zobrazí sivo
--  * „Práve hráme" je samostatný zoznam (runs): každá položka odkazuje na
--    inscenáciu z repertoáru, má vlastné „zobraziť verejnosti" a vlastné termíny.
--  * Termíny patria k položke „Práve hráme" (run_id), nie priamo k inscenácii.
--  * Vek a dĺžka sú voľný text v oboch jazykoch.
--  * Mäkké mazanie: deleted_at — zmazané zmizne zo stránky a ostane v archíve
--    v administrácii (odtiaľ sa dá obnoviť alebo zmazať natrvalo).

-- ── Repertoár ────────────────────────────────────────────────────────────────

ALTER TABLE productions
    ADD COLUMN is_public   boolean NOT NULL DEFAULT true,   -- existujúce ostanú verejné …
    ADD COLUMN is_retired  boolean NOT NULL DEFAULT false,
    ADD COLUMN deleted_at  timestamptz,
    ADD COLUMN age_sk      varchar(60),
    ADD COLUMN age_en      varchar(60),
    ADD COLUMN duration_sk varchar(60),
    ADD COLUMN duration_en varchar(60);
ALTER TABLE productions ALTER COLUMN is_public SET DEFAULT false;  -- … nové začínajú skryté

UPDATE productions SET is_retired = true WHERE NOT in_repertoire;
UPDATE productions SET age_sk = 'od ' || age_from || ' rokov', age_en = 'ages ' || age_from || '+'
 WHERE age_from IS NOT NULL;
UPDATE productions SET duration_sk = duration_min || ' minút', duration_en = duration_min || ' minutes'
 WHERE duration_min IS NOT NULL;

-- ── Práve hráme ──────────────────────────────────────────────────────────────

CREATE TABLE runs (
    id            serial PRIMARY KEY,
    production_id integer NOT NULL REFERENCES productions (id) ON DELETE CASCADE,
    is_public     boolean NOT NULL DEFAULT false,
    sort          integer NOT NULL DEFAULT 0,
    deleted_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

-- Doterajšie „Práve hráme" → položky zoznamu (verejné, v rovnakom poradí).
INSERT INTO runs (production_id, is_public, sort)
SELECT id, true, sort FROM productions WHERE is_featured;

-- Termíny patria k položke „Práve hráme".
ALTER TABLE performances ADD COLUMN run_id integer REFERENCES runs (id) ON DELETE CASCADE;
UPDATE performances pf SET run_id = r.id FROM runs r WHERE r.production_id = pf.production_id;

-- Termíny inscenácií, ktoré neboli v „Práve hráme", by inak zmizli — dostanú vlastnú položku.
INSERT INTO runs (production_id, is_public, sort)
SELECT DISTINCT pf.production_id, true, 1000 FROM performances pf WHERE pf.run_id IS NULL;
UPDATE performances pf SET run_id = r.id FROM runs r WHERE pf.run_id IS NULL AND r.production_id = pf.production_id;

ALTER TABLE performances ALTER COLUMN run_id SET NOT NULL;
ALTER TABLE performances DROP COLUMN production_id;
CREATE INDEX performances_run ON performances (run_id, starts_at);

ALTER TABLE productions
    DROP COLUMN is_featured,
    DROP COLUMN in_repertoire,
    DROP COLUMN age_from,
    DROP COLUMN duration_min;

-- ── Mäkké mazanie aj pre ostatný obsah ───────────────────────────────────────

ALTER TABLE members ADD COLUMN deleted_at timestamptz;
ALTER TABLE photos  ADD COLUMN deleted_at timestamptz;
ALTER TABLE videos  ADD COLUMN deleted_at timestamptz;
ALTER TABLE history ADD COLUMN deleted_at timestamptz;
