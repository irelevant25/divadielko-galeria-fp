-- Vstupné pri inscenácii (voľný text: „3 €", „dobrovoľné vstupné"),
-- zobrazuje sa ako prvý štítok pri veku a dĺžke.

ALTER TABLE productions
    ADD COLUMN IF NOT EXISTS price_sk varchar(60),
    ADD COLUMN IF NOT EXISTS price_en varchar(60);
