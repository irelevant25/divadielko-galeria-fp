<?php
/**
 * Divadielko Galéria — dočasná (maintenance) stránka.
 * Texty sa menia v includes/lang.php, odkazy a kontakt v includes/config.php.
 */

declare(strict_types=1);

$config       = require __DIR__ . '/includes/config.php';
$translations = require __DIR__ . '/includes/lang.php';

/** Výber jazyka: ?lang= → cookie → jazyk prehliadača → predvolený. */
function pick_language(array $config): string
{
    $available = $config['languages'];

    $requested = isset($_GET['lang']) && is_string($_GET['lang']) ? strtolower($_GET['lang']) : '';
    if (in_array($requested, $available, true)) {
        setcookie($config['lang_cookie'], $requested, [
            'expires'  => time() + 365 * 24 * 60 * 60,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,
        ]);
        return $requested;
    }

    $cookie = isset($_COOKIE[$config['lang_cookie']]) ? strtolower((string) $_COOKIE[$config['lang_cookie']]) : '';
    if (in_array($cookie, $available, true)) {
        return $cookie;
    }

    // Accept-Language: berieme len prvé dve písmená každej položky, poradie zachovávame.
    $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    foreach (explode(',', $header) as $part) {
        $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
        if (in_array($code, $available, true)) {
            return $code;
        }
        // Češtinu berieme ako slovenčinu.
        if ($code === 'cs' && in_array('sk', $available, true)) {
            return 'sk';
        }
    }

    return $config['default_lang'];
}

/** Základná adresa stránky (bez lomky na konci). */
function base_url(array $config): string
{
    if ($config['canonical_base'] !== '') {
        return rtrim($config['canonical_base'], '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return ($https ? 'https://' : 'http://') . $host;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lang    = pick_language($config);
$t       = $translations[$lang];
$links   = $config['links'];
$contact = $config['contact'];
$base    = base_url($config);
$city    = $lang === 'en' ? $contact['city_en'] : $contact['city_sk'];
$badge   = sprintf($t['badge'], $config['founded']);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Kým je stránka v príprave, hlásime sa ako dočasne nedostupná.
if ($config['send_503']) {
    header('HTTP/1.1 503 Service Unavailable', true, 503);
    header('Retry-After: ' . (int) $config['retry_after']);
}

// Structured data pre vyhľadávače.
$jsonLd = [
    '@context'     => 'https://schema.org',
    '@type'        => 'PerformingGroup',
    'name'         => 'Divadielko Galéria',
    'url'          => $base . '/',
    'description'  => $t['meta_desc'],
    'foundingDate' => (string) $config['founded'],
    'address'      => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => $contact['street'],
        'addressLocality' => 'Nové Mesto nad Váhom',
        'postalCode'      => '915 01',
        'addressCountry'  => 'SK',
    ],
    'telephone' => $contact['phone_tel'],
    'email'     => $contact['email'],
    'sameAs'    => [$links['facebook'], $links['instagram'], $links['msks']],
];
?>
<!DOCTYPE html>
<html lang="<?= e($t['html_lang']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($t['title']) ?></title>
<meta name="description" content="<?= e($t['meta_desc']) ?>">
<meta name="theme-color" content="#14090f">
<link rel="canonical" href="<?= e($base) ?>/">
<link rel="alternate" hreflang="sk" href="<?= e($base) ?>/?lang=sk">
<link rel="alternate" hreflang="en" href="<?= e($base) ?>/?lang=en">
<link rel="alternate" hreflang="x-default" href="<?= e($base) ?>/">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Divadielko Galéria">
<meta property="og:locale" content="<?= e($t['og_locale']) ?>">
<meta property="og:title" content="<?= e($t['title']) ?>">
<meta property="og:description" content="<?= e($t['meta_desc']) ?>">
<meta property="og:url" content="<?= e($base) ?>/">
<meta name="twitter:card" content="summary">
<link rel="stylesheet" href="/assets/css/style.css?v=1">
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>

<a class="skip-link" href="#obsah"><?= e($t['skip_link']) ?></a>

<div class="valance" aria-hidden="true">
  <svg viewBox="0 0 1200 90" preserveAspectRatio="none" focusable="false">
    <path class="valance__cloth" d="M0,0 H1200 V34 C1140,80 1080,80 1020,34 C960,80 900,80 840,34 C780,80 720,80 660,34 C600,80 540,80 480,34 C420,80 360,80 300,34 C240,80 180,80 120,34 C90,57 60,64 30,55 L0,44 Z"/>
    <path class="valance__trim" d="M1200,34 C1140,80 1080,80 1020,34 C960,80 900,80 840,34 C780,80 720,80 660,34 C600,80 540,80 480,34 C420,80 360,80 300,34 C240,80 180,80 120,34 C90,57 60,64 30,55 L0,44"/>
  </svg>
</div>

<nav class="langbar" aria-label="<?= e($t['lang_switch']) ?>">
<?php foreach ($config['languages'] as $code): ?>
  <a class="langbar__link<?= $code === $lang ? ' is-current' : '' ?>" href="?lang=<?= e($code) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
<?php endforeach; ?>
</nav>

<main id="obsah" class="stage">

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
          <path class="m-hat" d="M104,86 C108,58 118,44 120,42 C122,44 132,58 136,86 Z"/>
          <circle class="m-bell" cx="120" cy="40" r="5"/>
          <circle class="m-face" cx="120" cy="100" r="19"/>
          <circle class="m-eye" cx="113" cy="98" r="2.4"/>
          <circle class="m-eye" cx="127" cy="98" r="2.4"/>
          <path class="m-smile" d="M112,107 C116,112 124,112 128,107"/>
          <path class="m-collar" d="M99,120 C108,131 132,131 141,120 C136,127 132,131 120,131 C108,131 104,127 99,120 Z"/>
          <path class="m-coat" d="M120,122 C134,122 146,134 148,152 L152,192 C142,199 98,199 88,192 L92,152 C94,134 106,122 120,122 Z"/>
          <circle class="m-button" cx="120" cy="150" r="2.6"/>
          <circle class="m-button" cx="120" cy="164" r="2.6"/>
          <circle class="m-button" cx="120" cy="178" r="2.6"/>
          <g class="m-limb">
            <path d="M96,136 C84,146 76,156 72,166"/>
            <path d="M144,136 C156,146 164,156 168,166"/>
          </g>
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
    <p class="brand__badge"><?= e($badge) ?></p>
    <h1 class="brand__name"><?= e($t['brand']) ?></h1>
    <p class="brand__sub"><?= e($t['brand_sub']) ?></p>
  </header>

  <div class="rule" aria-hidden="true"><span></span>&#10022;<span></span></div>

  <section class="message">
    <h2 class="message__head"><?= e($t['headline']) ?></h2>
    <p class="message__lead"><?= e($t['lead']) ?></p>
  </section>

  <section class="links" aria-labelledby="links-head">
    <h3 class="section-head" id="links-head"><?= e($t['links_label']) ?></h3>

    <a class="btn btn--primary" href="<?= e($links['msks']) ?>" target="_blank" rel="noopener">
      <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M4 20h16v-9l-8-5.5L4 11v9zm6-2v-5h4v5h-4z"/>
      </svg>
      <span><?= e($t['cta_msks']) ?></span>
    </a>

    <div class="links__social">
      <a class="btn btn--ghost" href="<?= e($links['facebook']) ?>" target="_blank" rel="noopener">
        <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M15.12 5.32H17V2.14A26.11 26.11 0 0 0 14.26 2c-2.72 0-4.58 1.66-4.58 4.7v2.62H6.61v3.56h3.07V22h3.68v-9.12h3.06l.46-3.56h-3.52V7.05c0-1.03.28-1.73 1.76-1.73z"/>
        </svg>
        <span><?= e($t['cta_fb']) ?></span>
      </a>

      <a class="btn btn--ghost" href="<?= e($links['instagram']) ?>" target="_blank" rel="noopener">
        <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <g fill="none" stroke="currentColor" stroke-width="1.9">
            <rect x="3" y="3" width="18" height="18" rx="5"/>
            <circle cx="12" cy="12" r="4"/>
          </g>
          <circle fill="currentColor" cx="17.4" cy="6.6" r="1.3"/>
        </svg>
        <span><?= e($t['cta_ig']) ?></span>
      </a>
    </div>
  </section>

  <section class="contact" aria-labelledby="contact-head">
    <h3 class="section-head" id="contact-head"><?= e($t['contact_head']) ?></h3>
    <dl class="contact__list">
      <div class="contact__item">
        <dt><?= e($t['phone_label']) ?></dt>
        <dd><a href="tel:<?= e($contact['phone_tel']) ?>"><?= e($contact['phone_display']) ?></a></dd>
      </div>
      <div class="contact__item">
        <dt><?= e($t['email_label']) ?></dt>
        <dd><a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a></dd>
      </div>
      <div class="contact__item">
        <dt><?= e($t['where_label']) ?></dt>
        <dd><?= e($contact['street']) ?><br><?= e($city) ?></dd>
      </div>
    </dl>
  </section>

  <footer class="foot">
    <p>&copy; <?= date('Y') ?> <?= e($t['footer']) ?></p>
  </footer>

</main>
</body>
</html>
