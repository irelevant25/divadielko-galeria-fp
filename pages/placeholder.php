<?php
/**
 * Dočasná stránka v dvoch podobách:
 *   $variant = 'wip'         „Opona sa čoskoro dvíha" (stránka sa pripravuje)
 *   $variant = 'maintenance' „Máme krátku prestávku" (údržba) — bábka má prilbu a kľúč
 *
 * Nepotrebuje databázu: kontakt a odkazy berie z administrácie, ak je
 * databáza dostupná, inak z config.php.
 *
 * Keď je čo hrať, navrchu je „Práve hráme" — ten istý blok ako na ostrej
 * stránke (partials/now-playing.php + site.css a site.js pre plagát, ukážku
 * a galériu), až pod ním bábka a oznam. Ceruzky sa tu nikdy neukazujú.
 */

declare(strict_types=1);

/** @var string $variant */
$p    = $variant === 'maintenance' ? 'mnt_' : 'wip_';
$base = base_url();

// Dočasnú stránku vidí každý rovnako — aj prihlásený v náhľade (?preview=…) bez ceruziek.
cms_on(false);
$editor = false;

// „Práve hráme": tie isté zverejnené položky ako na ostrej stránke. Bez databázy
// (alebo keď sa niečo pokazí — sem sa chodí aj po chybe) sa blok nezobrazí.
$runs = $datesOf = $upcoming = [];
try {
    if (db_available()) {
        ['runs' => $runs, 'dates' => $datesOf, 'upcoming' => $upcoming] = now_playing(false);
    }
} catch (Throwable $e) {
    $runs = $datesOf = $upcoming = [];
}

$links = array_filter([
    'facebook'  => link_to('facebook'),
    'instagram' => link_to('instagram'),
    'youtube'   => link_to('youtube'),
]);
$msks = link_to('msks');

header('Content-Type: text/html; charset=UTF-8');
security_headers();
header('Cache-Control: no-cache, must-revalidate, max-age=0');

// Kým stránka nie je ostrá, hlási sa ako dočasne nedostupná.
header('HTTP/1.1 503 Service Unavailable', true, 503);
header('Retry-After: ' . (int) (config('retry_after')[$variant] ?? 3600));

$jsonLd = [
    '@context'     => 'https://schema.org',
    '@type'        => 'PerformingGroup',
    'name'         => 'Divadielko Galéria',
    'url'          => $base . '/',
    'description'  => t($p . 'meta_desc'),
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
    'sameAs'    => array_values(array_filter([$links['facebook'] ?? '', $links['instagram'] ?? '', $links['youtube'] ?? '', $msks])),
];
?>
<!DOCTYPE html>
<html lang="<?= e(t('html_lang')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t($p . 'title')) ?></title>
<meta name="description" content="<?= e(t($p . 'meta_desc')) ?>">
<meta name="theme-color" content="#14090f">
<link rel="canonical" href="<?= e($base) ?>/">
<link rel="icon" href="/static/img/favicon.svg" type="image/svg+xml">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Divadielko Galéria">
<meta property="og:locale" content="<?= e(t('og_locale')) ?>">
<meta property="og:title" content="<?= e(t($p . 'title')) ?>">
<meta property="og:description" content="<?= e(t($p . 'meta_desc')) ?>">
<meta property="og:url" content="<?= e($base) ?>/">
<meta name="twitter:card" content="summary">
<?php if ($runs): // vzhľad a správanie „Práve hráme" ako na ostrej stránke ?>
<link rel="stylesheet" href="<?= e(asset_version('static/css/site.css')) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset_version('static/css/placeholder.css')) ?>">
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php if ($runs): ?>
<script src="<?= e(asset_version('static/js/site.js')) ?>" defer></script>
<?php endif; ?>
<?php if (analytics_enabled()): $a = config('analytics'); ?>
<script async defer src="<?= e($a['src']) ?>" data-website-id="<?= e($a['website_id']) ?>"></script>
<?php endif; ?>
</head>
<body class="is-<?= e($variant) ?>">

<a class="skip-link" href="#obsah"><?= e(t('skip_link')) ?></a>

<div class="valance" aria-hidden="true">
  <svg viewBox="0 0 1200 90" preserveAspectRatio="none" focusable="false">
    <path class="valance__cloth" d="M0,0 H1200 V34 C1140,80 1080,80 1020,34 C960,80 900,80 840,34 C780,80 720,80 660,34 C600,80 540,80 480,34 C420,80 360,80 300,34 C240,80 180,80 120,34 C90,57 60,64 30,55 L0,44 Z"/>
    <path class="valance__trim" d="M1200,34 C1140,80 1080,80 1020,34 C960,80 900,80 840,34 C780,80 720,80 660,34 C600,80 540,80 480,34 C420,80 360,80 300,34 C240,80 180,80 120,34 C90,57 60,64 30,55 L0,44"/>
  </svg>
</div>

<main id="obsah">

<?php if ($runs): require __DIR__ . '/partials/program-helpers.php'; ?>
<section class="section hero" aria-label="<?= e(t('program_now')) ?>">
  <div class="wrap">
<?php require __DIR__ . '/partials/now-playing.php'; ?>
  </div>
</section>

<div class="rule" aria-hidden="true"><span></span>&#10022;<span></span></div>

<?php endif; ?>
<div class="stage">

  <div class="marionette" aria-hidden="true">
    <svg viewBox="0 0 240 330" focusable="false">
      <rect class="m-wood" x="58" y="8" width="124" height="9" rx="4.5"/>
      <rect class="m-wood" x="115" y="0" width="10" height="25" rx="5"/>
      <g class="marionette__swing">
        <g class="m-string">
          <path d="M84,17 C92,50 100,68 107,86"/>
          <path d="M156,17 C148,50 140,68 133,86"/>
          <path d="M64,17 C62,70 66,124 72,166"/>
          <path d="M176,17 C178,70 174,124 168,166"/>
        </g>
        <g class="marionette__body">
<?php if ($variant !== 'maintenance'): ?>
          <path class="m-hat" d="M104,86 C108,58 118,44 120,42 C122,44 132,58 136,86 Z"/>
          <circle class="m-bell" cx="120" cy="40" r="5"/>
<?php endif; ?>
          <circle class="m-face" cx="120" cy="100" r="19"/>
          <circle class="m-eye" cx="113" cy="<?= $variant === 'maintenance' ? '101' : '98' ?>" r="2.4"/>
          <circle class="m-eye" cx="127" cy="<?= $variant === 'maintenance' ? '101' : '98' ?>" r="2.4"/>
<?php if ($variant === 'maintenance'): ?>
          <path class="m-smile" d="M114,109 C117,111 123,111 126,108"/>
          <path class="m-helmet" d="M99,92 C99,66 141,66 141,92 Z"/>
          <path class="m-helmet-ridge" d="M120,69 V90"/>
          <rect class="m-helmet" x="94" y="89" width="52" height="5.5" rx="2.75"/>
<?php else: ?>
          <path class="m-smile" d="M112,107 C116,112 124,112 128,107"/>
<?php endif; ?>
          <path class="m-collar" d="M99,120 C108,131 132,131 141,120 C136,127 132,131 120,131 C108,131 104,127 99,120 Z"/>
          <path class="m-coat" d="M120,122 C134,122 146,134 148,152 L152,192 C142,199 98,199 88,192 L92,152 C94,134 106,122 120,122 Z"/>
          <circle class="m-button" cx="120" cy="150" r="2.6"/>
          <circle class="m-button" cx="120" cy="164" r="2.6"/>
          <circle class="m-button" cx="120" cy="178" r="2.6"/>
          <g class="m-limb">
            <path d="M96,136 C84,146 76,156 72,166"/>
            <path d="M144,136 C156,146 164,156 168,166"/>
          </g>
<?php if ($variant === 'maintenance'): ?>
          <g class="m-tool" transform="translate(169 169) rotate(28)">
            <rect x="-2.6" y="-25" width="5.2" height="27" rx="2.6"/>
            <path d="M-3,-38.4 A8,8 0 1,0 3,-38.4 L3,-32 L-3,-32 Z"/>
          </g>
<?php endif; ?>
          <circle class="m-hand" cx="71" cy="169" r="6"/>
          <circle class="m-hand" cx="169" cy="169" r="6"/>
          <g class="m-limb">
            <path d="M108,196 C106,216 104,238 105,258"/>
            <path d="M132,196 C134,216 136,238 135,258"/>
          </g>
          <path class="m-shoe" d="M97,258 C97,253 113,253 113,258 C113,264 97,264 97,258 Z"/>
          <path class="m-shoe" d="M127,258 C127,253 143,253 143,258 C143,264 127,264 127,258 Z"/>
        </g>
      </g>
    </svg>
  </div>

  <header class="brand">
<?php if (($badge = setting_tr('hero_badge')) !== ''): // rovnaké texty ako v úvode ostrej stránky ?>
    <p class="brand__badge"><?= e($badge) ?></p>
<?php endif; ?>
    <h1 class="brand__name"><?= e(setting_label('hero_title')) ?></h1>
    <p class="brand__sub"><?= e(t('brand_sub')) ?></p>
  </header>

  <div class="rule" aria-hidden="true"><span></span>&#10022;<span></span></div>

  <section class="message">
    <h2 class="message__head"><?= e(t($p . 'headline')) ?></h2>
    <p class="message__lead"><?= e(t($p . 'lead')) ?></p>
  </section>

  <section class="links" aria-labelledby="links-head">
    <h3 class="section-head" id="links-head"><?= e(t($p . 'links')) ?></h3>

<?php if ($msks !== ''): ?>
    <a class="btn btn--primary" href="<?= e($msks) ?>" target="_blank" rel="noopener">
      <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M4 20h16v-9l-8-5.5L4 11v9zm6-2v-5h4v5h-4z"/>
      </svg>
      <span><?= e(t('cta_msks')) ?></span>
    </a>
<?php endif; ?>

    <div class="links__social">
<?php foreach ($links as $network => $href): ?>
      <a class="btn btn--ghost" href="<?= e($href) ?>" target="_blank" rel="noopener">
        <?php require __DIR__ . '/partials/icon-' . $network . '.php'; ?>
        <span><?= e(t(['facebook' => 'cta_fb', 'instagram' => 'cta_ig', 'youtube' => 'cta_yt'][$network])) ?></span>
      </a>
<?php endforeach; ?>
    </div>
  </section>

  <section class="contact" aria-labelledby="contact-head">
    <h3 class="section-head" id="contact-head"><?= e(t('contact_head')) ?></h3>
    <dl class="contact__list">
      <div class="contact__item">
        <dt><?= e(t('phone_label')) ?></dt>
        <dd><a href="tel:<?= e(contact('phone_tel')) ?>"><?= e(contact('phone_display')) ?></a></dd>
      </div>
      <div class="contact__item">
        <dt><?= e(t('email_label')) ?></dt>
        <dd><a href="mailto:<?= e(contact('email')) ?>"><?= e(contact('email')) ?></a></dd>
      </div>
      <div class="contact__item">
        <dt><?= e(t('where_label')) ?></dt>
        <dd><?= e(contact('street')) ?><br><?= e(contact('city')) ?></dd>
      </div>
    </dl>
  </section>

  <footer class="foot">
    <p>&copy; <?= date('Y') ?> <?= e(t('footer')) ?></p>
    <p class="foot__credit"><?= sprintf(e(t('footer_credit')), '<a href="https://codehero.sk/" target="_blank" rel="noopener">CodeHero</a>') ?></p>
  </footer>

</div>
</main>

<?php if ($runs): require __DIR__ . '/partials/overlays.php'; endif; ?>
</body>
</html>
