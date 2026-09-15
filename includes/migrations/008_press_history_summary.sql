-- V médiách: články, reportáže v TV a rozhlase. Jedna položka môže byť veľká
-- navrchu (vyberá sa ručne), ostatné sú pod ňou v karuseli.
CREATE TABLE press (
    id           serial PRIMARY KEY,
    title_sk     varchar(300) NOT NULL,
    title_en     varchar(300),
    outlet       varchar(200),
    kind         varchar(20)  NOT NULL DEFAULT 'article' CHECK (kind IN ('article', 'tv', 'radio', 'web', 'other')),
    published_on date,
    url          varchar(500),
    image        varchar(255),
    text_sk      text,
    text_en      text,
    is_featured  boolean NOT NULL DEFAULT false,
    is_public    boolean NOT NULL DEFAULT false,
    deleted_at   timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

-- Prehľad histórie po rokoch (rok → inscenácia → kde). Novšie roky sa skladajú
-- samy z odohraných termínov „Práve hráme"; staršie sa zadajú sem.
CREATE TABLE history_plays (
    id            serial PRIMARY KEY,
    year          smallint NOT NULL,
    production_id integer  NOT NULL REFERENCES productions (id) ON DELETE CASCADE,
    place_sk      varchar(200),
    place_en      varchar(200),
    deleted_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX history_plays_year ON history_plays (year);

-- Namiesto výberu „na úvodnej osi" je teraz prehľad po rokoch a celá história po kliknutí.
ALTER TABLE history DROP COLUMN IF EXISTS highlight;
