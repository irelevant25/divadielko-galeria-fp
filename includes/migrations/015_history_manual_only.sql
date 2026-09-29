-- História: záznamy sa už nedopĺňajú samy z odohraných termínov „Práve hráme".
--
-- Doteraz sa roky a miesta počítali pri každom zobrazení stránky z odohraných
-- termínov, takže sa nedali upraviť ani zmazať (nemali ceruzku). Odteraz je
-- v histórii len to, čo je v tejto tabuľke — a teda všetko sa dá upraviť.
--
-- Aby sa doterajší obsah histórie nestratil, tie isté roky sa sem raz prepíšu:
-- pre každú dvojicu rok + inscenácia vznikne záznam s miestami, kde sa v tom
-- roku hralo (miesto termínu, inak miesto položky „Práve hráme"). Ak už taký
-- záznam niekto pridal ručne, nový nevzniká — len sa mu doplní miesto, ak ho
-- nemal. Ďalej si históriu udržiava redaktor sám.
--
-- Prepisujú sa aj roky inscenácií v archíve (a skrytých). V histórii ich nikto
-- nevidí (filtruje content.php → history_years), ale keď sa inscenácia z archívu
-- vráti, vráti sa s ňou aj to, čo sa s ňou odohralo.
--
-- Miesta sa spájajú čiarkou; stĺpec place_sk má 200 znakov, dlhší zoznam sa skráti
-- a označí výpustkou (…), nech migrácia nespadne na jednom roku — dá sa dopísať ceruzkou.
--
-- Pôvodný stav je v zálohe, ktorá sa robí automaticky pred touto migráciou.

-- Termín sa 2 hodiny po začiatku berie ako odohraný (PERFORMANCE_PAST_AFTER v content.php).
CREATE TEMP TABLE history_from_runs ON COMMIT DROP AS
SELECT extract(year FROM pf.starts_at)::int AS year,
       r.production_id,
       string_agg(DISTINCT coalesce(nullif(trim(pf.venue_sk), ''), nullif(trim(r.venue_sk), '')), ', '
                  ORDER BY coalesce(nullif(trim(pf.venue_sk), ''), nullif(trim(r.venue_sk), ''))) AS places
  FROM performances pf
  JOIN runs r ON r.id = pf.run_id
 WHERE pf.starts_at < now() - interval '2 hours'
 GROUP BY 1, 2;

-- Ručný záznam na ten istý rok a inscenáciu ostáva; doplní sa mu len prázdne miesto.
UPDATE history h
   SET place_sk = left(d.places, 199) || CASE WHEN length(d.places) > 199 THEN '…' ELSE '' END,
       updated_at = now()
  FROM history_from_runs d
 WHERE h.production_id = d.production_id
   AND h.year = d.year
   AND h.deleted_at IS NULL
   AND nullif(trim(coalesce(h.place_sk, '')), '') IS NULL
   AND d.places IS NOT NULL;

INSERT INTO history (year, production_id, place_sk)
SELECT d.year, d.production_id, left(d.places, 199) || CASE WHEN length(d.places) > 199 THEN '…' ELSE '' END
  FROM history_from_runs d
 WHERE NOT EXISTS (
           SELECT 1
             FROM history h
            WHERE h.production_id = d.production_id
              AND h.year = d.year
              AND h.deleted_at IS NULL
       );
