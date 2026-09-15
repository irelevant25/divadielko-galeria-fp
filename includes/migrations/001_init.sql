-- Divadielko Galéria — základná schéma (PostgreSQL)

CREATE TABLE users (
    id            serial PRIMARY KEY,
    username      varchar(60)  NOT NULL UNIQUE,
    email         varchar(255),
    password_hash varchar(255) NOT NULL,
    role          varchar(20)  NOT NULL DEFAULT 'editor' CHECK (role IN ('admin', 'editor')),
    active        boolean      NOT NULL DEFAULT true,
    last_login_at timestamptz,
    created_at    timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE login_attempts (
    id         bigserial PRIMARY KEY,
    ip_hash    char(64)    NOT NULL,
    username   varchar(100),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX login_attempts_ip ON login_attempts (ip_hash, created_at);

-- Voľné texty, kontakty, režim stránky. Prekladané hodnoty majú kľúč s _sk / _en.
CREATE TABLE settings (
    key        varchar(80) PRIMARY KEY,
    value      text,
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Inscenácie
CREATE TABLE productions (
    id            serial PRIMARY KEY,
    title_sk      varchar(200) NOT NULL,
    title_en      varchar(200),
    subtitle_sk   varchar(200),
    subtitle_en   varchar(200),
    description_sk text,
    description_en text,
    poster        varchar(255),
    price_sk      varchar(60),
    price_en      varchar(60),
    age_from      smallint,
    duration_min  smallint,
    premiere      date,
    is_featured   boolean NOT NULL DEFAULT false,
    in_repertoire boolean NOT NULL DEFAULT true,
    sort          integer NOT NULL DEFAULT 0,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

-- Termíny predstavení. Čas je miestny (Europe/Bratislava).
CREATE TABLE performances (
    id            serial PRIMARY KEY,
    production_id integer NOT NULL REFERENCES productions (id) ON DELETE CASCADE,
    starts_at     timestamp NOT NULL,
    venue_sk      varchar(200),
    venue_en      varchar(200),
    note_sk       varchar(200),
    note_en       varchar(200),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX performances_starts ON performances (starts_at);

-- Súbor
CREATE TABLE members (
    id         serial PRIMARY KEY,
    name       varchar(120) NOT NULL,
    role_sk    varchar(120),
    role_en    varchar(120),
    bio_sk     text,
    bio_en     text,
    since_year smallint,
    active     boolean NOT NULL DEFAULT true,
    sort       integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Galéria — fotografie
CREATE TABLE photos (
    id            serial PRIMARY KEY,
    image         varchar(255) NOT NULL,
    caption_sk    varchar(300),
    caption_en    varchar(300),
    production_id integer REFERENCES productions (id) ON DELETE SET NULL,
    taken_on      date,
    sort          integer NOT NULL DEFAULT 0,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

-- Galéria — videá: odkaz (YouTube / Instagram) alebo nahratý súbor
CREATE TABLE videos (
    id         serial PRIMARY KEY,
    title_sk   varchar(200),
    title_en   varchar(200),
    url        varchar(500),
    file       varchar(255),
    poster     varchar(255),
    sort       integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- História — časová os
CREATE TABLE history (
    id         serial PRIMARY KEY,
    year       smallint NOT NULL,
    title_sk   varchar(200) NOT NULL,
    title_en   varchar(200),
    text_sk    text,
    text_en    text,
    image      varchar(255),
    highlight  boolean NOT NULL DEFAULT true,
    sort       integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Správy z kontaktného formulára
CREATE TABLE messages (
    id         serial PRIMARY KEY,
    name       varchar(120) NOT NULL,
    email      varchar(255) NOT NULL,
    subject    varchar(200),
    body       text NOT NULL,
    lang       varchar(5),
    ip_hash    char(64),
    user_agent varchar(500),
    status     varchar(20) NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'read', 'archived', 'spam')),
    mailed     boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX messages_ip ON messages (ip_hash, created_at);

INSERT INTO settings (key, value) VALUES
    ('site_mode', 'wip'),
    ('founded', '2006');

INSERT INTO history (year, title_sk, title_en, highlight) VALUES
    (2006, 'Založenie divadielka', 'The theatre is founded', true);
