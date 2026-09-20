<?php
/**
 * Predvolené nastavenia. Všetko, čo je tajné alebo iné na serveri než lokálne
 * (heslo k databáze, tajný kľúč, spôsob odosielania pošty), patrí do
 * includes/config.local.php — ten sa necommituje a jeho hodnoty prepíšu tieto.
 *
 * Vzor config.local.php:
 *
 *   <?php return [
 *       'secret' => '…aspoň 32 náhodných znakov…',
 *       'db'     => ['dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=divadielko', 'user' => '…', 'password' => '…'],
 *       'mail'   => ['driver' => 'mail'],
 *   ];
 */

declare(strict_types=1);

return [
    // Režim stránky:
    //   null          = riadi sa z administrácie (admin.php → Nastavenia)
    //   'wip'         = „pripravujeme novú stránku"
    //   'maintenance' = „krátka prestávka" (údržba)
    //   'live'        = ostrá stránka
    // Hodnota tu má prednosť pred administráciou — hodí sa pri nasadzovaní.
    // Prihlásený používateľ vždy vidí ostrú stránku (s ceruzkami na úpravu).
    'mode' => null,

    // Kým stránka nie je ostrá, posielame HTTP 503 + Retry-After,
    // aby ju vyhľadávače nezaindexovali natrvalo.
    'retry_after' => [
        'wip'         => 14 * 24 * 60 * 60, // 14 dní
        'maintenance' => 60 * 60,           // 1 hodina
    ],

    // Jazyk stránky (obsah je v databáze v stĺpcoch *_sk)
    'default_lang' => 'sk',

    // Kanonická adresa. Prázdne = odvodí sa z domény, na ktorej stránka beží.
    'canonical_base' => '',

    // true = stránka posiela X-Robots-Tag: noindex (pre skúšobnú inštaláciu na
    // test.divadielkogaleria.sk, aby sa neukazovala vo vyhľadávačoch popri ostrej).
    'noindex' => false,

    // Tajný kľúč pre podpisy (kontaktný formulár, anonymizácia IP). V config.local.php!
    'secret' => '',

    // PostgreSQL
    'db' => [
        'dsn'      => 'pgsql:host=127.0.0.1;port=5432;dbname=divadielko',
        'user'     => '',
        'password' => '',
    ],

    // Pošta z kontaktného formulára.
    //   driver 'file' = správy sa len uložia do storage/mail/ (vývoj)
    //   driver 'mail' = PHP mail() — to podporuje Websupport. Adresa 'from'
    //                   musí byť skutočná schránka na doméne webu.
    // Príjemca sa nastavuje v administrácii (Kontakt → e-mail); 'to' je záloha.
    'mail' => [
        'driver'    => 'file',
        'from'      => 'web@divadielkogaleria.sk',
        'from_name' => 'Divadielko Galéria — web',
        'to'        => 'divadielko-galeria@msks.sk',
    ],

    // Nahrávanie súborov
    'upload' => [
        'chunk_size'     => 4 * 1024 * 1024,    // súbor sa posiela po kúskoch (obíde limit hostingu)
        'max_size'       => 8 * 1024 * 1024 * 1024, // 8 GB — záznam celého predstavenia z kamery
        'image_max_edge' => 2400,               // dlhšia strana obrázka po konverzii (px)
        'avif_quality'   => 60,
        'opus_bitrate'   => '96k',
        'video_crf'      => 34,                 // kvalita videa AV1 (0–63): nižšie = lepšie a väčšie
        'video_max_edge' => 1920,
        'video_max_fps'  => 30,                 // 50/60 snímok z kamery → 25/30 (polovica času kódovania); 0 = nechať
        // Video sa konvertuje po častiach (includes/video.php). Jedna požiadavka nesmie trvať dlho —
        // Cloudflare ju po ~100 s ukončí:
        'video_inline_seconds'  => 20,          // toľko sa konvertuje hneď pri nahratí; krátke video sa stihne celé
        'video_step_seconds'    => 30,          // najviac toľko trvá jedno volanie api.php → convert_step
        'video_segment_seconds' => 12,          // cieľová dĺžka práce na jednej časti
        'video_cron_seconds'    => 50,          // toľko pracuje jedno volanie cron.php (nikto naň nečaká, môže dlhšie)
    ],

    // Cesta k ffmpeg. Prázdne = hľadá sa v PATH, bežných miestach a v tools/.
    'ffmpeg' => '',

    // Kľúč pre setup.php z prehliadača (setup.php?key=…). Prázdne = len z príkazového riadku.
    'setup_key' => '',

    // Kľúč pre cron.php (cron.php?key=…) — plánovač hostingu ním poháňa rozpracované konverzie videa.
    // Zámerne iný než setup_key: tento skončí v nastaveniach plánovača a v logoch prístupov.
    // Aspoň 16 znakov; prázdne = cron.php sa z prehliadača zavolať nedá.
    'cron_key' => '',

    // Analytika — self-hosted Umami (bez cookies → netreba cookie lištu).
    'analytics' => [
        'enabled'    => true,
        'src'        => 'https://umami.divadielkogaleria.sk/script.js',
        'website_id' => 'f20cc0ce-9a0b-4bdc-9a36-5f9031a4dfda',
        'skip_hosts' => ['localhost', '127.0.0.1', '::1'],
    ],

    // Predvolený kontakt a odkazy — používajú sa, kým ich niekto nezmení
    // v administrácii, a vždy na dočasných stránkach, keby databáza nebežala.
    'contact' => [
        'phone_display' => '032 / 285 69 24',
        'phone_tel'     => '+421322856924',
        'email'         => 'divadielko-galeria@msks.sk',
        'street'        => 'Hviezdoslavova 4',
        'city'          => '915 01 Nové Mesto nad Váhom',
    ],
    'links' => [
        'msks'      => 'https://www.msks.sk/klient-218/kino-186/stranka-7745',
        'facebook'  => 'https://www.facebook.com/divadielkogaleria',
        'instagram' => 'https://www.instagram.com/divadielko_galeria/',
        'youtube'   => '',
    ],

    'founded' => 2006,
];
