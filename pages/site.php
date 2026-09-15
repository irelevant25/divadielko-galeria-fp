<?php
/**
 * Ostrá stránka — jedna stránka so sekciami (poradie okrem Domova a názvy v menu
 * sa menia v administrácii → Sekcie a menu):
 *   #domov     úvod, čo a kedy hráme (plagát + termíny)
 *   #media     V médiách — veľká položka navrchu, ostatné v karuseli
 *   #galeria   fotografie a videá (karusely)
 *   #subor     členovia súboru (karusel kariet, v každej traja pod sebou)
 *   #repertoar inscenácie (karusel, podrobnosti v okne)
 *   #historia  prehľad po rokoch (čo a kde sme hrali) a celá história (vlastné texty)
 *   #kontakt   kontakty a formulár
 *
 * Karusel: počet stĺpcov a riadkov na stranu určuje CSS podľa šírky obrazovky
 * (.carousel--*), šípky a bodky dopĺňa static/js/site.js.
 *
 * Prihlásený používateľ vidí pri každom obsahu ceruzku (cms_controls,
 * cms_settings, cms_add) — úpravy obsluhuje static/js/cms.js.
 */

declare(strict_types=1);

require_once ROOT . '/includes/mail.php';

$lang   = lang();
$base   = base_url();
$editor = cms_on();

// ── Údaje ────────────────────────────────────────────────────────────────────

// „Práve hráme": položky s inscenáciou z repertoáru a vlastnými termínmi (aj odohranými — tie sú sivé).
$runs    = runs_for_page($editor);
$datesOf = run_dates(array_column($runs, 'run_id'));

// Budúce termíny zverejnených položiek — pre vyhľadávače a vetu o vstupenkách.
$upcoming = [];
foreach ($runs as $run) {
    foreach ($run['run_public'] ? ($datesOf[(int) $run['run_id']] ?? []) : [] as $pf) {
        if (!$pf['is_past']) {
            $upcoming[] = ['date' => $pf, 'play' => $run];
        }
    }
}

// Repertoár: verejnosť vidí zverejnené inscenácie (tie, čo už nehráme, sivo), prihlásený všetky.
$repertoire = list_entity('productions', $editor ? '' : 'is_public');

$members = list_entity('members', $editor ? '' : 'active');
$former  = $editor ? [] : list_entity('members', 'NOT active');

$photos = list_entity('photos');
$videos = list_entity('videos');

// V médiách: sekcia (aj odkaz v menu) sa návštevníkovi zobrazí, len keď je čo ukázať.
[$pressFeatured, $pressRest] = press_for_page($editor);
$showPress = $editor || $pressFeatured !== null || $pressRest !== [];

$summary = history_summary();
$history = list_entity('history');

$links = array_filter([
    'facebook'  => link_to('facebook'),
    'instagram' => link_to('instagram'),
    'youtube'   => link_to('youtube'),
]);

$mapUrl = setting('map_url', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(contact('street') . ', ' . contact('city')));

$cfStatus = isset($_GET['cf']) && is_string($_GET['cf']) ? $_GET['cf'] : '';

// ── Pomocníci na vykreslenie ─────────────────────────────────────────────────

// Odohraný termín ostáva v zozname, len sivý — bez slov (čítačke obrazovky to povie skrytý text).
$renderDate = static function (array $pf): void {
    $ts = strtotime((string) $pf['starts_at']);
    ?>
      <li class="date<?= $pf['is_past'] ? ' is-past' : '' ?> cms-item">
        <time class="date__when" datetime="<?= e(date('Y-m-d\TH:i', $ts)) ?>">
          <span class="date__day"><?= e(date('j', $ts)) ?></span>
          <span class="date__month"><?= e(explode(',', t('months_short'))[(int) date('n', $ts) - 1]) ?></span>
<?php if ($pf['is_past']): ?>
          <span class="visually-hidden"><?= e(t('date_past')) ?></span>
<?php endif; ?>
        </time>
        <div class="date__info">
          <p class="date__meta"><span class="date__weekday"><?= e(format_date($pf['starts_at'], 'weekday')) ?></span> <?= e(format_time($pf['starts_at'])) ?></p>
<?php if (tr($pf, 'venue') !== ''): ?>
          <p class="date__venue"><?= e(tr($pf, 'venue')) ?></p>
<?php endif; ?>
<?php if (tr($pf, 'note') !== ''): ?>
          <p class="date__note"><?= e(tr($pf, 'note')) ?></p>
<?php endif; ?>
        </div>
        <?= cms_controls('performances', (int) $pf['id']) ?>
      </li>
<?php
};

// Vstupenky sa predávajú na mieste — pod všetkými termínmi je o tom jedna veta
// (dá sa upraviť aj skryť). Návštevník ju vidí, len keď sú nejaké termíny.
$renderTicketsNote = static function (bool $hasDates) use ($editor): void {
    $note = setting_tr('tickets_note');
    if (!$editor && ($note === '' || !$hasDates)) {
        return;
    }
    ?>
    <div class="tickets-note cms-zone<?= $note === '' ? ' is-hidden-note' : '' ?>">
<?php if ($note !== ''): ?>
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16a1 1 0 0 1 1 1v3a2 2 0 0 0 0 4v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a2 2 0 0 0 0-4V7a1 1 0 0 1 1-1z"/><path d="M15 7.5v2M15 11v2M15 14.5v2"/></svg>
      <p><?= e($note) ?></p>
<?php else: ?>
      <p><?= e(t('tickets_note_hidden')) ?></p>
<?php endif; ?>
      <?= cms_settings('program') ?>
    </div>
<?php
};

// Vstupné sa zobrazuje ako prvý (zvýraznený) štítok, ostatné údaje za ním.
$price = static fn (array $p): string => tr($p, 'price');

// Tlačidlá „Ukážka" (video v okne) a „Galéria" (obrázky s šípkami a počítadlom 3 / 9)
// — zobrazia sa, len keď má inscenácia čo ukázať. V okne s podrobnosťami je galéria
// rovno ako náhľady, takže tam je len „Ukážka".
$renderPlayActions = static function (array $p, bool $withGallery = true): void {
    $trailer = play_trailer($p);
    $images  = $withGallery ? play_images($p) : [];
    if (!$trailer && !$images) {
        return;
    }
    $title = tr($p, 'title');
    ?>
        <div class="play-actions">
<?php if ($trailer): ?>
          <button type="button" class="btn btn--ghost btn--compact" data-player="<?= e(json_encode($trailer, JSON_UNESCAPED_SLASHES)) ?>" data-title="<?= e($title) ?>">
            <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5.5v13l11-6.5z"/></svg>
            <span><?= e(t('play_trailer')) ?></span>
          </button>
<?php endif; ?>
<?php if ($images): ?>
          <button type="button" class="btn btn--ghost btn--compact" data-lightbox-set="<?= e(json_encode(array_map(static fn ($img) => ['src' => media_url($img), 'caption' => $title], $images), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
            <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 16l5-5 4 4 3-3 6 6"/></g><circle fill="currentColor" cx="15.5" cy="9.5" r="1.5"/></svg>
            <span><?= e(t('play_gallery')) ?></span>
            <span class="btn__count"><?= count($images) ?></span>
          </button>
<?php endif; ?>
        </div>
<?php
};

$facts = static function (array $p): array {
    return array_filter([
        tr($p, 'age'),
        tr($p, 'duration'),
        $p['premiere'] ? t('program_premiere', format_date($p['premiere'])) : '',
    ]);
};

// Karusel: <ul> s položkami + šípky a bodky strán (tie zapne až JavaScript;
// bez neho sa karusel dá posúvať prstom alebo posuvníkom).
$carouselOpen = static function (string $modifier, string $label, string $trackAttrs = ''): void {
    ?>
    <div class="carousel carousel--<?= e($modifier) ?>" data-carousel role="region" aria-roledescription="<?= e(t('carousel')) ?>" aria-label="<?= e($label) ?>" data-page-label="<?= e(t('carousel_page')) ?>">
      <ul class="carousel__track"<?= $trackAttrs ?>>
<?php
};
$carouselClose = static function (): void {
    ?>
      </ul>
      <div class="carousel__nav" hidden>
        <button type="button" class="carousel__arrow" data-carousel-prev aria-label="<?= e(t('carousel_prev')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg></button>
        <div class="carousel__dots" role="group" aria-label="<?= e(t('carousel_pages')) ?>"></div>
        <p class="carousel__count" hidden></p>
        <button type="button" class="carousel__arrow" data-carousel-next aria-label="<?= e(t('carousel_next')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg></button>
      </div>
    </div>
<?php
};

// Ikona pri článku / reportáži bez obrázka.
$pressIcon = static function (string $kind): string {
    $paths = [
        'article' => '<rect x="3.5" y="4.5" width="17" height="15" rx="1.5"/><path d="M7 8.5h6M7 12h10M7 15.5h10M15.5 8.5h1.5"/>',
        'tv'      => '<rect x="3" y="6.5" width="18" height="12" rx="2"/><path d="M8.5 3.5l3.5 3 3.5-3M9 21h6"/>',
        'radio'   => '<rect x="3" y="8" width="18" height="12" rx="2"/><circle cx="15.5" cy="14" r="2.6"/><path d="M6.5 12h4M6.5 15.5h4M7 8l10-4.5"/>',
        'web'     => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.4 3.8 5.2 3.8 8.5s-1.2 6.1-3.8 8.5c-2.6-2.4-3.8-5.2-3.8-8.5S9.4 5.9 12 3.5z"/>',
        'other'   => '<path d="M12 3.5l2.4 5.6 6.1.5-4.6 4 1.4 5.9L12 16.4l-5.3 3.1 1.4-5.9-4.6-4 6.1-.5z"/>',
    ];

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$kind] ?? $paths['other']) . '</svg>';
};

// Článok / reportáž. Veľká položka ukáže dlhší text, v karuseli je skrátený;
// celý sa otvorí v okne. Odkaz na YouTube sa prehrá priamo na stránke.
$renderPress = static function (array $item, bool $big) use ($pressIcon): void {
    $id    = (int) $item['id'];
    $title = tr($item, 'title');
    $text  = tr($item, 'text');
    $short = excerpt($text, $big ? 600 : 150);
    $more  = $short !== text_flat($text);
    $video = $item['url'] ? video_info(['url' => $item['url'], 'file' => null, 'poster' => null]) : null;
    $kind  = (string) $item['kind'];
    $meta  = array_filter([t('kind_' . $kind), (string) $item['outlet'], $item['published_on'] ? format_date((string) $item['published_on']) : '']);
    $tag   = $big ? 'article' : 'li';

    $link = static function () use ($item, $video, $kind, $title): void {
        if ($video && $video['kind'] === 'youtube') { ?>
            <button type="button" class="btn btn--ghost btn--compact" data-player="<?= e(json_encode(['kind' => 'youtube', 'src' => $video['embed']], JSON_UNESCAPED_SLASHES)) ?>" data-title="<?= e($title) ?>">
              <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5.5v13l11-6.5z"/></svg>
              <span><?= e(t('press_open_tv')) ?></span>
            </button>
<?php   } elseif ($item['url']) { ?>
            <a class="btn btn--ghost btn--compact" href="<?= e($item['url']) ?>" target="_blank" rel="noopener">
              <span><?= e(t('press_open_' . $kind)) ?></span>
              <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
            </a>
<?php   }
    };
    ?>
    <<?= $tag ?> class="press<?= $big ? ' press--featured' : ' carousel__item' ?> cms-item<?= $item['is_public'] ? '' : ' is-hidden-item' ?>">
      <?= cms_controls('press', $id, 'cms-bar--corner', !$big, !$big) /* veľká položka je mimo poradia karusela */ ?>
      <div class="press__media press__media--<?= e($kind) ?>">
<?php if ($item['image']): ?>
        <button type="button" class="press__zoom" data-lightbox-single="<?= e(media_url($item['image'])) ?>" data-caption="<?= e($title) ?>" aria-label="<?= e(t('play_image_open')) ?>">
          <img src="<?= e(media_url($item['image'])) ?>" alt="" loading="lazy">
        </button>
<?php else: ?>
        <span class="press__icon"><?= $pressIcon($kind) ?></span>
<?php endif; ?>
      </div>
      <div class="press__body">
        <?= cms_flags(['hidden' => !$item['is_public']]) ?>
        <p class="press__meta"><?= e(implode(' · ', $meta)) ?></p>
        <h3 class="press__title"><?= e($title) ?></h3>
<?php if ($text !== ''): ?>
<?php if ($big && !$more): ?>
        <div class="prose press__text"><?= paragraphs($text) ?></div>
<?php else: ?>
        <p class="press__text"><?= e($short) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($more || $item['url']): ?>
        <div class="press__actions">
<?php if ($more): ?>
          <button type="button" class="btn btn--ghost btn--compact" data-sheet="sheet-press-<?= $id ?>" aria-haspopup="dialog"><?= e(t('press_read_more')) ?></button>
<?php endif; ?>
<?php $link(); ?>
        </div>
<?php endif; ?>
      </div>
<?php if ($more): ?>
      <template id="sheet-press-<?= $id ?>">
        <article class="sheet-press">
          <p class="press__meta"><?= e(implode(' · ', $meta)) ?></p>
          <h2 class="sheet__title" id="sheet-title"><?= e($title) ?></h2>
          <div class="prose"><?= paragraphs($text) ?></div>
<?php if ($item['url']): ?>
          <div class="press__actions">
<?php $link(); ?>
          </div>
<?php endif; ?>
        </article>
      </template>
<?php endif; ?>
    </<?= $tag ?>>
<?php
};

// ── Hlavičky, SEO ────────────────────────────────────────────────────────────

header('Content-Type: text/html; charset=UTF-8');
security_headers();
header('Cache-Control: ' . ($editor ? 'no-store' : 'no-cache, must-revalidate, max-age=0'));
if ($editor && site_mode() !== 'live') {
    header('X-Robots-Tag: noindex');
}

// Obrázok pri zdieľaní: plagát prvej zverejnenej položky „Práve hráme".
$ogImage = '';
foreach ($runs as $run) {
    if ($run['run_public'] && ($run['poster'] || $run['image'])) {
        $ogImage = $base . media_url($run['poster'] ?: $run['image']);
        break;
    }
}

$jsonLd = [[
    '@context'     => 'https://schema.org',
    '@type'        => 'PerformingGroup',
    'name'         => 'Divadielko Galéria',
    'url'          => $base . '/',
    'description'  => t('site_meta_desc'),
    'foundingDate' => (string) founded(),
    'address'      => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => contact('street'),
        'addressLocality' => 'Nové Mesto nad Váhom',
        'postalCode'      => '915 01',
        'addressCountry'  => 'SK',
    ],
    'telephone' => contact('phone_tel'),
    'email'     => contact('email'),
    'sameAs'    => array_values(array_filter([$links['facebook'] ?? '', $links['instagram'] ?? '', $links['youtube'] ?? '', link_to('msks')])),
]];
foreach ($upcoming as ['date' => $pf, 'play' => $play]) {
    $event = [
        '@context'            => 'https://schema.org',
        '@type'               => 'TheaterEvent',
        'name'                => tr($play, 'title'),
        'startDate'           => date('c', strtotime((string) $pf['starts_at'])),
        'eventStatus'         => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location'            => [
            '@type'   => 'Place',
            // miesto termínu → miesto položky „Práve hráme" → divadielko
            'name'    => tr($pf, 'venue') ?: (tr($play, 'venue') ?: 'Divadielko Galéria'),
            'address' => contact('street') . ', ' . contact('city'),
        ],
        'organizer' => ['@type' => 'PerformingGroup', 'name' => 'Divadielko Galéria', 'url' => $base . '/'],
        'url'       => $base . '/#domov',
    ];
    if ($play['poster'] || $play['image']) {
        $event['image'] = $base . media_url($play['poster'] ?: $play['image']);
    }
    // Vstupné je voľný text; do štruktúrovaných údajov ide, len keď sa z neho dá prečítať suma.
    if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', tr($play, 'price'), $m)) {
        $event['offers'] = [
            '@type'         => 'Offer',
            'price'         => str_replace(',', '.', $m[1]),
            'priceCurrency' => 'EUR',
            'availability'  => 'https://schema.org/InStock',
            'url'           => $base . '/#domov',
        ];
    }
    $jsonLd[] = $event;
}

// Poradie sekcií = poradie menu aj pätičky (administrácia → Sekcie a menu).
// V médiách a Repertoár návštevník (ani v menu) nevidí, kým nemajú obsah.
$visible = [
    'domov' => true, 'media' => $showPress, 'galeria' => true, 'subor' => true,
    'repertoar' => $repertoire !== [] || $editor, 'historia' => true, 'kontakt' => true,
];
$order = array_values(array_filter(section_order(), static fn (string $key): bool => $visible[$key] ?? false));
$nav = [];
foreach ($order as $key) {
    $nav[$key] = section_label($key);
}
?>
<!DOCTYPE html>
<html lang="<?= e(t('html_lang')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('site_title')) ?></title>
<meta name="description" content="<?= e(t('site_meta_desc')) ?>">
<meta name="theme-color" content="#14090f">
<link rel="canonical" href="<?= e($base) ?>/<?= $lang !== config('default_lang') ? '?lang=' . e($lang) : '' ?>">
<link rel="alternate" hreflang="sk" href="<?= e($base) ?>/?lang=sk">
<link rel="alternate" hreflang="en" href="<?= e($base) ?>/?lang=en">
<link rel="alternate" hreflang="x-default" href="<?= e($base) ?>/">
<link rel="icon" href="/static/img/favicon.svg" type="image/svg+xml">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Divadielko Galéria">
<meta property="og:locale" content="<?= e(t('og_locale')) ?>">
<meta property="og:title" content="<?= e(t('site_title')) ?>">
<meta property="og:description" content="<?= e(t('site_meta_desc')) ?>">
<meta property="og:url" content="<?= e($base) ?>/">
<?php if ($ogImage !== ''): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage !== '' ? 'summary_large_image' : 'summary' ?>">
<link rel="stylesheet" href="<?= e(asset_version('static/css/site.css')) ?>">
<noscript><style>.topnav__toggle { display: none !important; } .topnav__list { display: flex !important; position: static; flex-direction: row; flex-wrap: wrap; padding: 0; background: none; border: 0; box-shadow: none; }</style></noscript>
<?php if ($editor): ?>
<link rel="stylesheet" href="<?= e(asset_version('static/css/cms.css')) ?>">
<?php endif; ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="<?= e(asset_version('static/js/site.js')) ?>" defer></script>
<?php if (analytics_enabled()): $a = config('analytics'); ?>
<script async defer src="<?= e($a['src']) ?>" data-website-id="<?= e($a['website_id']) ?>"></script>
<?php endif; ?>
</head>
<body class="site<?= $editor ? ' has-cms' : '' ?>">

<a class="skip-link" href="#obsah"><?= e(t('skip_link')) ?></a>

<div class="valance" aria-hidden="true">
  <svg viewBox="0 0 1200 90" preserveAspectRatio="none" focusable="false">
    <path class="valance__cloth" d="M0,0 H1200 V34 C1140,80 1080,80 1020,34 C960,80 900,80 840,34 C780,80 720,80 660,34 C600,80 540,80 480,34 C420,80 360,80 300,34 C240,80 180,80 120,34 C90,57 60,64 30,55 L0,44 Z"/>
    <path class="valance__trim" d="M1200,34 C1140,80 1080,80 1020,34 C960,80 900,80 840,34 C780,80 720,80 660,34 C600,80 540,80 480,34 C420,80 360,80 300,34 C240,80 180,80 120,34 C90,57 60,64 30,55 L0,44"/>
  </svg>
</div>

<header class="topbar">
  <div class="topbar__inner">
    <a class="topbar__brand" href="#domov">
      <img src="/static/img/favicon.svg" alt="" width="30" height="30">
      <span><?= e(t('brand')) ?></span>
    </a>

    <nav class="topnav" aria-label="<?= e(t('nav_label')) ?>">
      <button class="topnav__toggle" type="button" aria-expanded="false" aria-controls="topnav-list">
        <span class="topnav__burger" aria-hidden="true"></span>
        <span class="topnav__label"><?= e(t('nav_menu')) ?></span>
      </button>
      <ul class="topnav__list" id="topnav-list">
<?php foreach ($nav as $id => $label): ?>
        <li><a href="#<?= e($id) ?>" data-nav="<?= e($id) ?>"><?= e($label) ?></a></li>
<?php endforeach; ?>
      </ul>
    </nav>

    <div class="langbar" role="navigation" aria-label="<?= e(t('lang_switch')) ?>">
<?php foreach (config('languages') as $code): ?>
      <a class="langbar__link<?= $code === $lang ? ' is-current' : '' ?>" href="<?= e(lang_url($code)) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
<?php endforeach; ?>
    </div>
  </div>
</header>

<main id="obsah">

<?php
// Každá sekcia sa vykreslí do vlastného bufferu a potom sa vypíšu v poradí z administrácie.
$html = [];
ob_start();
?>
<!-- ═══ DOMOV: čo a kedy hráme ═══════════════════════════════════════════ -->
<section class="section hero" id="domov" aria-labelledby="hero-title">
  <div class="wrap">
    <div class="hero__intro cms-zone">
<?php if (($badge = setting_tr('hero_badge')) !== ''): ?>
      <p class="eyebrow"><?= e($badge) ?></p>
<?php endif; ?>
      <h1 class="hero__title" id="hero-title"><?= e(setting_label('hero_title')) ?></h1>
      <div class="hero__tagline"><?= paragraphs(setting_tr('tagline')) ?></div>
      <?= cms_settings('intro') ?>
    </div>

<?php if ($runs): ?>
    <!-- Práve hráme: položky s inscenáciou z repertoáru a vlastnými termínmi; plagát sa strieda vľavo / vpravo -->
<?php foreach ($runs as $i => $play): $rid = (int) $play['run_id']; $pid = (int) $play['id']; $dates = $datesOf[$rid] ?? []; ?>
<?php if ($i > 0): ?>
    <div class="rule rule--between" aria-hidden="true"><span></span>&#10022;<span></span></div>
<?php endif; ?>
    <article class="feature<?= $i % 2 ? ' feature--flip' : '' ?><?= $play['run_public'] ? '' : ' is-hidden-item' ?> cms-item" aria-labelledby="feature-title-<?= $rid ?>">
      <?= cms_controls('runs', $rid, 'cms-bar--corner') ?>
      <figure class="poster">
<?php if ($banner = $play['poster'] ?: $play['image']): // vlastný plagát, inak obrázok z repertoáru ?>
        <button type="button" class="poster__frame" data-lightbox-single="<?= e(media_url($banner)) ?>" data-caption="<?= e(tr($play, 'title')) ?>" aria-label="<?= e(t('program_poster_open')) ?>">
          <img src="<?= e(media_url($banner)) ?>" alt="<?= e(t('program_poster_alt', tr($play, 'title'))) ?>"<?= $i === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>>
        </button>
<?php else: ?>
        <div class="poster__frame poster__frame--empty" aria-hidden="true">
          <span><?= e(tr($play, 'title')) ?></span>
        </div>
<?php endif; ?>
      </figure>

      <div class="feature__body">
        <?= cms_flags(['hidden' => !$play['run_public']]) ?>
        <p class="eyebrow"><?= e(t('program_now')) ?></p>
        <h2 class="feature__title" id="feature-title-<?= $rid ?>"><?= e(tr($play, 'title')) ?></h2>
        <?= cms_edit_link('productions', $pid, 'cms_edit_production') ?>
<?php if (tr($play, 'subtitle') !== ''): ?>
        <p class="feature__subtitle"><?= e(tr($play, 'subtitle')) ?></p>
<?php endif; ?>
<?php if (($f = $facts($play)) || $price($play) !== ''): ?>
        <ul class="facts">
<?php if ($price($play) !== ''): ?>
          <li><?= e($price($play)) ?></li>
<?php endif; ?>
<?php foreach ($f as $fact): ?>
          <li><?= e($fact) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <div class="prose"><?= paragraphs(tr($play, 'description')) ?></div>
<?php $renderPlayActions($play); ?>

        <h3 class="subhead"><?= e(t('program_this')) ?></h3>
<?php if (tr($play, 'venue') !== ''): // kde sa hrá — pri termíne sa zobrazí len iné miesto ?>
        <p class="run-venue">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21s-6.5-6.1-6.5-11A6.5 6.5 0 0 1 18.5 10c0 4.9-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/></svg>
          <span><span class="visually-hidden"><?= e(t('where_label')) ?>: </span><?= e(tr($play, 'venue')) ?></span>
        </p>
<?php endif; ?>
<?php if ($dates): ?>
        <ul class="dates">
<?php foreach ($dates as $pf) { $renderDate($pf); } ?>
        </ul>
<?php else: ?>
        <p class="muted"><?= e(t('program_no_dates')) ?></p>
<?php endif; ?>
        <?= cms_add('performances', 'cms_add_performance', ['run_id' => $rid]) ?>
      </div>
    </article>
<?php endforeach; ?>
    <?= cms_add('runs', 'cms_add_now_playing') ?>
<?php else: ?>
    <div class="feature feature--empty cms-zone">
      <div class="prose prose--center"><?= paragraphs(setting_tr('program_empty')) ?></div>
      <?= cms_settings('program') ?>
    </div>
    <?= cms_add('runs', 'cms_add_now_playing') ?>
<?php endif; ?>

<?php $renderTicketsNote($upcoming !== []); ?>

  </div>
</section>
<?php $html['domov'] = ob_get_clean(); ob_start(); ?>
<?php if ($showPress): ?>
<!-- ═══ V MÉDIÁCH ═════════════════════════════════════════════════════════ -->
<section class="section" id="media" aria-labelledby="media-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="media-title"><?= e(setting_label('media_title')) ?></h2>
      <div class="section__intro"><?= paragraphs(setting_tr('media_intro')) ?></div>
      <?= cms_settings('media') ?>
    </header>

<?php if ($pressFeatured): $renderPress($pressFeatured, true); endif; ?>
<?php if ($pressRest): ?>
<?php if ($pressFeatured): ?>
    <h3 class="subhead subhead--rule"><?= e(t('media_more')) ?></h3>
<?php endif; ?>
<?php $carouselOpen('media', t('media_more')); ?>
<?php foreach ($pressRest as $item) { $renderPress($item, false); } ?>
<?php $carouselClose(); ?>
<?php elseif (!$pressFeatured): ?>
    <p class="muted center"><?= e(t('media_empty')) ?></p>
<?php endif; ?>
    <?= cms_add('press', 'cms_add_press') ?>
  </div>
</section>
<?php endif; ?>
<?php $html['media'] = ob_get_clean(); ob_start(); ?>
<!-- ═══ GALÉRIA ═══════════════════════════════════════════════════════════ -->
<section class="section" id="galeria" aria-labelledby="galeria-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="galeria-title"><?= e(setting_label('gallery_title')) ?></h2>
      <div class="section__intro"><?= paragraphs(setting_tr('gallery_intro')) ?></div>
      <?= cms_settings('gallery') ?>
    </header>

<?php if ($photos): ?>
<?php $carouselOpen('photos', t('gallery_photos'), ' data-lightbox-group'); ?>
<?php foreach ($photos as $ph): ?>
        <li class="carousel__item photo cms-item">
          <?= cms_controls('photos', (int) $ph['id'], 'cms-bar--corner', true) ?>
          <button type="button" class="photo__open" data-lightbox="<?= e(media_url($ph['image'])) ?>" data-caption="<?= e(tr($ph, 'caption')) ?>">
            <img src="<?= e(media_url($ph['image'])) ?>" alt="<?= e(tr($ph, 'caption')) ?>" loading="lazy">
          </button>
        </li>
<?php endforeach; ?>
<?php $carouselClose(); ?>
<?php elseif (!$videos): ?>
    <p class="muted center"><?= e(t('gallery_empty')) ?></p>
<?php endif; ?>
    <?= cms_add('photos', 'cms_add_photo') ?>

<?php if ($videos || $editor): ?>
    <h3 class="subhead subhead--rule"><?= e(t('gallery_videos')) ?></h3>
<?php if ($videos): ?>
<?php $carouselOpen('videos', t('gallery_videos'), ' data-player-group'); ?>
<?php foreach ($videos as $v): $info = video_info($v); if (!$info) { continue; } $title = tr($v, 'title'); $label = $title !== '' ? $title : t('video_untitled'); ?>
        <li class="carousel__item video cms-item">
          <?= cms_controls('videos', (int) $v['id'], 'cms-bar--corner', true) ?>
<?php if ($info['kind'] === 'youtube' || $info['kind'] === 'file'): // klik → väčšie okno, video sa spustí; šípkami na ďalšie ?>
          <button type="button" class="video__frame video__open" data-player="<?= e(json_encode(['kind' => $info['kind'], 'src' => $info['kind'] === 'youtube' ? $info['embed'] : $info['src']], JSON_UNESCAPED_SLASHES)) ?>" data-title="<?= e($label) ?>" aria-label="<?= e(t('video_play', $label)) ?>">
<?php if ($info['poster']): ?>
            <img src="<?= e($info['poster']) ?>" alt="" loading="lazy">
<?php else: // bez náhľadu: záber priamo z videa (načíta sa, až keď je video na obrazovke) ?>
            <video class="video__still" data-src="<?= e($info['src']) ?>#t=0.5" preload="none" muted playsinline tabindex="-1" aria-hidden="true"></video>
<?php endif; ?>
            <span class="video__icon" aria-hidden="true"></span>
          </button>
<?php if ($title !== '' || $info['kind'] === 'youtube'): ?>
          <p class="video__title"><?= e($title) ?><?php if ($info['kind'] === 'youtube'): ?> <a class="video__ext" href="<?= e($info['href']) ?>" target="_blank" rel="noopener"><?= e(t('video_youtube')) ?></a><?php endif; ?></p>
<?php endif; ?>
<?php else: ?>
          <a class="video__frame video__frame--link video__frame--<?= e($info['kind']) ?>" href="<?= e($info['href']) ?>" target="_blank" rel="noopener">
<?php if ($info['poster']): ?>
            <img src="<?= e($info['poster']) ?>" alt="" loading="lazy">
<?php endif; ?>
            <span class="video__icon" aria-hidden="true"></span>
            <span class="video__badge"><?= e(t($info['kind'] === 'instagram' ? 'video_instagram' : 'video_link')) ?></span>
          </a>
<?php if ($title !== ''): ?>
          <p class="video__title"><?= e($title) ?></p>
<?php endif; ?>
<?php endif; ?>
        </li>
<?php endforeach; ?>
<?php $carouselClose(); ?>
<?php endif; ?>
    <?= cms_add('videos', 'cms_add_video') ?>
<?php endif; ?>
  </div>
</section>
<?php $html['galeria'] = ob_get_clean(); ob_start(); ?>
<!-- ═══ SÚBOR ═════════════════════════════════════════════════════════════ -->
<section class="section" id="subor" aria-labelledby="subor-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="subor-title"><?= e(setting_label('ensemble_title')) ?></h2>
      <div class="section__intro"><?= paragraphs(setting_tr('ensemble_intro')) ?></div>
      <?= cms_settings('ensemble') ?>
    </header>

<?php if ($members || $former): ?>
<?php if ($members): ?>
    <!-- karty ako strana s obsadením v divadelnom bulletine — v každej traja pod sebou (úloha, meno, od kedy, pár slov) -->
<?php $carouselOpen('cast', setting_label('ensemble_title')); ?>
<?php foreach (array_chunk($members, 3) as $group): ?>
        <li class="carousel__item cast-card">
<?php foreach ($group as $m): $mid = (int) $m['id']; $bio = tr($m, 'bio'); ?>
          <div class="cast__member cms-item<?= $m['active'] ? '' : ' is-archived' ?>">
            <?= cms_controls('members', $mid, 'cms-bar--corner') ?>
            <h3 class="cast__name"><?= e($m['name']) ?></h3>
<?php if (tr($m, 'role') !== ''): ?>
            <p class="cast__role"><?= e(tr($m, 'role')) ?></p>
<?php endif; ?>
<?php if ($m['since_year']): ?>
            <p class="cast__since"><?= e(t('ensemble_since', (int) $m['since_year'])) ?></p>
<?php endif; ?>
<?php if ($bio !== ''): // krátke pár slov priamo v karte, dlhší text v okne (karty ostanú rovnako nízke) ?>
<?php if (mb_strlen(text_flat($bio)) <= 90): ?>
            <p class="cast__bio"><?= e(text_flat($bio)) ?></p>
<?php else: ?>
            <button type="button" class="cast__more" data-sheet="sheet-member-<?= $mid ?>" aria-haspopup="dialog"><?= e(t('member_more')) ?><span class="visually-hidden"> — <?= e($m['name']) ?></span></button>
            <template id="sheet-member-<?= $mid ?>">
              <article class="sheet-member">
<?php if (tr($m, 'role') !== ''): ?>
                <p class="cast__role"><?= e(tr($m, 'role')) ?></p>
<?php endif; ?>
                <h2 class="sheet__title" id="sheet-title"><?= e($m['name']) ?></h2>
<?php if ($m['since_year']): ?>
                <p class="cast__since"><?= e(t('ensemble_since', (int) $m['since_year'])) ?></p>
<?php endif; ?>
                <div class="prose"><?= paragraphs($bio) ?></div>
              </article>
            </template>
<?php endif; ?>
<?php endif; ?>
          </div>
<?php endforeach; ?>
        </li>
<?php endforeach; ?>
<?php $carouselClose(); ?>
<?php endif; ?>
<?php if ($former): ?>
    <p class="cast__former"><span class="cast__former-label"><?= e(t('ensemble_former')) ?></span> <?= e(implode(' · ', array_column($former, 'name'))) ?></p>
<?php endif; ?>
<?php else: ?>
    <p class="muted center"><?= e(t('ensemble_empty')) ?></p>
<?php endif; ?>
    <?= cms_add('members', 'cms_add_member') ?>
  </div>
</section>
<?php $html['subor'] = ob_get_clean(); ob_start(); ?>
<?php if ($repertoire || $editor): ?>
<!-- ═══ REPERTOÁR: karty s obrázkom, údajmi a začiatkom popisu; celé (popis, ukážka, galéria) v okne ═══ -->
<section class="section" id="repertoar" aria-labelledby="repertoar-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="repertoar-title"><?= e(setting_label('repertoire_title')) ?></h2>
      <div class="section__intro"><?= paragraphs(setting_tr('repertoire_intro')) ?></div>
      <?= cms_settings('repertoire') ?>
    </header>

<?php if ($repertoire): ?>
<?php $carouselOpen('plays', setting_label('repertoire_title')); ?>
<?php foreach ($repertoire as $p): $pid = (int) $p['id']; $title = tr($p, 'title'); $images = play_images($p); ?>
        <li class="carousel__item play-card cms-item<?= $p['is_retired'] ? ' is-retired' : '' ?><?= $p['is_public'] ? '' : ' is-hidden-item' ?>">
          <div class="play-card__media">
<?php if ($p['image']): ?>
            <img src="<?= e(media_url($p['image'])) ?>" alt="" loading="lazy">
<?php else: ?>
            <span class="play-card__placeholder" aria-hidden="true">&#10022;</span>
<?php endif; ?>
            <?= cms_flags(['hidden' => !$p['is_public'], 'retired' => (bool) $p['is_retired']]) ?>
          </div>
          <div class="play-card__body">
            <h3 class="play-card__title">
              <button type="button" class="play-card__open" data-sheet="sheet-play-<?= $pid ?>" aria-haspopup="dialog"><?= e($title) ?></button>
            </h3>
<?php if ($f = $facts($p)): ?>
            <p class="play-card__facts"><?= e(implode(' · ', array_slice($f, 0, 2))) ?></p>
<?php endif; ?>
<?php if (($desc = excerpt(tr($p, 'description'), 95)) !== ''): ?>
            <p class="play-card__excerpt"><?= e($desc) ?></p>
<?php endif; ?>
            <span class="play-card__more" aria-hidden="true"><?= e(t('play_details')) ?></span>
          </div>
          <?= cms_controls('productions', $pid, 'cms-bar--corner', true) ?>
          <template id="sheet-play-<?= $pid ?>">
            <article class="sheet-play<?= $p['is_retired'] ? ' is-retired' : '' ?>">
              <div class="sheet-play__media">
<?php if ($p['image']): ?>
                <button type="button" class="sheet-play__image" data-lightbox-single="<?= e(media_url($p['image'])) ?>" data-caption="<?= e($title) ?>" aria-label="<?= e(t('play_image_open')) ?>">
                  <img src="<?= e(media_url($p['image'])) ?>" alt="<?= e($title) ?>">
                </button>
<?php else: ?>
                <span class="play-card__placeholder" aria-hidden="true">&#10022;</span>
<?php endif; ?>
              </div>
              <div class="sheet-play__body">
                <?= cms_flags(['hidden' => !$p['is_public'], 'retired' => (bool) $p['is_retired']]) ?>
                <h2 class="sheet__title" id="sheet-title"><?= e($title) ?></h2>
                <?= cms_edit_link('productions', $pid, 'cms_edit_production') ?>
<?php if (tr($p, 'subtitle') !== ''): ?>
                <p class="feature__subtitle"><?= e(tr($p, 'subtitle')) ?></p>
<?php endif; ?>
<?php if ($f): ?>
                <ul class="facts facts--small">
<?php foreach ($f as $fact): ?>
                  <li><?= e($fact) ?></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
                <div class="prose"><?= paragraphs(tr($p, 'description')) ?></div>
<?php $renderPlayActions($p, false); ?>
              </div>
<?php if ($images): ?>
              <div class="sheet-play__gallery">
                <h3 class="subhead"><?= e(t('play_gallery')) ?> <span class="btn__count"><?= count($images) ?></span></h3>
                <ul class="sheet-thumbs" data-lightbox-group>
<?php foreach ($images as $img): ?>
                  <li><button type="button" class="sheet-thumbs__open" data-lightbox="<?= e(media_url($img)) ?>" data-caption="<?= e($title) ?>"><img src="<?= e(media_url($img)) ?>" alt="" loading="lazy"></button></li>
<?php endforeach; ?>
                </ul>
              </div>
<?php endif; ?>
            </article>
          </template>
        </li>
<?php endforeach; ?>
<?php $carouselClose(); ?>
<?php endif; ?>
    <?= cms_add('productions', 'cms_add_production') ?>
  </div>
</section>
<?php endif; ?>
<?php $html['repertoar'] = ob_get_clean(); ob_start(); ?>
<!-- ═══ HISTÓRIA ══════════════════════════════════════════════════════════ -->
<?php
// Prehľad po rokoch je predvolený; „Celá história" (vlastné texty) je druhá záložka.
// Keď je len jedno z nich, záložky sa nezobrazia. Prihlásený vidí vždy obe.
$tabs = $editor || ($summary && $history);
?>
<section class="section" id="historia" aria-labelledby="historia-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="historia-title"><?= e(setting_label('history_title')) ?></h2>
      <div class="section__intro"><?= paragraphs(setting_tr('history_intro')) ?></div>
      <?= cms_settings('history') ?>
    </header>

<?php if ($tabs): ?>
    <div class="history-tabs" data-tabs>
      <div class="tabs" role="tablist" aria-label="<?= e(setting_label('history_title')) ?>" hidden>
        <button type="button" class="tabs__tab" role="tab" id="tab-history-summary" aria-controls="history-summary" aria-selected="true"><?= e(t('history_tab_summary')) ?></button>
        <button type="button" class="tabs__tab" role="tab" id="tab-history-full" aria-controls="history-full" aria-selected="false" tabindex="-1"><?= e(t('history_tab_full')) ?></button>
      </div>
<?php endif; ?>

<?php if ($tabs || $summary): ?>
      <div class="history-panel" id="history-summary"<?= $tabs ? ' role="tabpanel" aria-labelledby="tab-history-summary"' : '' ?>>
<?php if ($summary): ?>
        <!-- čo a kde sme hrali, rok = karta (najnovší prvý): roky z „Práve hráme" sa dopĺňajú samy, staršie sú zadané ručne -->
<?php $carouselOpen('years', t('history_tab_summary')); ?>
<?php foreach ($summary as $year => $plays): ?>
          <li class="carousel__item chronicle__year">
            <h3 class="chronicle__label"><?= (int) $year ?></h3>
            <ul class="chronicle__plays">
<?php foreach ($plays as $row): ?>
              <li class="chronicle__item">
                <span class="chronicle__play"><?= e($row['title']) ?></span>
<?php if ($row['places']): ?>
                <span class="chronicle__places"><?= e(implode(' · ', $row['places'])) ?></span>
<?php endif; ?>
<?php foreach ($row['manual'] as $manualId): ?>
                <?= cms_controls('history_plays', $manualId, 'cms-bar--inline') ?>
<?php endforeach; ?>
              </li>
<?php endforeach; ?>
            </ul>
          </li>
<?php endforeach; ?>
<?php $carouselClose(); ?>
<?php else: ?>
        <p class="muted center"><?= e(t('history_summary_empty')) ?></p>
<?php endif; ?>
<?php if ($editor): ?>
        <p class="cms-note"><?= e(t('history_summary_note')) ?></p>
<?php endif; ?>
        <?= cms_add('history_plays', 'cms_add_history_play') ?>
      </div>
<?php endif; ?>

<?php if ($tabs || !$summary): ?>
      <div class="history-panel" id="history-full"<?= $tabs ? ' role="tabpanel" aria-labelledby="tab-history-full"' : '' ?>>
<?php if ($history): ?>
        <ol class="timeline">
<?php foreach ($history as $h): ?>
          <li class="timeline__item cms-item">
            <?= cms_controls('history', (int) $h['id'], 'cms-bar--corner') ?>
            <p class="timeline__year"><?= e((string) $h['year']) ?></p>
            <div class="timeline__card">
              <h3 class="timeline__title"><?= e(tr($h, 'title')) ?></h3>
<?php if (tr($h, 'text') !== ''): ?>
              <div class="prose"><?= paragraphs(tr($h, 'text')) ?></div>
<?php endif; ?>
<?php if ($h['image']): ?>
              <button type="button" class="timeline__image" data-lightbox-single="<?= e(media_url($h['image'])) ?>" data-caption="<?= e($h['year'] . ' — ' . tr($h, 'title')) ?>">
                <img src="<?= e(media_url($h['image'])) ?>" alt="" loading="lazy">
              </button>
<?php endif; ?>
            </div>
          </li>
<?php endforeach; ?>
        </ol>
<?php else: ?>
        <p class="muted center"><?= e(t('history_empty')) ?></p>
<?php endif; ?>
        <?= cms_add('history', 'cms_add_history') ?>
      </div>
<?php endif; ?>

<?php if ($tabs): ?>
    </div>
<?php endif; ?>
  </div>
</section>
<?php $html['historia'] = ob_get_clean(); ob_start(); ?>
<!-- ═══ KONTAKT ═══════════════════════════════════════════════════════════ -->
<section class="section" id="kontakt" aria-labelledby="kontakt-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="kontakt-title"><?= e(setting_label('contact_title')) ?></h2>
      <div class="section__intro"><?= paragraphs(setting_tr('contact_intro')) ?></div>
      <?= cms_settings('contact_head') ?>
    </header>

    <div class="contact-grid">
      <div class="contact-info cms-zone">
        <?= cms_settings('contact') ?>
        <dl class="contact-info__list">
          <div>
            <dt><?= e(t('phone_label')) ?></dt>
            <dd><a href="tel:<?= e(contact('phone_tel')) ?>"><?= e(contact('phone_display')) ?></a></dd>
          </div>
          <div>
            <dt><?= e(t('email_label')) ?></dt>
            <dd><a href="mailto:<?= e(contact('email')) ?>"><?= e(contact('email')) ?></a></dd>
          </div>
          <div>
            <dt><?= e(t('where_label')) ?></dt>
            <dd>
              <?= e(contact('street')) ?><br><?= e(contact('city')) ?>
              <br><a class="contact-info__map" href="<?= e($mapUrl) ?>" target="_blank" rel="noopener"><?= e(t('map_link')) ?></a>
            </dd>
          </div>
        </dl>

        <div class="contact-info__social cms-zone">
          <p class="subhead"><?= e(t('social_head')) ?></p>
          <div class="social">
<?php foreach ($links as $network => $href): ?>
            <a class="btn btn--ghost" href="<?= e($href) ?>" target="_blank" rel="noopener">
              <?php require __DIR__ . '/partials/icon-' . $network . '.php'; ?>
              <span><?= e(t(['facebook' => 'cta_fb', 'instagram' => 'cta_ig', 'youtube' => 'cta_yt'][$network])) ?></span>
            </a>
<?php endforeach; ?>
<?php if (link_to('msks') !== ''): ?>
            <a class="btn btn--ghost" href="<?= e(link_to('msks')) ?>" target="_blank" rel="noopener">
              <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 20h16v-9l-8-5.5L4 11v9zm6-2v-5h4v5h-4z"/></svg>
              <span>msks.sk</span>
            </a>
<?php endif; ?>
          </div>
          <?= cms_settings('links') ?>
        </div>
      </div>

      <form class="contact-form" method="post" action="/api.php?action=contact" data-contact-form novalidate>
        <h3 class="contact-form__title"><?= e(t('cf_head')) ?></h3>
        <input type="hidden" name="token" value="<?= e(contact_form_token()) ?>">
        <input type="hidden" name="lang" value="<?= e($lang) ?>">
        <div class="hp" aria-hidden="true">
          <label for="cf-website"><?= e(t('cf_honeypot')) ?></label>
          <input type="text" id="cf-website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <div class="field-row">
          <div class="field">
            <label for="cf-name"><?= e(t('cf_name')) ?></label>
            <input type="text" id="cf-name" name="name" required maxlength="120" autocomplete="name">
          </div>
          <div class="field">
            <label for="cf-email"><?= e(t('cf_email')) ?></label>
            <input type="email" id="cf-email" name="email" required maxlength="255" autocomplete="email">
          </div>
        </div>
        <div class="field">
          <label for="cf-subject"><?= e(t('cf_subject')) ?> <span class="field__opt">(<?= e(t('cf_optional')) ?>)</span></label>
          <input type="text" id="cf-subject" name="subject" maxlength="200">
        </div>
        <div class="field">
          <label for="cf-message"><?= e(t('cf_message')) ?></label>
          <textarea id="cf-message" name="message" rows="6" required maxlength="5000"></textarea>
        </div>
        <p class="contact-form__privacy"><?= e(t('cf_privacy')) ?></p>
        <div class="contact-form__foot">
          <button type="submit" class="btn btn--primary" data-sending="<?= e(t('cf_sending')) ?>"><?= e(t('cf_send')) ?></button>
          <p class="contact-form__status<?= $cfStatus === 'ok' ? ' is-ok' : ($cfStatus !== '' ? ' is-error' : '') ?>" role="status" aria-live="polite"><?php
            if ($cfStatus === 'ok') {
                echo e(t('cf_ok'));
            } elseif (preg_match('/^cf_err_[a-z]+$/', $cfStatus)) {
                echo e(t($cfStatus));
            }
          ?></p>
        </div>
      </form>
    </div>
  </div>
</section>
<?php $html['kontakt'] = ob_get_clean();

foreach ($order as $i => $key) {
    if ($i > 0) {
        echo "\n", '<div class="rule" aria-hidden="true"><span></span>&#10022;<span></span></div>', "\n\n";
    }
    echo $html[$key];
}
?>

</main>

<footer class="site-foot">
  <div class="wrap site-foot__inner">
    <div class="site-foot__brand">
      <img src="/static/img/favicon.svg" alt="" width="40" height="40">
      <div>
        <p class="site-foot__name"><?= e(t('brand')) ?></p>
<?php if (($badge = setting_tr('hero_badge')) !== ''): ?>
        <p class="site-foot__sub"><?= e($badge) ?></p>
<?php endif; ?>
      </div>
    </div>
    <nav class="site-foot__nav" aria-label="<?= e(t('nav_label')) ?>">
<?php foreach ($nav as $id => $label): ?>
      <a href="#<?= e($id) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
    </nav>
    <p class="site-foot__copy">&copy; <?= date('Y') ?> <?= e(t('footer')) ?></p>
    <p class="site-foot__credit"><?= sprintf(e(t('footer_credit')), '<a href="https://codehero.sk/" target="_blank" rel="noopener">CodeHero</a>') ?></p>
  </div>
</footer>

<!-- Okno s podrobnosťami (inscenácia z repertoáru, člen súboru, článok): obsah sa vloží zo <template> pri položke -->
<div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-title" hidden>
  <div class="sheet__panel">
    <button type="button" class="lightbox__btn sheet__close" data-sheet-close aria-label="<?= e(t('lb_close')) ?>">&times;</button>
    <div class="sheet__content"></div>
  </div>
</div>

<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="<?= e(t('lb_label')) ?>" hidden>
  <figure class="lightbox__figure">
    <img class="lightbox__img" alt="">
    <figcaption class="lightbox__caption"></figcaption>
    <p class="lightbox__count" aria-live="polite"></p>
  </figure>
  <button type="button" class="lightbox__btn lightbox__close" data-lb="close" aria-label="<?= e(t('lb_close')) ?>">&times;</button>
  <button type="button" class="lightbox__btn lightbox__prev" data-lb="prev" aria-label="<?= e(t('lb_prev')) ?>">&#8249;</button>
  <button type="button" class="lightbox__btn lightbox__next" data-lb="next" aria-label="<?= e(t('lb_next')) ?>">&#8250;</button>
</div>

<!-- Video vo väčšom okne (galéria, ukážka z inscenácie, reportáž): vloží sa až po kliknutí,
     pri zatvorení alebo prepnutí sa zastaví; vo videách z galérie sa dá listovať šípkami -->
<div class="lightbox player" id="player" role="dialog" aria-modal="true" aria-label="<?= e(t('player_label')) ?>" hidden>
  <div class="player__frame"></div>
  <p class="lightbox__caption player__title"></p>
  <p class="lightbox__count player__count" aria-live="polite" hidden></p>
  <button type="button" class="lightbox__btn lightbox__close" data-player-close aria-label="<?= e(t('lb_close')) ?>">&times;</button>
  <button type="button" class="lightbox__btn lightbox__prev" data-player-prev aria-label="<?= e(t('player_prev')) ?>">&#8249;</button>
  <button type="button" class="lightbox__btn lightbox__next" data-player-next aria-label="<?= e(t('player_next')) ?>">&#8250;</button>
</div>

<?php if ($editor): require __DIR__ . '/partials/adminbar.php'; endif; ?>
</body>
</html>
