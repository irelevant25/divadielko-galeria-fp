<?php
/**
 * Texty rozhrania sú v includes/lang.php, obsah (inscenácie, súbor, …) v databáze
 * v stĺpcoch *_sk. Stránka je jednojazyčná — slovenská.
 */

declare(strict_types=1);

/**
 * Jazyk stránky. Stránka je len po slovensky — funkcia ostáva, aby sa texty aj
 * obsah brali stále cez jedno miesto (a api.php mohol jazyk odovzdať ďalej).
 */
function lang(?string $set = null): string
{
    return (string) config('default_lang');
}

function lang_strings(): array
{
    static $strings = null;

    return $strings ??= require __DIR__ . '/lang.php';
}

/** Preklad kľúča z lang.php; ďalšie argumenty idú do sprintf. */
function t(string $key, ...$args): string
{
    $text = lang_strings()[config('default_lang')][$key] ?? $key;

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

/** Hodnota textového poľa z riadku: $row['title'] je v stĺpci title_sk. */
function tr(array $row, string $field): string
{
    return trim((string) ($row[$field . '_' . config('default_lang')] ?? ''));
}

/** Dátum a čas po slovensky bez závislosti na intl. */
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
        return "$d. $m";
    }

    return "$d. $m $y";
}

function format_time(string $value): string
{
    $ts = strtotime($value);

    return $ts === false ? '' : date('H:i', $ts);
}
