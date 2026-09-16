-- Súbor po skupinách
--
--  * Skupina (úloha, napr. „Vodič") má názov v oboch jazykoch a zoznam ľudí
--    (meno + nepovinne „od roku") v stĺpci people. Ten istý človek môže byť
--    vo viacerých skupinách. Poradie skupín sa mení šípkami na stránke,
--    ľudí v okne úprav skupiny.
--  * Doterajší členovia sa rozdelia podľa úlohy: úloha s čiarkami („Vodič, čítač")
--    dá človeka do každej z nich, rovnaká úloha (bez ohľadu na veľké písmená)
--    = jedna skupina. Skupiny idú v poradí, v akom sa úloha prvý raz objavila,
--    ľudia v skupine v doterajšom poradí. Kto nemal úlohu, ide do skupiny „Súbor",
--    bývalí členovia do skupiny „Spolupracovali s nami" na konci.
--  * Členovia v archíve a text „pár slov o sebe" sa neprenášajú — sú v zálohe,
--    ktorá sa urobí automaticky pred touto migráciou.

CREATE TABLE ensemble_groups (
    id         serial PRIMARY KEY,
    name_sk    varchar(120) NOT NULL,
    name_en    varchar(120),
    people     jsonb NOT NULL DEFAULT '[]'::jsonb, -- [{"name": "…", "since": 2006 alebo null}, …]
    sort       integer NOT NULL DEFAULT 0,
    deleted_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Malé / veľké písmená cez translate(): lower() a upper() by v databáze
-- s lokalizáciou „C" diakritiku nezmenili.
WITH people AS (
    SELECT 0 AS block, m.sort, m.id, p.ord, m.name, m.since_year,
           CASE WHEN nullif(trim(m.role_sk), '') IS NULL THEN 'Súbor' ELSE trim(p.part) END AS role_sk,
           CASE WHEN nullif(trim(m.role_sk), '') IS NULL THEN 'Ensemble'
                ELSE nullif(trim(split_part(coalesce(m.role_en, ''), ',', p.ord::int)), '') END AS role_en
      FROM members m
     CROSS JOIN LATERAL unnest(string_to_array(coalesce(nullif(trim(m.role_sk), ''), '-'), ',')) WITH ORDINALITY AS p (part, ord)
     WHERE m.deleted_at IS NULL AND m.active AND trim(p.part) <> ''
    UNION ALL
    SELECT 1, m.sort, m.id, 1, m.name, m.since_year, 'Spolupracovali s nami', 'They have worked with us'
      FROM members m
     WHERE m.deleted_at IS NULL AND NOT m.active
),
keyed AS (
    SELECT people.*,
           translate(role_sk, 'AÁÄBCČDĎEÉĚFGHIÍJKLĹĽMNŇOÓÔPQRŔŘSŠTŤUÚŮVWXYÝZŽ',
                              'aáäbcčdďeéěfghiíjklĺľmnňoóôpqrŕřsštťuúůvwxyýzž') AS role_key,
           row_number() OVER (ORDER BY block, sort, id, ord) AS seq
      FROM people
),
grouped AS (
    SELECT min(seq) AS first_seq,
           (array_agg(role_sk ORDER BY seq))[1] AS name_sk,
           (array_agg(role_en ORDER BY seq) FILTER (WHERE role_en IS NOT NULL))[1] AS name_en,
           jsonb_agg(jsonb_build_object('name', name, 'since', since_year) ORDER BY seq) AS people
      FROM keyed
     GROUP BY role_key
)
INSERT INTO ensemble_groups (name_sk, name_en, people, sort)
SELECT translate(left(name_sk, 1), 'aáäbcčdďeéěfghiíjklĺľmnňoóôpqrŕřsštťuúůvwxyýzž',
                                   'AÁÄBCČDĎEÉĚFGHIÍJKLĹĽMNŇOÓÔPQRŔŘSŠTŤUÚŮVWXYÝZŽ') || substr(name_sk, 2),
       upper(left(name_en, 1)) || substr(name_en, 2),
       people,
       row_number() OVER (ORDER BY first_seq)
  FROM grouped;

DROP TABLE members;
