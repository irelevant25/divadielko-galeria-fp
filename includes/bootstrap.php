<?php
/**
 * Spoločný začiatok každej stránky: nastavenia, jazyk, databáza, pomocníci.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

date_default_timezone_set('Europe/Bratislava');
mb_internal_encoding('UTF-8');

/** Nastavenia: config.php prepísaný hodnotami z config.local.php. */
function config(?string $key = null)
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        $local  = __DIR__ . '/config.local.php';
        if (is_file($local)) {
            $config = array_replace_recursive($config, require $local);
        }
    }

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }

    return $value;
}

require __DIR__ . '/db.php';
require __DIR__ . '/i18n.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/content.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Viacriadkový text → odseky s <br>. Vstup je obyčajný text, nie HTML. */
function paragraphs(?string $text): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $out = '';
    foreach (preg_split('/\R{2,}/', $text) as $para) {
        $out .= '<p>' . nl2br(e(trim($para)), false) . '</p>';
    }

    return $out;
}

/**
 * Ako paragraphs(), ale v texte sa dá použiť pár značiek na zvýraznenie
 * (<b>, <strong>, <i>, <em>, <br>). Všetko ostatné sa vypíše ako text.
 */
function rich_paragraphs(?string $text): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $out = '';
    foreach (preg_split('/\R{2,}/', $text) as $para) {
        $html = nl2br(e(trim($para)), false);
        $html = preg_replace('~&lt;(/?)(b|strong|i|em)&gt;~i', '<$1$2>', $html);
        $html = preg_replace('~&lt;br\s*/?&gt;~i', '<br>', $html);
        $out .= '<p>' . $html . '</p>';
    }

    return $out;
}

/** Základná adresa stránky (bez lomky na konci). */
function base_url(): string
{
    $canonical = (string) config('canonical_base');
    if ($canonical !== '') {
        return rtrim($canonical, '/');
    }

    return (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Hostiteľ bez portu (zvláda aj IPv6 v hranatých zátvorkách). */
function current_host(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if (preg_match('/^\[(.+)\]/', $host, $m)) {
        return strtolower($m[1]);
    }

    return strtolower(explode(':', $host)[0]);
}

/** Vložiť merací skript? Nie na localhoste, nie pre prihlásených a nie keď je vypnutý. */
function analytics_enabled(): bool
{
    $a = config('analytics') ?? [];

    if (empty($a['enabled']) || empty($a['src']) || empty($a['website_id']) || current_user()) {
        return false;
    }

    return !in_array(current_host(), $a['skip_hosts'] ?? [], true);
}

/** Verzia súboru pre ?v= — po úprave CSS/JS si prehliadač stiahne novú. */
function asset_version(string $path): string
{
    $file = ROOT . '/' . ltrim($path, '/');

    return '/' . ltrim($path, '/') . '?v=' . (is_file($file) ? filemtime($file) : '0');
}

/** URL nahratého súboru v assets/. */
function media_url(?string $file): string
{
    return $file ? '/assets/' . rawurlencode($file) : '';
}

/** Podpis (HMAC) tajným kľúčom. Bez kľúča v configu sa použije náhradný, aby web nespadol. */
function sign(string $data): string
{
    $secret = (string) config('secret');
    if ($secret === '') {
        $secret = 'dg-unset-secret|' . __DIR__;
    }

    return hash_hmac('sha256', $data, $secret);
}

/** Anonymizovaná IP — na obmedzenie počtu pokusov, samotná adresa sa neukladá. */
function ip_hash(): string
{
    return sign('ip|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $to, int $status = 303): void
{
    header('Location: ' . $to, true, $status);
    exit;
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');

    // Skúšobná inštalácia (test.…): stránka je dostupná, ale nepatrí do vyhľadávačov.
    if (config('noindex')) {
        header('X-Robots-Tag: noindex, nofollow');
    }

    // Stránka nesmie spúšťať skripty odinakiaľ než od nás (a z Umami).
    // YouTube sa načíta až po kliknutí na video, a len z youtube-nocookie.com.
    $analytics = '';
    $a = config('analytics') ?? [];
    if (!empty($a['enabled']) && ($host = parse_url((string) ($a['src'] ?? ''), PHP_URL_HOST))) {
        $analytics = ' https://' . $host;
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self'$analytics; connect-src 'self'$analytics; "
        . "img-src 'self' data: https://i.ytimg.com; media-src 'self'; frame-src https://www.youtube-nocookie.com; "
        . "style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}

/** Stránky, ktoré nemajú byť vo vyhľadávačoch ani v cache (prihlásenie, administrácia, API). */
function private_headers(): void
{
    security_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');
}
