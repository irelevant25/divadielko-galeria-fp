-- Stránka je len po slovensky + nová sekcia „O nás" namiesto „V médiách"
--
--  * Anglická verzia sa ruší: stĺpce *_en v každej tabuľke aj nastavenia
--    s kľúčom *_en sa mažú (texty ostávajú v stĺpcoch *_sk).
--  * „V médiách" už nie je — tabuľka press aj jej nastavenia sa mažú.
--  * „O nás" je dlhší text o súbore v nastaveniach (about_title_sk, about_text_sk),
--    dá sa v ňom použiť <b> a <br>. V poradí sekcií nastúpi na miesto „V médiách".
--  * Pri správach z kontaktného formulára už netreba jazyk.

DROP TABLE IF EXISTS press;

ALTER TABLE messages DROP COLUMN IF EXISTS lang;

-- Anglické stĺpce v ktorejkoľvek tabuľke.
DO $$
DECLARE col record;
BEGIN
    FOR col IN
        SELECT c.table_name, c.column_name
          FROM information_schema.columns c
          JOIN information_schema.tables t
            ON t.table_schema = c.table_schema AND t.table_name = c.table_name
         WHERE c.table_schema = 'public'
           AND t.table_type = 'BASE TABLE'
           AND c.column_name LIKE '%\_en'
    LOOP
        EXECUTE format('ALTER TABLE %I DROP COLUMN %I', col.table_name, col.column_name);
    END LOOP;
END $$;

DELETE FROM settings WHERE key LIKE '%\_en';
DELETE FROM settings WHERE key LIKE 'media\_%' OR key LIKE 'nav\_media%';

-- „O nás" nastúpi na miesto „V médiách" (poradie sa dá zmeniť v administrácii).
UPDATE settings
   SET value = array_to_string(array_replace(string_to_array(value, ','), 'media', 'onas'), ','),
       updated_at = now()
 WHERE key = 'section_order' AND value LIKE '%media%';

-- Text o divadielku (dá sa prepísať v administrácii → ceruzka pri nadpise).
INSERT INTO settings (key, value) VALUES ('about_text_sk', 'V zrekonštruovaných priestoroch Metského kultúrneho strediska (MsKS) v Novom Meste nad Váhom, z iniciatívy a podpory vtedajšieho riaditeľa MsKS akad. mal Jána Mikušku, bola umiestnená stála expozícia novovzniknutej Galérie Petra Matejku. Súbežne s ňou vzniklo v decembri 2006 Divadielko galéria (DG).

Divadielko galéria je najmladším divadelným súborom vo viac ako 150-rošnej histórii novomestského ochotníckeho divadla.

Súbor, v spolupráci s talentovanými žiakmi stredných škôl, sa etabloval prvým vystúpením pri slávnostnom otvorení Galérie Petra Matejku 1. decembra 2006 hudobno-literárnym pásmom <b>S vianočnou sviečkou otváram tajomný závoj čara...</b>. Pri jeho zrode stáli Daniela Arbetová, Bibiana Kincelová a Ivan Radošínsky.

Pomyselné čaro divadla súbor šíri prostredníctvom, bábkového divadla, činohy a poetickej scény.

Hlavným zámerom v súvislosti s využitím zrekonštruovaných priestorov bola i realizácia myšlienky obnovenia činnosti bábkového divadla. A tak od apríla 2007 k Divadielku galéria neodmysliteľne patrí bábkové scéna, ktorá nadviazala na tradíciu bábkového súboru Závodného klubu Strojár pri novomestskom podniku VUMA. S bábkami - marionetami na dlhých nitiach - bábkoherci v naštudovaných inscenáciach klasických rozprávok oslovujú najmenších divákov. Prvou premiérou bola rozprávka <b>Šťuka patrí na pekáč</b>.

Ďalšie hry, ktoré súbor nadšených ochotníckych bábkarov, pod vedením režiséra Ivana Radošínskeho, uviedol počas svojho pôsobenia, boli príbehy ako <b>Princezná kukulienka</b>, <b>Zlatá priadka</b>, <b>Adamko medzi chrobáčikmi</b>, <b>Začarovaný les</b>, <b>Eliášove husle</b>, <b>Kaimovo dobrodružstvo</b>, <b>Knôpka</b> a ďalšie. V reprtoári súboru je 16 hier spolu s pripravovanou premiérou <b>Ostrov splnených prianí</b> (máj 2026). Medzi viac ako 400 odohranými predstaveniami môžeme spomenúť celoštátnu súťaž a prehliadku divadla dospelých hrajúcich pre deti DIVADLO A DETI - Rimavská Sobota 2013, prezentácie na domácej scéne i v blízkom okolí a zahraničí - v Rumunsku, Česku a každoročne na Festivale Zlatá brána v Srbsku.

Pri založení súboru stáli Eva Ončáková, Zuzana Ferenčíková, Oľga Kosecová, Ján Kincel, Ľubomír Malec a ďalší.

Od 2008 bola otvorená spolupráca so scénografkou Ľubicou Ivanovskou a Tomášom Bačom, technická spolupráca. Od roku 2017 je dvornou hudobnou skladateľkou súboru DG Mária Volárová.')
ON CONFLICT (key) DO NOTHING;
