<?php
/**
 * Jazyk a preklady. Texty rozhrania sú v includes/lang.php, obsah (inscenácie,
 * členovia, …) má v databáze stĺpce *_sk a *_en.
 */

declare(strict_types=1);

/**
 * Výber jazyka: ?lang= (prepínač SK / EN, zapamätá sa v cookie) → cookie → predvolený
 * (slovenčina). Jazyk prehliadača sa zámerne neberie do úvahy — stránka je
 * slovenská a angličtina je len na vyžiadanie.
 */
function pick_language(): string
{
    $available = config('languages');
    $cookie    = (string) config('lang_cookie');

    $requested = isset($_GET['lang']) && is_string($_GET['lang']) ? strtolower($_GET['lang']) : '';
    if (in_array($requested, $available, true)) {
        if (($_COOKIE[$cookie] ?? '') !== $requested && !headers_sent()) {
            setcookie($cookie, $requested, [
                'expires'  => time() + 365 * 24 * 60 * 60,
                'path'     => '/',
                'samesite' => 'Lax',
                'secure'   => is_https(),
                'httponly' => false,
            ]);
        }
        return $requested;
    }

    $fromCookie = isset($_COOKIE[$cookie]) ? strtolower((string) $_COOKIE[$cookie]) : '';
    if (in_array($fromCookie, $available, true)) {
        return $fromCookie;
    }

    return (string) config('default_lang');
}

/** Aktuálny jazyk. S argumentom ho nastaví (napr. API podľa jazyka stránky, z ktorej prišiel formulár). */
function lang(?string $set = null): string
{
    static $lang = null;

    if ($set !== null && in_array($set, config('languages'), true)) {
        $lang = $set;
    }

    return $lang ??= pick_language();
}

function lang_strings(): array
{
    static $strings = null;

    return $strings ??= require __DIR__ . '/lang.php';
}

/** Preklad kľúča z lang.php; ďalšie argumenty idú do sprintf. */
function t(string $key, ...$args): string
{
    return t_in(lang(), $key, ...$args);
}

/** Preklad v konkrétnom jazyku (napr. predvolené texty pre obe jazykové verzie formulára). */
function t_in(string $lang, string $key, ...$args): string
{
    $strings = lang_strings();
    $text = $strings[$lang][$key] ?? $strings[config('default_lang')][$key] ?? $key;

    return $args ? vsprintf($text, $args) : $text;
}

/** Existuje kľúč v lang.php? */
function t_has(string $key): bool
{
    return isset(lang_strings()[config('default_lang')][$key]);
}

/** Všetky preklady s daným prefixom (napr. pre JavaScript). */
function t_prefix(string $prefix): array
{
    $out = [];
    foreach (lang_strings()[lang()] as $key => $value) {
        if (strncmp($key, $prefix, strlen($prefix)) === 0) {
            $out[substr($key, strlen($prefix))] = $value;
        }
    }

    return $out;
}

/** Hodnota prekladaného poľa z riadku: $row['title_en'] → pri prázdnom $row['title_sk']. */
function tr(array $row, string $field): string
{
    $value = trim((string) ($row[$field . '_' . lang()] ?? ''));
    if ($value === '') {
        $value = trim((string) ($row[$field . '_' . config('default_lang')] ?? ''));
    }

    return $value;
}

/** Odkaz na tú istú stránku v inom jazyku. */
function lang_url(string $code): string
{
    $query = $_GET;
    $query['lang'] = $code;

    return '?' . http_build_query($query);
}

/** Dátum a čas po slovensky / anglicky bez závislosti na intl. */
function format_date(string $value, string $style = 'long'): string
{
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    $months = explode(',', t('months'));
    $days   = explode(',', t('weekdays'));
    $d = (int) date('j', $ts);
    $m = $months[(int) date('n', $ts) - 1] ?? '';
    $y = date('Y', $ts);

    if ($style === 'weekday') {
        return $days[(int) date('N', $ts) - 1] ?? '';
    }
    if ($style === 'day_month') {
        return lang() === 'en' ? "$d $m" : "$d. $m";
    }

    return lang() === 'en' ? "$d $m $y" : "$d. $m $y";
}

function format_time(string $value): string
{
    $ts = strtotime($value);

    return $ts === false ? '' : date('H:i', $ts);
}
