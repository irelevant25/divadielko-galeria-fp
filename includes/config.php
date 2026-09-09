<?php
/**
 * Nastavenia dočasnej (maintenance) stránky.
 * Všetko, čo sa bežne mení, sa dá upraviť tu — do index.php netreba siahať.
 */

declare(strict_types=1);

return [
    // Kým sa pripravuje ostrá stránka, posielame HTTP 503 + Retry-After.
    // Vyhľadávače tak stránku berú ako dočasnú a nezaindexujú ju natrvalo.
    // Po spustení ostrej stránky stačí prepnúť na false.
    'send_503'    => true,
    'retry_after' => 14 * 24 * 60 * 60, // 14 dní v sekundách

    // Jazyky
    'default_lang' => 'sk',
    'languages'    => ['sk', 'en'],
    'lang_cookie'  => 'dg_lang',

    // Kanonická adresa. Prázdne = odvodí sa automaticky z domény,
    // na ktorej stránka beží. Vyplňte, až keď je doména finálna,
    // napr. 'https://www.divadielkogaleria.sk'.
    'canonical_base' => '',

    // Odkazy
    'links' => [
        'msks'      => 'https://www.msks.sk/klient-218/kino-186/stranka-7745',
        'facebook'  => 'https://www.facebook.com/divadielkogaleria',
        'instagram' => 'https://www.instagram.com/divadielko_galeria/',
    ],

    // Kontakt
    'contact' => [
        'phone_display' => '032 / 285 69 24',
        'phone_tel'     => '+421322856924',
        'email'         => 'divadielko-galeria@msks.sk',
        'street'        => 'Hviezdoslavova 4',
        'city_sk'       => '915 01 Nové Mesto nad Váhom',
        'city_en'       => '915 01 Nové Mesto nad Váhom, Slovakia',
    ],

    'founded' => 2006,
];
