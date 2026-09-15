-- Členovia súboru sa na stránke zobrazujú bez fotografií.
-- (Pre databázy založené pôvodnou verziou 001_init.sql, ktorá stĺpec ešte mala.)

ALTER TABLE members DROP COLUMN IF EXISTS photo;
