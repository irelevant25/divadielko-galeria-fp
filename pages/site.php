<?php
/**
 * Ostrá stránka — jedna stránka so sekciami (poradie okrem Domova a názvy v menu
 * sa menia v administrácii → Sekcie a menu):
 *   #domov     úvod, čo a kedy hráme (plagát + termíny)
 *   #onas      o nás — dlhší text o súbore (administrácia → ceružka pri nadpise)
 *   #galeria   fotografie a videá (karusely)
 *   #subor     súbor po skupinách (záverečné titulky ako vo filme: úloha → mená)
 *   #repertoar inscenácie (karusel, podrobnosti v okne)
 *   #historia  časová os po stranách rokov (rok → záznamy s dátumom, miestom, textom; obsadenie v okne)
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

$base   = base_url();
$editor = cms_on();

// ── Údaje ────────────────────────────────────────────────────────────────────

// „Práve hráme": položky s inscenáciou z repertoáru a vlastnými termínmi (aj odohranými — tie sú sivé).
['runs' => $runs, 'dates' => $datesOf, 'upcoming' => $upcoming] = now_playing($editor);

// Repertoár: verejnosť vidí zverejnené inscenácie (tie, čo už nehráme, sivo), prihlásený všetky.
$repertoire = list_entity('productions', $editor ? '' : 'is_public');

// Súbor: skupiny (úlohy) a ich ľudia. Prázdnu skupinu vidí len prihlásený.
$ensemble = [];
foreach (list_entity('ensemble_groups') as $group) {
    $group['people'] = json_decode((string) $group['people'], true) ?: [];
    if ($group['people'] || $editor) {
        $ensemble[] = $group;
    }
}

$photos = list_entity('photos');
$videos = list_entity('videos');

// O nás: text z editora (vyčistené HTML). Prázdny — sekciu vidí len prihlásený.
$aboutText = setting_tr('about_text');
$showAbout = $editor || $aboutText !== '';

// História: časová os — najnovší rok hore, alebo najstarší (nastavenie sekcie), po stranách
// po $historyLimit rokov (0 = všetky na jednej strane). Skryté inscenácie len prihlásenému.
$historyOrder = setting('history_order') === 'asc' ? 'asc' : 'desc';
$historyLimit = max(0, (int) setting('history_limit', '5'));
$historyYears = history_years($editor, $historyOrder);

$links = array_filter([
    'facebook'  => link_to('facebook'),
    'instagram' => link_to('instagram'),
    'youtube'   => link_to('youtube'),
]);

$mapUrl = setting('map_url', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(contact('street') . ', ' . contact('city')));

$cfStatus = isset($_GET['cf']) && is_string($_GET['cf']) ? $_GET['cf'] : '';

// ── Pomocníci na vykreslenie ─────────────────────────────────────────────────

// „Práve hráme" (termín, veta o vstupenkách, štítky, tlačidlá Ukážka / Galéria) — spoločné
// s dočasnými stránkami: $renderDate, $renderTicketsNote, $price, $facts, $renderPlayActions.
require __DIR__ . '/partials/program-helpers.php';

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
// O nás a Repertoár návštevník (ani v menu) nevidí, kým nemajú obsah.
$visible = [
    'domov' => true, 'onas' => $showAbout, 'galeria' => true, 'subor' => true,
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
<link rel="canonical" href="<?= e($base) ?>/">
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
<noscript><style>.timeline__item[data-history-extra] { display: grid !important; } .timeline__pager { display: none !important; }</style></noscript>
<?php if ($editor): ?>
<link rel="stylesheet" href="<?= e(asset_version('static/css/cms.css')) ?>">
<?php endif; ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="<?= e(asset_version('static/js/site.js')) ?>" defer></script>
<?= analytics_scripts() ?>
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
      <img src="<?= e(asset_version('static/img/logo-dg.png')) ?>" alt="<?= e(t('brand')) ?>" width="389" height="149">
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

<?php require __DIR__ . '/partials/now-playing.php'; ?>

  </div>
</section>
<?php $html['domov'] = ob_get_clean(); ob_start(); ?>
<?php if ($showAbout): ?>
<!-- ═══ O NÁS ════════════════════════════════════════════════════════ -->
<section class="section" id="onas" aria-labelledby="onas-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="onas-title"><?= e(setting_label('about_title')) ?></h2>
      <?= cms_settings('about') ?>
    </header>

<?php if ($aboutText !== ''): ?>
    <div class="prose about"><?= rich_html($aboutText) ?></div>
<?php else: ?>
    <p class="muted center"><?= e(t('default_about_empty')) ?></p>
<?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php $html['onas'] = ob_get_clean(); ob_start(); ?>
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

<?php if ($ensemble): ?>
    <!-- Záverečné titulky ako vo filme: vľavo úloha (skupina), vpravo jej ľudia pod sebou.
         Ten istý človek môže byť vo viacerých skupinách. Šípky pri skupine menia poradie
         skupín; názov a ľudia (poradie, pridanie, odobratie) sa upravujú v okne skupiny.
         Zoznam úloha → mená je <dl>, aby to čítačka obrazovky prečítala ako dvojice. -->
    <dl class="credits" data-credits>
<?php foreach ($ensemble as $group): ?>
      <div class="credits__group cms-item">
        <dt class="credits__role"><?= e(tr($group, 'name')) ?><?= cms_controls('ensemble_groups', (int) $group['id'], 'cms-bar--credits') ?></dt>
<?php foreach ($group['people'] as $person): ?>
        <dd class="credits__person">
          <span class="credits__name"><?= e((string) $person['name']) ?></span>
<?php if (!empty($person['since'])): ?>
          <span class="credits__since"><?= e(t('ensemble_since_short', (int) $person['since'])) ?></span>
<?php endif; ?>
        </dd>
<?php endforeach; ?>
<?php if (!$group['people']): // vidí len prihlásený — prázdnu skupinu návštevník nevidí ?>
        <dd class="credits__person credits__person--empty"><?= e(t('ensemble_group_empty')) ?></dd>
<?php endif; ?>
      </div>
<?php endforeach; ?>
    </dl>
<?php else: ?>
    <p class="muted center"><?= e(t('ensemble_empty')) ?></p>
<?php endif; ?>
<?php if ($editor): ?>
    <p class="cms-note"><?= e(t('ensemble_note')) ?></p>
<?php endif; ?>
    <?= cms_add('ensemble_groups', 'cms_add_group') ?>
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
// Časová os (history_years): každý rok je nadpis na čiare, pod ním karty jeho záznamov (názov,
// miesto, text, obrázok). Každá karta má na čiare vlastnú bodku a pri nej mesiac, prípadne deň
// (na mobile nad kartou). Záznam s obsadením sa dá rozkliknúť: obsadenie je v okne s podrobnosťami
// (obsah v <template> pri zázname).
// Roky sú po stranách (history_limit rokov na strane, 0 = všetky na jednej): návštevník vidí prvú
// stranu, ďalšie listuje tlačidlami a bodkami pod osou (site.js) — vypíšu sa však všetky roky,
// ostatné strany len skryje CSS, takže bez JavaScriptu je vidieť celá história. Prihlásený vidí
// všetky roky naraz a pred každou ďalšou stranou čiaru, kde sa návštevníkovi začína. Strany sa
// počítajú z rokov, ktoré má návštevník — prihlásený vidí navyše aj roky, v ktorých je len skrytá
// inscenácia, a tie by strany posunuli.
$yearsPublic = array_keys(array_filter($historyYears, static fn (array $items): bool => (bool) array_filter($items, static fn (array $item): bool => !$item['hidden'])));
// rozsah strany: „2016 – 2020", pri jednom roku len „2026"
$yearRange    = static fn (array $years): string => min($years) === max($years) ? (string) min($years) : min($years) . ' – ' . max($years);
$historyPages = $yearsPublic ? ($historyLimit > 0 ? array_chunk($yearsPublic, $historyLimit) : [$yearsPublic]) : [];
$historyPaged = count($historyPages) > 1;
$pageOf = $pageStart = $pageRanges = [];
foreach ($historyPages as $i => $chunk) {
    $pageRanges[] = $yearRange($chunk);
    foreach ($chunk as $y) {
        $pageOf[$y] = $i + 1;
    }
    if ($i > 0) {
        $pageStart[$chunk[0]] = $i + 1; // rok, ktorým sa začína ďalšia strana (čiara pre prihláseného)
    }
}
$maxDots = 12; // do toľkých strán bodky, nad tým počítadlo „2 / 14" — rovnako MAX_DOTS v site.js
?>
<section class="section" id="historia" aria-labelledby="historia-title">
  <div class="wrap">
    <header class="section__head cms-zone">
      <h2 class="section__title" id="historia-title"><?= e(setting_label('history_title')) ?></h2>
      <div class="section__intro"><?= paragraphs(setting_tr('history_intro')) ?></div>
      <?= cms_settings('history') ?>
    </header>

<?php if ($historyYears): ?>
    <ol class="timeline">
<?php foreach ($historyYears as $year => $items): $pageNo = $pageOf[$year] ?? 1; ?>
<?php if ($editor && isset($pageStart[$year])): ?>
      <li class="timeline__cut cms-note"><?= e(t('history_cut', $pageStart[$year], $pageRanges[$pageStart[$year] - 1])) ?></li>
<?php endif; ?>
      <li class="timeline__item"<?= $historyPaged && !$editor ? ' data-history-page="' . $pageNo . '"' . ($pageNo > 1 ? ' data-history-extra' : '') : '' ?>>
        <h3 class="timeline__year"><?= (int) $year ?></h3>
        <div class="timeline__entries">
<?php foreach ($items as $item): $sheet = 'sheet-history-' . $item['id']; ?>
          <article class="timeline__card<?= $item['cast'] ? ' has-cast' : '' ?><?= $item['month'] !== null ? ' has-date' : '' ?>">
            <?= cms_flags(['hidden' => $item['hidden']]) ?>
<?php if ($item['cast']): // celá karta otvorí okno s obsadením (tlačidlo je v nadpise kvôli čítačkám) ?>
            <h4 class="timeline__title"><button type="button" class="timeline__open" data-sheet="<?= e($sheet) ?>" aria-haspopup="dialog"><?= e($item['title']) ?><span class="visually-hidden"> — <?= e(t('history_cast')) ?></span></button></h4>
<?php else: ?>
            <h4 class="timeline__title"><?= e($item['title']) ?></h4>
<?php endif; ?>
<?php if ($item['month'] !== null): // na čiare vedľa bodky karty (site.css); v HTML za nadpisom — čítačka pri skákaní po nadpisoch začne názvom ?>
            <p class="timeline__date<?= $item['day'] === null ? ' is-month' : '' ?>"><time datetime="<?= e(history_datetime((int) $year, $item['month'], $item['day'])) ?>"><?php if ($item['day'] !== null): ?><span class="timeline__day"><?= (int) $item['day'] ?>.</span> <?php endif; ?><span class="timeline__month"><?= e(history_month($item['month'], $item['day'] !== null)) ?></span></time></p>
<?php endif; ?>
<?php if ($item['place'] !== ''): ?>
            <p class="timeline__places"><?= e($item['place']) ?></p>
<?php endif; ?>
<?php if ($item['text'] !== ''): ?>
            <div class="prose"><?= paragraphs($item['text']) ?></div>
<?php endif; ?>
<?php if ($item['image'] !== ''): ?>
            <button type="button" class="timeline__image" aria-label="<?= e(t('play_image_open')) ?>" data-lightbox-single="<?= e(media_url($item['image'])) ?>" data-caption="<?= e(history_date((int) $year, $item['month'], $item['day']) . ' — ' . $item['title']) ?>">
              <img src="<?= e(media_url($item['image'])) ?>" alt="" loading="lazy">
            </button>
<?php endif; ?>
<?php if ($item['cast']): ?>
            <span class="timeline__more" aria-hidden="true"><?= e(t('history_cast')) ?></span>
            <template id="<?= e($sheet) ?>">
              <article class="sheet-history">
                <p class="sheet-history__when"><?= e(history_date((int) $year, $item['month'], $item['day'])) ?></p>
                <h2 class="sheet__title" id="sheet-title"><?= e($item['title']) ?></h2>
                <?= cms_edit_link('history', $item['id'], 'cms_edit_history') ?>
<?php if ($item['place'] !== ''): ?>
                <p class="sheet-history__place"><?= e($item['place']) ?></p>
<?php endif; ?>
                <h3 class="subhead"><?= e(t('history_cast')) ?></h3>
                <!-- ako titulky: vľavo postava (úloha), vpravo kto ju hral -->
                <dl class="cast">
<?php foreach ($item['cast'] as $person): ?>
                  <div class="cast__row">
                    <dt class="cast__role"><?= e($person['role']) ?></dt>
                    <dd class="cast__name"><?= e($person['name']) ?></dd>
                  </div>
<?php endforeach; ?>
                </dl>
              </article>
            </template>
<?php endif; ?>
            <?= cms_controls('history', $item['id'], 'cms-bar--inline') ?>
          </article>
<?php endforeach; ?>
        </div>
      </li>
<?php endforeach; ?>
    </ol>
<?php if ($historyPaged && !$editor): // listovanie: ďalšie strany skrýva CSS, prepína ich site.js; bez JavaScriptu <noscript> v hlavičke ?>
    <nav class="timeline__pager" aria-label="<?= e(t('history_pager')) ?>" data-history-pager data-history-ranges="<?= e(json_encode($pageRanges, JSON_UNESCAPED_UNICODE)) ?>">
      <button type="button" class="btn btn--ghost btn--compact" data-history-prev disabled>
        <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg>
        <span><?= e(t($historyOrder === 'asc' ? 'history_pager_older' : 'history_pager_newer')) ?></span>
        <span class="timeline__pager-range" data-history-range></span>
      </button>
<?php if (count($historyPages) <= $maxDots): ?>
      <div class="timeline__dots">
<?php foreach ($pageRanges as $i => $range): ?>
        <button type="button" class="timeline__dot" data-page="<?= $i + 1 ?>" aria-label="<?= e(t('history_page', $i + 1, $range)) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
<?php endforeach; ?>
      </div>
<?php else: ?>
      <p class="timeline__count">1 / <?= count($historyPages) ?></p>
<?php endif; ?>
      <button type="button" class="btn btn--ghost btn--compact" data-history-next>
        <span><?= e(t($historyOrder === 'asc' ? 'history_pager_newer' : 'history_pager_older')) ?></span>
        <span class="timeline__pager-range" data-history-range>(<?= e($pageRanges[1]) ?>)</span>
        <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
      </button>
    </nav>
<?php endif; ?>
<?php else: ?>
    <p class="muted center"><?= e(t('history_empty')) ?></p>
<?php endif; ?>
<?php if ($editor): ?>
    <p class="cms-note"><?= e(t('history_note')) ?></p>
<?php endif; ?>
    <?= cms_add('history', 'cms_add_history') ?>
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

<!-- Okno s podrobnosťami (inscenácia z repertoáru): obsah sa vloží zo <template> pri položke -->
<div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-title" hidden>
  <div class="sheet__panel">
    <button type="button" class="lightbox__btn sheet__close" data-sheet-close aria-label="<?= e(t('lb_close')) ?>">&times;</button>
    <div class="sheet__content"></div>
  </div>
</div>

<?php require __DIR__ . '/partials/overlays.php'; ?>

<?php if ($editor): require __DIR__ . '/partials/adminbar.php'; endif; ?>
</body>
</html>
