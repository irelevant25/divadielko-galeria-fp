-- História: nepovinný mesiac a deň pri zázname a obsadenie (kto koho hral).
--
--  * Doteraz mal záznam len rok. Pri udalosti (premiéra, ocenenie, hosťovanie) sa hodí
--    aj presný dátum, preto pribudol mesiac a deň — oba nepovinné, deň len spolu
--    s mesiacom. Či taký deň v mesiaci naozaj je (30. február), stráži includes/cms.php;
--    tu sú len hranice. Doterajšie záznamy ostávajú bez mesiaca, ako boli.
--  * Obsadenie je zoznam dvojíc [{"role": "…", "name": "…"}, …] — vľavo postava alebo
--    úloha (aj Réžia, Hudba…), vpravo meno. Na stránke sa ukáže po kliknutí na záznam;
--    s prázdnym zoznamom sa záznam rozkliknúť nedá. Predvolené '[]' drží staršie zálohy
--    obnoviteľné.

ALTER TABLE history
    ADD COLUMN month     smallint,
    ADD COLUMN day       smallint,
    ADD COLUMN cast_list jsonb NOT NULL DEFAULT '[]'::jsonb;

ALTER TABLE history
    ADD CONSTRAINT history_month CHECK (month BETWEEN 1 AND 12),
    ADD CONSTRAINT history_day CHECK (day IS NULL OR (month IS NOT NULL AND day BETWEEN 1 AND 31));
