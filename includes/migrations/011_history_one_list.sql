-- História — jeden zoznam pre obe záložky
--
--  * „Prehľad po rokoch" a „Celá história" ukazujú tie isté údaje, len inak.
--  * Záznam v histórii (tabuľka history) je buď inscenácia z repertoáru
--    (production_id — názov sa berie z repertoáru), alebo udalosť s vlastným
--    názvom (title). K obom sa dá pridať miesto, text a obrázok.
--  * Roky z odohraných termínov „Práve hráme" sa k záznamom pridávajú samy
--    (includes/content.php → history_years); ručný záznam s rovnakým rokom
--    a inscenáciou sa k nim pripojí (napr. doplní text alebo fotku).
--  * Doterajšie ručne zadané roky prehľadu (history_plays) sa presunú sem.

ALTER TABLE history
    ADD COLUMN production_id integer REFERENCES productions (id) ON DELETE CASCADE,
    ADD COLUMN place_sk      varchar(200),
    ADD COLUMN place_en      varchar(200),
    ALTER COLUMN title_sk DROP NOT NULL;

INSERT INTO history (year, production_id, place_sk, place_en, deleted_at, created_at, updated_at)
SELECT year, production_id, place_sk, place_en, deleted_at, created_at, updated_at
  FROM history_plays
 ORDER BY year, id;

-- Záznam je inscenácia alebo udalosť s názvom.
ALTER TABLE history ADD CONSTRAINT history_production_or_title
    CHECK (production_id IS NOT NULL OR nullif(trim(title_sk), '') IS NOT NULL);

CREATE INDEX IF NOT EXISTS history_year ON history (year);

DROP TABLE history_plays;
