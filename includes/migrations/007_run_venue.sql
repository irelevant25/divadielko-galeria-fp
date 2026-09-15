-- „Práve hráme" má vlastné miesto (kde sa hrá). Termín môže mať iné miesto
-- (napr. hosťovanie) — vtedy sa pri ňom zobrazí to jeho.

ALTER TABLE runs
    ADD COLUMN venue_sk varchar(200),
    ADD COLUMN venue_en varchar(200);

-- Keď majú všetky termíny položky rovnaké miesto, presunie sa na položku …
UPDATE runs r
   SET venue_sk = x.venue_sk, venue_en = x.venue_en
  FROM (SELECT run_id, min(venue_sk) AS venue_sk, min(venue_en) AS venue_en
          FROM performances
         GROUP BY run_id
        HAVING count(DISTINCT coalesce(venue_sk, '')) = 1
           AND count(DISTINCT coalesce(venue_en, '')) = 1) x
 WHERE x.run_id = r.id;

-- … a pri termínoch sa už neopakuje.
UPDATE performances pf
   SET venue_sk = NULL, venue_en = NULL
  FROM runs r
 WHERE r.id = pf.run_id
   AND r.venue_sk IS NOT NULL
   AND coalesce(pf.venue_sk, '') = coalesce(r.venue_sk, '')
   AND coalesce(pf.venue_en, '') = coalesce(r.venue_en, '');
