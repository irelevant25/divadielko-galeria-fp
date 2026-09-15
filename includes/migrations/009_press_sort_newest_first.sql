-- V médiách sa dá zoradiť šípkami ako ostatné zoznamy. Východiskové poradie:
-- veľká položka navrchu, potom od najnovšej.
ALTER TABLE press ADD COLUMN IF NOT EXISTS sort integer NOT NULL DEFAULT 0;
UPDATE press SET sort = o.rn
  FROM (SELECT id, row_number() OVER (ORDER BY is_featured DESC, published_on DESC NULLS LAST, id DESC) AS rn FROM press) o
 WHERE press.id = o.id;

-- Galéria: nové fotografie a videá sa pridávajú na začiatok (najnovšie prvé);
-- doterajšie sa raz zoradia rovnako — od naposledy pridanej.
UPDATE photos SET sort = o.rn
  FROM (SELECT id, row_number() OVER (ORDER BY id DESC) AS rn FROM photos) o
 WHERE photos.id = o.id;
UPDATE videos SET sort = o.rn
  FROM (SELECT id, row_number() OVER (ORDER BY id DESC) AS rn FROM videos) o
 WHERE videos.id = o.id;
