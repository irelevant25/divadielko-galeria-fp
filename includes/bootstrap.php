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
 * Text z editora (pole typu richtext) → bezpečné HTML. Ostanú len odseky, zlomy
 * riadkov, tučné, kurzíva, zoznamy a odkazy (http, https, mailto, tel, /, #);
 * ostatné značky sa zahodia a ich text ostane, skripty a štýly aj s obsahom.
 * Používa sa pri ukladaní aj pri vypisovaní. Starší obyčajný text (prázdny riadok
 * = nový odsek, <b> a <br> v ňom) sa najprv prevedie na odseky.
 */
function rich_html(?string $html): string
{
    $html = trim(str_replace("\r\n", "\n", (string) $html));
    if ($html === '') {
        return '';
    }
    if (!preg_match('~<(p|div|ul|ol|h[1-6])[\s>]~i', $html)) {
        $html = '<p>' . implode('</p><p>', array_map(
            static fn (string $para): string => preg_replace('/\n/', '<br>', trim($para)),
            preg_split('/\n{2,}/', $html)
        )) . '</p>';
    }

    $doc = new DOMDocument();
    $errors = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($errors);
    $body = $doc->getElementsByTagName('body')->item(0);

    return $body ? rich_html_blocks($body) : '';
}

/** Priamy obsah koreňa: bloky ostanú, voľný text a tučné písmo sa zabalia do odseku. */
function rich_html_blocks(DOMNode $root): string
{
    $out = '';
    $inline = '';
    $flush = static function () use (&$out, &$inline): void {
        if (rich_html_has_text($inline)) {
            $out .= '<p>' . preg_replace('~^(<br>)+|(<br>)+$~', '', trim($inline)) . '</p>';
        }
        $inline = '';
    };
    foreach ($root->childNodes as $child) {
        $html = rich_html_node($child);
        if ($child instanceof DOMElement && in_array(strtolower($child->tagName), RICH_BLOCKS, true)) {
            $flush();
            $out .= $html;
        } else {
            $inline .= $html;
        }
    }
    $flush();

    return $out;
}

const RICH_BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'pre', 'table'];
const RICH_DROP   = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math',
                     'head', 'title', 'meta', 'link', 'form', 'input', 'button', 'select', 'textarea', 'img', 'video', 'audio'];

function rich_html_node(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return e(str_replace("\n", ' ', $node->data));
    }
    if (!$node instanceof DOMElement) {
        return ''; // komentáre a pod.
    }

    $tag = strtolower($node->tagName);
    if (in_array($tag, RICH_DROP, true)) {
        return '';
    }
    if ($tag === 'br') {
        return '<br>';
    }

    $inner = '';
    foreach ($node->childNodes as $child) {
        $inner .= rich_html_node($child);
    }

    switch ($tag) {
        case 'b':
        case 'strong':
            return rich_html_has_text($inner) ? '<strong>' . $inner . '</strong>' : $inner;
        case 'i':
        case 'em':
            return rich_html_has_text($inner) ? '<em>' . $inner . '</em>' : $inner;
        case 'a':
            $href = trim($node->getAttribute('href'));
            if (!preg_match('~^(https?://|mailto:|tel:|#|/(?!/))~i', $href) || !rich_html_has_text($inner)) {
                return $inner;
            }
            $external = (bool) preg_match('~^https?://~i', $href);

            return '<a href="' . e($href) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $inner . '</a>';
        case 'ul':
        case 'ol':
            $items = '';
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                    $items .= rich_html_node($child);
                }
            }
            return $items !== '' ? '<' . $tag . '>' . $items . '</' . $tag . '>' : '';
        case 'li':
            $inner = preg_replace('~^(<br>)+|(<br>)+$~', '', trim(rich_html_list_item($node)));
            return rich_html_has_text($inner) ? '<li>' . $inner . '</li>' : '';
        default:
            if (in_array($tag, RICH_BLOCKS, true)) {
                // odsek aj nadpis, citát, <div> z editora → odsek; vnorené bloky sa rozbalia
                $blocks = rich_html_blocks($node);
                return $blocks;
            }
            return $inner; // <span>, <font>, <u> a pod. — len text
    }
}

/** Položka zoznamu: vnorený zoznam ostane, odseky v nej sa zmenia na zlomy riadkov. */
function rich_html_list_item(DOMElement $li): string
{
    $out = '';
    foreach ($li->childNodes as $child) {
        $html = rich_html_node($child);
        if ($child instanceof DOMElement && !in_array(strtolower($child->tagName), ['ul', 'ol'], true)
            && in_array(strtolower($child->tagName), RICH_BLOCKS, true)) {
            $html = preg_replace(['~^<p>~', '~</p><p>~', '~</p>$~'], ['', '<br>', ''], $html);
            $out .= ($out !== '' && !str_ends_with($out, '<br>') ? '<br>' : '') . $html;
        } else {
            $out .= $html;
        }
    }

    return $out;
}

/** Je v HTML aj nejaký viditeľný text (nielen medzery a zlomy riadkov)? */
function rich_html_has_text(string $html): bool
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{A0}") !== '';
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
