<?php
/**
 * Čo sa dá na stránke upravovať. Z tohto popisu sa skladá formulár v okne
 * úprav (static/js/cms.js) aj ukladanie (api.php), takže nové pole = nový
 * riadok tu + stĺpec v databáze (migrácia) + jeho vykreslenie v pages/site.php.
 *
 * Typy polí:
 *   text, textarea, number, date, datetime, bool, url,
 *   select ('options' => entita, alebo 'choices' => [hodnota => kľúč prekladu]),
 *   file   ('accept' => image | video | audio | any)
 *   files  viac súborov naraz (zoznam v stĺpci jsonb), 'accept' ako pri file
 *   people ľudia v skupine súboru: zoznam {name, since} v stĺpci jsonb, 'max' = najviac ľudí
 *   i18n   => true: text v stĺpci <pole>_sk
 *
 * 'soft_delete' => true: kôš na stránke presunie záznam do archívu (deleted_at);
 * natrvalo sa maže až v administrácii → Archív.
 * 'new_first' => true: nový záznam ide na začiatok zoznamu (inak na koniec).
 * 'move_scope' => podmienka SQL: ktoré záznamy sa pri posune šípkami berú do úvahy.
 *
 * Názov poľa vo formulári je preklad 'f_<pole>' z lang.php ('label' => kľúč ho nahradí).
 */

declare(strict_types=1);

return [
    'entities' => [
        // Repertoár — katalóg inscenácií: čo to je (popis, obrázok, ukážka, galéria).
        // Termíny, plagát a vstupné patria k „Práve hráme".
        'productions' => [
            'order'  => 'sort, id',
            'sortable' => true,
            'soft_delete' => true,
            'fields' => [
                'title'       => ['type' => 'text', 'i18n' => true, 'required' => true, 'max' => 200],
                'subtitle'    => ['type' => 'text', 'i18n' => true, 'max' => 200],
                'description' => ['type' => 'textarea', 'i18n' => true, 'max' => 5000],
                'image'       => ['type' => 'file', 'accept' => 'image', 'hint' => 'hint_play_image'],
                'age'         => ['type' => 'text', 'i18n' => true, 'max' => 60, 'hint' => 'hint_age'],
                'duration'    => ['type' => 'text', 'i18n' => true, 'max' => 60, 'hint' => 'hint_duration'],
                'premiere'    => ['type' => 'date'],
                'trailer'     => ['type' => 'file', 'accept' => 'video', 'hint' => 'hint_trailer'],
                'trailer_url' => ['type' => 'url', 'hint' => 'hint_trailer_url'],
                'images'      => ['type' => 'files', 'accept' => 'image', 'max' => 60, 'hint' => 'hint_images'],
                'is_public'   => ['type' => 'bool', 'default' => false, 'hint' => 'hint_public_play'],
                'is_retired'  => ['type' => 'bool', 'default' => false, 'hint' => 'hint_retired'],
            ],
        ],
        // Práve hráme — inscenácia z repertoáru + vlastný plagát (banner), vstupné a termíny.
        'runs' => [
            'order'  => 'sort, id',
            'sortable' => true,
            'soft_delete' => true,
            'fields' => [
                'production_id' => ['type' => 'select', 'options' => 'productions', 'required' => true, 'hint' => 'hint_run_production'],
                'poster'        => ['type' => 'file', 'accept' => 'image', 'hint' => 'hint_run_poster'],
                'price'         => ['type' => 'text', 'i18n' => true, 'max' => 60, 'hint' => 'hint_price'],
                'venue'         => ['type' => 'text', 'i18n' => true, 'max' => 200, 'hint' => 'hint_run_venue'],
                'venue_url'     => ['type' => 'url', 'hint' => 'hint_run_venue_url'],
                'venue_map_url' => ['type' => 'url', 'label' => 'f_map_url', 'hint' => 'hint_run_venue_map_url'],
                'is_public'     => ['type' => 'bool', 'default' => false, 'hint' => 'hint_public_run'],
            ],
        ],
        // Termín patrí k položke „Práve hráme". Zmazaný termín zmizne hneď (je to len oprava).
        'performances' => [
            'order'  => 'starts_at, id',
            'sortable' => false,
            'fields' => [
                'run_id'    => ['type' => 'select', 'options' => 'runs', 'required' => true],
                'starts_at' => ['type' => 'datetime', 'required' => true],
                'venue'     => ['type' => 'text', 'i18n' => true, 'max' => 200, 'hint' => 'hint_date_venue'],
                'note'      => ['type' => 'text', 'i18n' => true, 'max' => 200],
            ],
        ],
        // Súbor po skupinách (úlohách) ako záverečné titulky vo filme. Ten istý
        // človek môže byť vo viacerých skupinách; ľudia sa upravujú v okne skupiny.
        'ensemble_groups' => [
            'order'  => 'sort, id',
            'sortable' => true,
            'soft_delete' => true,
            'fields' => [
                'name'   => ['type' => 'text', 'i18n' => true, 'required' => true, 'max' => 120, 'label' => 'f_group_name', 'hint' => 'hint_group_name'],
                'people' => ['type' => 'people', 'max' => 100, 'hint' => 'hint_group_people'],
            ],
        ],
        'photos' => [
            'order'  => 'sort, id',
            'sortable' => true,
            'new_first' => true,
            'soft_delete' => true,
            'fields' => [
                'image'         => ['type' => 'file', 'accept' => 'image', 'required' => true],
                'caption'       => ['type' => 'text', 'i18n' => true, 'max' => 300],
                'production_id' => ['type' => 'select', 'options' => 'productions'],
                'taken_on'      => ['type' => 'date'],
            ],
        ],
        'videos' => [
            'order'  => 'sort, id',
            'sortable' => true,
            'new_first' => true,
            'soft_delete' => true,
            'fields' => [
                'title'  => ['type' => 'text', 'i18n' => true, 'max' => 200],
                'url'    => ['type' => 'url', 'hint' => 'hint_video_url'],
                'file'   => ['type' => 'file', 'accept' => 'video', 'hint' => 'hint_video_file'],
                'poster' => ['type' => 'file', 'accept' => 'image', 'hint' => 'hint_video_poster'],
            ],
        ],
        // História — jeden zoznam pre obe záložky (po rokoch aj celá história). Záznam je
        // inscenácia z repertoáru alebo udalosť s vlastným názvom (jedno z toho je povinné,
        // pozri cms_save); roky z „Práve hráme" sa k nim pridávajú samy (content.php → history_years).
        'history' => [
            'order'  => 'year, sort, id',
            'sortable' => false,
            'soft_delete' => true,
            'fields' => [
                'year'          => ['type' => 'number', 'min' => 1900, 'max' => 2100, 'required' => true],
                'production_id' => ['type' => 'select', 'options' => 'productions', 'hint' => 'hint_history_production'],
                'title'         => ['type' => 'text', 'i18n' => true, 'max' => 200, 'label' => 'f_history_title', 'hint' => 'hint_history_title'],
                'place'         => ['type' => 'text', 'i18n' => true, 'max' => 200, 'hint' => 'hint_history_place'],
                'text'          => ['type' => 'textarea', 'i18n' => true, 'max' => 5000],
                'image'         => ['type' => 'file', 'accept' => 'image'],
            ],
        ],
    ],

    // Voľné texty a kontakty (tabuľka settings: kľúč → hodnota). Nadpis sekcie
    // (<sekcia>_title) prázdny = predvolený; ostatné texty prázdne = nezobrazia sa.
    'settings' => [
        'intro' => [
            'hero_badge' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'hint' => 'hint_hero_badge'],
            'hero_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'hint' => 'hint_hero_title'],
            'tagline'    => ['type' => 'textarea', 'i18n' => true, 'max' => 400],
        ],
        'program' => [
            'tickets_note'  => ['type' => 'text', 'i18n' => true, 'max' => 200, 'hint' => 'hint_tickets_note'],
            'program_empty' => ['type' => 'textarea', 'i18n' => true, 'max' => 400],
        ],
        // O nás — dlhší text o divadielku (dá sa v ňom použiť <b> a <br>).
        'about' => [
            'about_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'label' => 'f_section_title', 'hint' => 'hint_section_title'],
            'about_text'  => ['type' => 'textarea', 'i18n' => true, 'max' => 20000, 'label' => 'f_about_text', 'hint' => 'hint_about_text'],
        ],
        'gallery' => [
            'gallery_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'label' => 'f_section_title', 'hint' => 'hint_section_title'],
            'gallery_intro' => ['type' => 'textarea', 'i18n' => true, 'max' => 1000, 'label' => 'f_section_intro'],
        ],
        'ensemble' => [
            'ensemble_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'label' => 'f_section_title', 'hint' => 'hint_section_title'],
            'ensemble_intro' => ['type' => 'textarea', 'i18n' => true, 'max' => 2000, 'label' => 'f_section_intro'],
        ],
        'repertoire' => [
            'repertoire_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'label' => 'f_section_title', 'hint' => 'hint_section_title'],
            'repertoire_intro' => ['type' => 'textarea', 'i18n' => true, 'max' => 1000, 'label' => 'f_section_intro'],
        ],
        'history' => [
            'history_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'label' => 'f_section_title', 'hint' => 'hint_section_title'],
            'history_intro' => ['type' => 'textarea', 'i18n' => true, 'max' => 3000, 'label' => 'f_section_intro'],
            'founded'       => ['type' => 'number', 'min' => 1900, 'max' => 2100, 'hint' => 'hint_founded'],
        ],
        // Kontakt: nadpis a text pod ním (ceruzka pri nadpise) a zvlášť údaje (ceruzka pri kontaktoch).
        'contact_head' => [
            'contact_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'label' => 'f_section_title', 'hint' => 'hint_section_title'],
            'contact_intro' => ['type' => 'textarea', 'i18n' => true, 'max' => 1000, 'label' => 'f_section_intro'],
        ],
        'contact' => [
            'phone_display' => ['type' => 'text', 'max' => 60],
            'phone_tel'     => ['type' => 'text', 'max' => 30, 'hint' => 'hint_phone_tel'],
            'email'         => ['type' => 'text', 'max' => 200, 'hint' => 'hint_contact_email'],
            'street'        => ['type' => 'text', 'max' => 200],
            'city'          => ['type' => 'text', 'max' => 200],
            'map_url'       => ['type' => 'url', 'hint' => 'hint_map_url'],
        ],
        'links' => [
            'facebook'  => ['type' => 'url'],
            'instagram' => ['type' => 'url'],
            'youtube'   => ['type' => 'url'],
            'msks'      => ['type' => 'url'],
        ],
        // Názvy položiek menu (prázdne = predvolený) — administrácia → Sekcie a menu.
        'nav' => [
            'nav_domov'     => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_domov'],
            'nav_onas'      => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_onas'],
            'nav_galeria'   => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_galeria'],
            'nav_subor'     => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_subor'],
            'nav_repertoar' => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_repertoar'],
            'nav_historia'  => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_historia'],
            'nav_kontakt'   => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_kontakt'],
        ],
    ],
];
