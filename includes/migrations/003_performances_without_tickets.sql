-- Vstupenky sa predávajú na mieste pred predstavením — odkaz na ne ani
-- príznak „vypredané" sa nepoužívajú. (Pre databázy založené pôvodnou
-- verziou 001_init.sql, ktorá tieto stĺpce ešte mala.)

ALTER TABLE performances
    DROP COLUMN IF EXISTS ticket_url,
    DROP COLUMN IF EXISTS sold_out;
