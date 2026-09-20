<?php
/**
 * Obsah stránky z databázy a ovládacie prvky na jeho úpravu.
 */

declare(strict_types=1);

const SITE_MODES = ['wip', 'maintenance', 'live'];

function entities(): array
{
    static $defs = null;

    return $defs ??= require __DIR__ . '/entities.php';
}

// ── Nastavenia (voľné texty, kontakty) ───────────────────────────────────────

function settings_all(): array
{
    static $settings = null;

    if ($settings === null) {
        $settings = [];
        if (db_available()) {
            foreach (db_all('SELECT key, value FROM settings') as $row) {
                $settings[$row['key']] = (string) $row['value'];
            }
        }
    }

    return $settings;
}

function setting(string $key, string $default = ''): string
{
    $value = settings_all()[$key] ?? '';

    return $value !== '' ? $value : $default;
}

/**
 * Text z nastavení (stĺpec kľúč_sk) alebo predvolený text z lang.php
 * ('default_<kľúč>'). Predvolený sa použije len vtedy, keď text ešte nikto
 * neuložil — uložený prázdny text znamená „nezobrazovať".
 */
function setting_tr(string $key): string
{
    $all  = settings_all();
    $main = $key . '_' . config('default_lang');

    return array_key_exists($main, $all) ? $all[$main] : default_text($key);
}

/** Text, ktorý nesmie ostať prázdny (nadpis, položka menu): uložený, inak predvolený. */
function setting_label(string $key): string
{
    $value = setting_tr($key);

    return $value !== '' ? $value : default_text($key);
}

/** Predvolený text 'default_<kľúč>' z lang.php; %d = rok založenia („Na scéne od roku 2006"). */
function default_text(string $key): string
{
    if (!t_has('default_' . $key)) {
        return '';
    }
    $text = t('default_' . $key);

    return str_contains($text, '%d') ? sprintf($text, founded()) : $text;
}

function save_setting(string $key, ?string $value): void
{
    db_exec(
        'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()',
        [$key, $value]
    );
}

/** Kontakt a odkazy: administrácia → config.php. */
function contact(string $key): string
{
    return setting($key, (string) (config('contact.' . $key) ?? ''));
}

function link_to(string $key): string
{
    return setting($key, (string) (config('links.' . $key) ?? ''));
}

function founded(): int
{
    return (int) setting('founded', (string) config('founded'));
}

// ── Sekcie a menu ────────────────────────────────────────────────────────────

/** Sekcie stránky (kotvy v adrese) v predvolenom poradí. */
const SECTIONS = ['domov', 'onas', 'galeria', 'subor', 'repertoar', 'historia', 'kontakt'];

/**
 * Poradie sekcií — zároveň poradie položiek v menu a v pätičke (administrácia →
 * Sekcie a menu). Domov (úvod a „Práve hráme") je vždy navrchu; sekcia, ktorá
 * v uloženom poradí chýba (pribudla neskôr), ide za svoju predvolenú susedku.
 */
function section_order(): array
{
    $order = array_values(array_unique(array_intersect(explode(',', setting('section_order')), SECTIONS)));
    foreach (SECTIONS as $i => $key) {
        if (!in_array($key, $order, true)) {
            $after = $i > 0 ? array_search(SECTIONS[$i - 1], $order, true) : false;
            array_splice($order, $after === false ? 0 : $after + 1, 0, [$key]);
        }
    }

    return array_merge(['domov'], array_values(array_diff($order, ['domov'])));
}

/** Názov sekcie v menu (prázdny = predvolený z lang.php). */
function section_label(string $key): string
{
    return setting_label('nav_' . $key);
}

/**
 * Aktuálny režim: config.php → administrácia. Bez databázy: kým ešte nie je
 * nastavená (prázdny db.user), stránka sa „pripravuje"; keď je nastavená, ale
 * nebeží, zobrazí sa údržba.
 */
function site_mode(): string
{
    $forced = config('mode');
    if (is_string($forced) && in_array($forced, SITE_MODES, true)) {
        return $forced;
    }
    if (!db_available()) {
        return (string) config('db.user') === '' ? 'wip' : 'maintenance';
    }

    $mode = setting('site_mode', 'wip');

    return in_array($mode, SITE_MODES, true) ? $mode : 'wip';
}

// ── Obsah ────────────────────────────────────────────────────────────────────

/** Termín sa po 2 hodinách od začiatku berie ako odohraný (zostane viditeľný, ale sivý). */
const PERFORMANCE_PAST_AFTER = '2 hours';

/**
 * „Práve hráme": položky zoznamu spolu s údajmi inscenácie z repertoáru.
 * Verejnosť vidí len zverejnené; prihlásený aj skryté (pripravuje ich).
 * Riadok = stĺpce inscenácie + run_id, run_public.
 */
function runs_for_page(bool $withHidden): array
{
    return db_all(
        'SELECT p.*, r.id AS run_id, r.is_public AS run_public, r.poster, r.price_sk, r.venue_sk, r.venue_url, r.venue_map_url
           FROM runs r
           JOIN productions p ON p.id = r.production_id
          WHERE r.deleted_at IS NULL AND p.deleted_at IS NULL' . ($withHidden ? '' : ' AND r.is_public') . '
          ORDER BY r.sort, r.id'
    );
}

/**
 * Všetko pre blok „Práve hráme" (ostrá stránka aj dočasné stránky):
 *   runs     položky (runs_for_page)
 *   dates    run_id → termíny (aj odohrané — tie sú sivé)
 *   upcoming budúce termíny zverejnených položiek — pre vyhľadávače a vetu o vstupenkách
 *
 * @return array{runs: array, dates: array<int, array>, upcoming: list<array{date: array, play: array}>}
 */
function now_playing(bool $withHidden): array
{
    $runs  = runs_for_page($withHidden);
    $dates = run_dates(array_column($runs, 'run_id'));

    $upcoming = [];
    foreach ($runs as $run) {
        foreach ($run['run_public'] ? ($dates[(int) $run['run_id']] ?? []) : [] as $pf) {
            if (!$pf['is_past']) {
                $upcoming[] = ['date' => $pf, 'play' => $run];
            }
        }
    }

    return ['runs' => $runs, 'dates' => $dates, 'upcoming' => $upcoming];
}

/**
 * Termíny položiek „Práve hráme" — aj odohrané (is_past), zoradené podľa času.
 *
 * @return array<int, array> run_id → termíny
 */
function run_dates(array $runIds): array
{
    if (!$runIds) {
        return [];
    }

    $marks = implode(', ', array_fill(0, count($runIds), '?'));
    $out = [];
    foreach (db_all(
        "SELECT pf.*, pf.starts_at < now() - interval '" . PERFORMANCE_PAST_AFTER . "' AS is_past
           FROM performances pf
          WHERE pf.run_id IN ($marks)
          ORDER BY pf.starts_at, pf.id",
        array_map('intval', $runIds)
    ) as $row) {
        $out[(int) $row['run_id']][] = $row;
    }

    return $out;
}

/**
 * Ukážka inscenácie: nahraté video má prednosť, inak odkaz na YouTube.
 *
 * @return array{kind: string, src: string}|null
 */
function play_trailer(array $p): ?array
{
    if (!empty($p['trailer'])) {
        return ['kind' => 'file', 'src' => media_url($p['trailer'])];
    }

    $url  = trim((string) ($p['trailer_url'] ?? ''));
    $info = $url !== '' ? video_info(['url' => $url, 'file' => null, 'poster' => null]) : null;

    return $info && $info['kind'] === 'youtube' ? ['kind' => 'youtube', 'src' => $info['embed']] : null;
}

/** Obrázky do galérie inscenácie (názvy súborov v assets/). */
function play_images(array $p): array
{
    $list = json_decode((string) ($p['images'] ?? '[]'), true);

    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

/**
 * História po rokoch — spoločné údaje pre obe záložky („Prehľad po rokoch" aj „Celá história").
 * Skladá sa z odohraných termínov všetkých položiek „Práve hráme" — aj skrytých
 * a tých v archíve (odohranú položku zvyčajne skryjete alebo dáte do koša, no
 * v histórii má ostať) — a z ručných záznamov (tabuľka history): inscenácia
 * z repertoáru alebo udalosť s vlastným názvom, nepovinne miesto, text a obrázok.
 * Ručný záznam s rovnakým rokom a inscenáciou sa pripojí k jej odohraným termínom.
 * Inscenácie v archíve sa nezobrazujú; skrytú inscenáciu (bez „Zobraziť verejnosti")
 * vidí len prihlásený — 'hidden' mu ju označí štítkom. Najnovší rok je prvý; v roku
 * idú najprv inscenácie podľa prvého termínu, potom ručné záznamy.
 *
 * @return array<int, list<array{title: string, hidden: bool, places: string[], texts: string[], images: string[], ids: int[]}>>
 */
function history_years(bool $withHidden): array
{
    $rows = db_all(
        "SELECT extract(year FROM pf.starts_at)::int AS year, p.id AS production_id, p.title_sk,
                pf.venue_sk, r.venue_sk AS run_venue_sk,
                NULL::int AS entry_id, NULL::text AS text_sk, NULL::varchar AS image,
                pf.starts_at AS sort_at, 0 AS sort, NOT p.is_public AS hidden
           FROM performances pf
           JOIN runs r ON r.id = pf.run_id
           JOIN productions p ON p.id = r.production_id
          WHERE p.deleted_at IS NULL" . ($withHidden ? '' : ' AND p.is_public') . "
            AND pf.starts_at < now() - interval '" . PERFORMANCE_PAST_AFTER . "'
      UNION ALL
         SELECT h.year, h.production_id,
                CASE WHEN h.production_id IS NULL THEN h.title_sk ELSE p.title_sk END,
                h.place_sk, NULL, h.id, h.text_sk, h.image, NULL, h.sort, coalesce(NOT p.is_public, false)
           FROM history h
      LEFT JOIN productions p ON p.id = h.production_id
          WHERE h.deleted_at IS NULL
            AND (h.production_id IS NULL OR (p.deleted_at IS NULL" . ($withHidden ? '' : ' AND p.is_public') . "))
       ORDER BY year DESC, sort_at NULLS LAST, sort, entry_id"
    );

    $out = [];
    foreach ($rows as $row) {
        $year = (int) $row['year'];
        // inscenácia = jedna položka v roku (termíny aj ručné záznamy k nej), udalosť = vlastná položka
        $key = $row['production_id'] !== null ? 'p' . $row['production_id'] : 'e' . $row['entry_id'];
        $out[$year][$key] ??= ['title' => tr($row, 'title'), 'hidden' => (bool) $row['hidden'], 'places' => [], 'texts' => [], 'images' => [], 'ids' => []];
        $item = &$out[$year][$key];

        // Miesto termínu → miesto položky „Práve hráme"; ručne zadané miesto je voľný text.
        $place = tr($row, 'venue');
        if ($place === '') {
            $place = tr(['venue_sk' => $row['run_venue_sk']], 'venue');
        }
        if ($place !== '' && !in_array($place, $item['places'], true)) {
            $item['places'][] = $place;
        }
        if ($row['entry_id'] !== null) {
            $item['ids'][] = (int) $row['entry_id'];
            $text = tr($row, 'text');
            if ($text !== '') {
                $item['texts'][] = $text;
            }
            if ($row['image']) {
                $item['images'][] = (string) $row['image'];
            }
        }
        unset($item);
    }

    return array_map('array_values', $out);
}

/** Text do jedného riadku (odseky a zalomenia → medzera). */
function text_flat(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/**
 * Skrátený text na kartu — celý je v okne s podrobnosťami.
 * Keď sa zmestí celý, vráti text_flat($text) (tak sa dá zistiť, či bol skrátený).
 */
function excerpt(string $text, int $max): string
{
    $text = text_flat($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    $cut   = mb_substr($text, 0, $max + 1);
    $space = mb_strrpos($cut, ' ');
    $cut   = $space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : mb_substr($text, 0, $max);

    return rtrim($cut, " \t,.;:–—-") . '…';
}

/** Záznamy zo zoznamu; zmazané (v archíve) nikdy, $where pridá ďalšie podmienky. */
function list_entity(string $entity, string $where = ''): array
{
    $def = entities()['entities'][$entity];
    $conditions = array_filter([!empty($def['soft_delete']) ? 'deleted_at IS NULL' : '', $where]);

    return db_all("SELECT * FROM $entity" . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') . ' ORDER BY ' . $def['order']);
}

/** Video: YouTube / Instagram / súbor → údaje na vykreslenie. */
function video_info(array $video): ?array
{
    $url = trim((string) $video['url']);

    // Doména (s ľubovoľnou subdoménou, veľké písmená nevadia) musí byť naozaj YouTube — nie
    // „nieyoutube.com" ani youtube.com niekde v ceste; patrí sem aj youtube-nocookie.com z „Vložiť video".
    if ($url !== '' && preg_match('~^(?:https?://)?(?:[a-z0-9-]+\.)*(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m)) {
        return [
            'kind'   => 'youtube',
            'id'     => $m[1],
            'href'   => 'https://www.youtube.com/watch?v=' . $m[1],
            'embed'  => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0',
            'poster' => $video['poster'] ? media_url($video['poster']) : 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg',
        ];
    }

    if ($url !== '' && preg_match('~instagram\.com/(?:[A-Za-z0-9_.]+/)?(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)~', $url)) {
        return ['kind' => 'instagram', 'href' => $url, 'poster' => media_url($video['poster'])];
    }

    if ($video['file']) {
        $poster = $video['poster'];
        if (!$poster) {
            // Náhľad, ktorý vznikol pri konverzii videa (rovnaký názov, .avif).
            $guess = pathinfo((string) $video['file'], PATHINFO_FILENAME) . '.avif';
            $poster = is_file(ROOT . '/assets/' . $guess) ? $guess : '';
        }
        return ['kind' => 'file', 'src' => media_url($video['file']), 'poster' => media_url($poster)];
    }

    if ($url !== '') {
        return ['kind' => 'link', 'href' => $url, 'poster' => media_url($video['poster'])];
    }

    return null;
}

// ── Ovládacie prvky úprav (len pre prihlásených) ─────────────────────────────

/**
 * Ukázať ceruzky a ďalšie ovládanie úprav? Len prihlásenému. cms_on(false) ich
 * vypne do konca požiadavky — dočasná stránka je vždy taká, akú ju vidí návštevník.
 */
function cms_on(?bool $set = null): bool
{
    static $allowed = true;
    if ($set !== null) {
        $allowed = $set;
    }

    return $allowed && current_user() !== null;
}

const CMS_ICONS = [
    'edit'   => '<path d="M4 20h4L19 9l-4-4L4 16v4z" /><path d="M13.5 6.5l4 4" />',
    'add'    => '<path d="M12 5v14M5 12h14" />',
    'up'     => '<path d="M12 19V5M6 11l6-6 6 6" />',
    'down'   => '<path d="M12 5v14M6 13l6 6 6-6" />',
    'left'   => '<path d="M19 12H5M11 6l-6 6 6 6" />',
    'right'  => '<path d="M5 12h14M13 6l6 6-6 6" />',
    'delete' => '<path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13" />',
];

function cms_icon(string $name): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . CMS_ICONS[$name] . '</svg>';
}

function cms_button(string $action, string $label, array $data = [], string $icon = ''): string
{
    return '<button type="button" class="cms-btn" data-cms-action="' . e($action) . '"' . cms_data_attrs($data)
        . ' title="' . e($label) . '" aria-label="' . e($label) . '">' . cms_icon($icon ?: $action) . '</button>';
}

/**
 * Ceruzka, posun a zmazanie pri položke. $horizontal: položky idú vedľa seba
 * (karusel v jednom riadku) — šípky posunu ukazujú doľava / doprava.
 */
function cms_controls(string $entity, int $id, string $extraClass = '', bool $horizontal = false, bool $movable = true): string
{
    if (!cms_on()) {
        return '';
    }

    $def  = entities()['entities'][$entity];
    $data = ['entity' => $entity, 'id' => $id];
    $html = cms_button('edit', t('cms_edit'), $data);
    if ($def['sortable'] && $movable) {
        $html .= cms_button('up', t($horizontal ? 'cms_move_left' : 'cms_move_up'), $data + ['dir' => 'up'], $horizontal ? 'left' : 'up');
        $html .= cms_button('down', t($horizontal ? 'cms_move_right' : 'cms_move_down'), $data + ['dir' => 'down'], $horizontal ? 'right' : 'down');
    }
    // Kôš: väčšinou presun do archívu (obnoviteľné v administrácii), termín sa zmaže hneď.
    $soft = !empty($def['soft_delete']);
    $confirm = $soft ? t($entity === 'productions' ? 'cms_confirm_archive_production' : 'cms_confirm_archive') : t('js_confirm_delete');
    $html .= cms_button('delete', t($soft ? 'cms_archive' : 'cms_delete'), $data + ['confirm' => $confirm]);

    return '<div class="cms-bar ' . e($extraClass) . '">' . $html . '</div>';
}

/**
 * Štítky pre prihlásených — aby bolo vidieť, čo je skryté pred verejnosťou
 * a čo už nehráme. Návštevník ich nevidí. $flags = ['hidden' => bool, 'retired' => bool]
 */
function cms_flags(array $flags): string
{
    if (!cms_on()) {
        return '';
    }

    $html = '';
    foreach ($flags as $flag => $on) {
        if ($on) {
            $html .= '<span class="cms-flag cms-flag--' . e($flag) . '">' . e(t('flag_' . $flag)) . '</span>';
        }
    }

    return $html === '' ? '' : '<div class="cms-flags">' . $html . '</div>';
}

/** Textové tlačidlo s ceruzkou (napr. „Upraviť inscenáciu" pri položke „Práve hráme"). */
function cms_edit_link(string $entity, int $id, string $labelKey): string
{
    if (!cms_on()) {
        return '';
    }

    return '<button type="button" class="cms-add cms-add--inline" data-cms-action="edit"' . cms_data_attrs(['entity' => $entity, 'id' => $id]) . '>'
        . cms_icon('edit') . '<span>' . e(t($labelKey)) . '</span></button>';
}

/** Tlačidlo „pridať" pre zoznam. $preset = predvyplnené hodnoty. */
function cms_add(string $entity, string $labelKey, array $preset = []): string
{
    if (!cms_on()) {
        return '';
    }

    $data = ['entity' => $entity];
    if ($preset) {
        $data['preset'] = $preset;
    }

    return '<button type="button" class="cms-add" data-cms-action="add"' . cms_data_attrs($data) . '>'
        . cms_icon('add') . '<span>' . e(t($labelKey)) . '</span></button>';
}

/** Ceruzka pre skupinu nastavení (texty, kontakt, odkazy). */
function cms_settings(string $group, string $extraClass = ''): string
{
    if (!cms_on()) {
        return '';
    }

    return '<div class="cms-bar ' . e($extraClass) . '">'
        . cms_button('settings', t('cms_edit_texts'), ['group' => $group], 'edit') . '</div>';
}

function cms_data_attrs(array $data): string
{
    $attrs = '';
    foreach ($data as $k => $v) {
        $attrs .= ' data-' . $k . '="' . e(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) . '"';
    }

    return $attrs;
}
