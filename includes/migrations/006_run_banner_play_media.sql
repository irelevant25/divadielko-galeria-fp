-- „Práve hráme" má vlastný plagát (banner) a vstupné.
-- Repertoár má vlastný obrázok, ukážku (video) a galériu obrázkov; vstupné už nie.

ALTER TABLE runs
    ADD COLUMN poster   varchar(255),
    ADD COLUMN price_sk varchar(60),
    ADD COLUMN price_en varchar(60);

-- Doterajší plagát a vstupné inscenácie prejdú na jej položky „Práve hráme".
UPDATE runs r
   SET poster = p.poster, price_sk = p.price_sk, price_en = p.price_en
  FROM productions p
 WHERE p.id = r.production_id;

-- Plagát ostáva zatiaľ ako obrázok v repertoári, kým ho niekto nevymení.
ALTER TABLE productions RENAME COLUMN poster TO image;

ALTER TABLE productions
    DROP COLUMN price_sk,
    DROP COLUMN price_en,
    ADD COLUMN trailer     varchar(255),                      -- nahraté video (ukážka)
    ADD COLUMN trailer_url varchar(500),                      -- alebo odkaz na YouTube
    ADD COLUMN images      jsonb NOT NULL DEFAULT '[]'::jsonb; -- galéria obrázkov inscenácie
