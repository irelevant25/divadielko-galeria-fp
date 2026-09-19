<?php
/**
 * Divadielko Galéria — vstupný bod.
 *
 * Podľa režimu (config.php → administrácia) zobrazí:
 *   wip          pages/placeholder.php  „Opona sa čoskoro dvíha"
 *   maintenance  pages/placeholder.php  „Máme krátku prestávku"
 *   live         pages/site.php         ostrá jednostránková stránka
 *
 * Prihlásený používateľ vidí ostrú stránku v každom režime — aj s ceruzkami
 * na úpravu obsahu. Prihlásenie a administrácia sú na /login.php a /admin.php,
 * nikde na stránke na ne nevedie odkaz.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

// Chýbajúci obrázok či skript dostane 404, nie celú stránku (aj v dočasných režimoch).
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('~\.(ico|png|jpe?g|gif|webp|avif|svg|css|js|map|json|xml|txt|woff2?|ttf|mp4|webm|mp3|opus)$~i', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('404');
}

$mode = site_mode();

// Prihlásený si môže pozrieť, čo vidí verejnosť: /?preview=wip alebo /?preview=maintenance
$preview = current_user() !== null && in_array($_GET['preview'] ?? '', ['wip', 'maintenance'], true) ? $_GET['preview'] : null;

if ($preview !== null || ($mode !== 'live' && current_user() === null)) {
    $variant = $preview ?? $mode;
    require __DIR__ . '/pages/placeholder.php';
    exit;
}

// Staré adresy z pôvodného webu (a čokoľvek neexistujúce) → úvodná stránka.
if ($path !== '/' && $path !== '/index.php') {
    redirect('/', 301);
}

// Keby sa pri vykresľovaní niečo pokazilo (napr. výpadok databázy uprostred),
// návštevník dostane stránku údržby namiesto polovice webu.
ob_start();
try {
    require __DIR__ . '/pages/site.php';
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean();
    error_log('[site] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $variant = 'maintenance';
    require __DIR__ . '/pages/placeholder.php';
}
