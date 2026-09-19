<?php
/**
 * Ukladanie obsahu z okna úprav. Všetko ide cez popis v entities.php —
 * stĺpce mimo neho sa nikdy nezapíšu.
 */

declare(strict_types=1);

require_once __DIR__ . '/media.php';

final class CmsError extends InvalidArgumentException
{
    public ?string $field;

    public function __construct(string $message, ?string $field = null)
    {
        parent::__construct($message);
        $this->field = $field;
    }
}

/** Nastavenia pre static/js/cms.js (vkladajú sa do stránky ako JSON). */
function cms_client_config(): string
{
    $config = [
        'api'       => '/api.php',
        'csrf'      => csrf_token(),
        'lang'      => lang(),
        'chunkSize' => upload_chunk_size(),
        'maxSize'   => (int) config('upload.max_size'),
        'accept'    => array_keys(MEDIA_TYPES),
        'strings'   => t_prefix('js_'),
    ];

    return '<script type="application/json" id="cms-config">'
        . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . "</script>\n"
        . '<script src="' . e(asset_version('static/js/cms.js')) . '" defer></script>' . "\n";
}

function cms_def(string $entity): array
{
    $def = entities()['entities'][$entity] ?? null;
    if ($def === null) {
        throw new CmsError(t('cms_err_unknown'));
    }

    return $def;
}

/** Pole → stĺpec v tabuľke (prekladané pole má príponu jazyka). */
function cms_columns_of(string $field, array $f): array
{
    $lang = (string) config('default_lang');

    return empty($f['i18n']) ? [$field => null] : [$field . '_' . $lang => $lang];
}

/** Popis formulára pre JavaScript. */
function cms_form_schema(array $fields, array $placeholders = []): array
{
    $out = [];
    foreach ($fields as $name => $f) {
        $item = [
            'name'     => $name,
            'type'     => $f['type'],
            'i18n'     => !empty($f['i18n']),
            'label'    => t($f['label'] ?? 'f_' . $name),
            'required' => !empty($f['required']),
        ];
        foreach (['accept', 'min', 'max'] as $k) {
            if (isset($f[$k])) {
                $item[$k] = $f[$k];
            }
        }
        if (!empty($f['hint'])) {
            $item['hint'] = t($f['hint']);
        }
        if ($f['type'] === 'select') {
            $item['options'] = isset($f['choices'])
                ? array_map(static fn ($value, $label) => ['value' => $value, 'label' => t($label)], array_keys($f['choices']), $f['choices'])
                : cms_options($f['options']);
        }
        $item['placeholders'] = (object) array_intersect_key($placeholders, cms_columns_of($name, $f));
        $out[] = $item;
    }

    return $out;
}

/**
 * Možnosti pre výber. Záznamy v archíve sa vybrať nedajú; pri inscenáciách je
 * v zátvorke vidieť, či sú skryté / už ich nehráme.
 */
function cms_options(string $entity): array
{
    cms_def($entity);

    switch ($entity) {
        case 'productions':
            $rows = db_all('SELECT id, title_sk AS label, NOT is_public AS hidden, is_retired AS retired
                              FROM productions WHERE deleted_at IS NULL ORDER BY sort, id');
            break;
        case 'runs':
            // Termín patrí k položke „Práve hráme" — pomenovaná podľa inscenácie.
            $rows = db_all('SELECT r.id, p.title_sk AS label, NOT r.is_public AS hidden, false AS retired
                              FROM runs r JOIN productions p ON p.id = r.production_id
                             WHERE r.deleted_at IS NULL AND p.deleted_at IS NULL ORDER BY r.sort, r.id');
            break;
        default:
            $rows = db_all("SELECT id, name AS label, false AS hidden, false AS retired FROM $entity ORDER BY id");
    }

    $out = [];
    foreach ($rows as $row) {
        $marks = array_filter([$row['hidden'] ? t('mark_hidden') : '', $row['retired'] ? t('mark_retired') : '']);
        $out[] = ['value' => (int) $row['id'], 'label' => $row['label'] . ($marks ? ' (' . implode(', ', $marks) . ')' : '')];
    }

    return $out;
}

/** Podmienka „nie je v archíve" pre entity s mäkkým mazaním. */
function cms_alive(array $def): string
{
    return !empty($def['soft_delete']) ? ' AND deleted_at IS NULL' : '';
}

/** Riadok pre formulár: hodnoty podľa stĺpcov, dátumy vo formáte pre <input>. */
function cms_row_for_form(array $def, array $row): array
{
    $out = ['id' => (int) $row['id']];
    foreach ($def['fields'] as $name => $f) {
        foreach (cms_columns_of($name, $f) as $column => $code) {
            $value = $row[$column] ?? null;
            if ($f['type'] === 'datetime' && $value) {
                $value = date('Y-m-d\TH:i', strtotime((string) $value));
            } elseif ($f['type'] === 'bool') {
                $value = (bool) $value;
            } elseif ($f['type'] === 'files' || $f['type'] === 'people') {
                $value = json_decode((string) $value, true) ?: [];
            } elseif ($f['type'] === 'richtext') {
                $value = rich_html((string) $value);
            }
            $out[$column] = $value;
        }
    }

    return $out;
}

function cms_get(string $entity, int $id): array
{
    $def = cms_def($entity);
    $row = db_one("SELECT * FROM $entity WHERE id = ?" . cms_alive($def), [$id]);
    if (!$row) {
        throw new CmsError(t('cms_err_not_found'));
    }

    return cms_row_for_form($def, $row);
}

/** Prázdny záznam s predvolenými hodnotami (pre „pridať"). */
function cms_blank(string $entity, array $preset = []): array
{
    $def = cms_def($entity);
    $out = ['id' => null];
    foreach ($def['fields'] as $name => $f) {
        foreach (cms_columns_of($name, $f) as $column => $code) {
            $empty = ['bool' => false, 'files' => [], 'people' => []][$f['type']] ?? null;
            $out[$column] = $preset[$column] ?? ($f['default'] ?? $empty);
        }
    }

    return $out;
}

/**
 * Overí a upraví jednu hodnotu. Vracia hodnotu pre databázu (reťazec alebo null).
 */
function cms_value(string $column, array $f, $raw, bool $required)
{
    $type = $f['type'];

    if ($type === 'bool') {
        return ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true' || $raw === 'on') ? 't' : 'f';
    }

    // Viac súborov (napr. galéria inscenácie): zoznam názvov → JSON do stĺpca jsonb.
    if ($type === 'files') {
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $out = [];
        foreach ($list as $name) {
            $safe = is_string($name) ? media_safe_name($name) : null;
            if ($safe === null || !is_file(media_dir() . '/' . $safe)) {
                throw new CmsError(t('cms_err_file'), $column);
            }
            if (($f['accept'] ?? 'any') !== 'any' && media_type($safe) !== $f['accept']) {
                throw new CmsError(t('cms_err_file_type'), $column);
            }
            $out[] = $safe;
        }

        return json_encode(array_slice(array_values(array_unique($out)), 0, (int) ($f['max'] ?? 100)));
    }

    // Ľudia v skupine súboru: [{name, since}] → JSON do stĺpca jsonb. Úplne prázdny
    // riadok sa vynechá; meno je povinné, rok nepovinný.
    if ($type === 'people') {
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $out = [];
        foreach ($list as $row) {
            $name  = is_array($row) && is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '';
            $since = is_array($row) && is_scalar($row['since'] ?? null) ? trim((string) $row['since']) : '';
            if ($name === '' && $since === '') {
                continue;
            }
            if ($name === '') {
                throw new CmsError(t('cms_err_person_name'), $column);
            }
            if ($since !== '' && (!preg_match('/^\d{4}$/', $since) || (int) $since < 1900 || (int) $since > 2100)) {
                throw new CmsError(t('cms_err_person_since', $name), $column);
            }
            $out[] = ['name' => mb_substr($name, 0, 120), 'since' => $since === '' ? null : (int) $since];
        }
        if (count($out) > (int) ($f['max'] ?? 100)) {
            throw new CmsError(t('cms_err_people_max', (int) ($f['max'] ?? 100)), $column);
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    $value = is_scalar($raw) ? trim(str_replace("\r\n", "\n", (string) $raw)) : '';

    if ($value === '') {
        if ($required) {
            throw new CmsError(t('cms_err_required'), $column);
        }
        return null;
    }

    switch ($type) {
        case 'text':
        case 'textarea':
            return mb_substr($value, 0, (int) ($f['max'] ?? 10000));

        // Text z editora: ostane len povolené HTML (bootstrap.php → rich_html). Orezať
        // sa nedá (rozbilo by značky), preto je dlhý text chyba.
        case 'richtext':
            $html = rich_html($value);
            if ($html === '') {
                if ($required) {
                    throw new CmsError(t('cms_err_required'), $column);
                }
                return null;
            }
            if (mb_strlen($html) > (int) ($f['max'] ?? 20000)) {
                throw new CmsError(t('cms_err_too_long', (int) ($f['max'] ?? 20000)), $column);
            }
            return $html;

        case 'number':
            if (!preg_match('/^-?\d+$/', $value)
                || (isset($f['min']) && (int) $value < $f['min'])
                || (isset($f['max']) && (int) $value > $f['max'])) {
                throw new CmsError(t('cms_err_number', $f['min'] ?? '', $f['max'] ?? ''), $column);
            }
            return (string) (int) $value;

        case 'date':
            $d = DateTime::createFromFormat('!Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) {
                throw new CmsError(t('cms_err_date'), $column);
            }
            return $value;

        case 'datetime':
            $d = DateTime::createFromFormat('Y-m-d\TH:i', substr($value, 0, 16)) ?: DateTime::createFromFormat('Y-m-d H:i', substr($value, 0, 16));
            if (!$d) {
                throw new CmsError(t('cms_err_date'), $column);
            }
            return $d->format('Y-m-d H:i:00');

        case 'url':
            if (!preg_match('~^https?://~i', $value)) {
                $value = 'https://' . $value;
            }
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                throw new CmsError(t('cms_err_url'), $column);
            }
            return mb_substr($value, 0, 500);

        case 'select':
            if (isset($f['choices'])) {
                if (!array_key_exists($value, $f['choices'])) {
                    throw new CmsError(t('cms_err_required'), $column);
                }
                return $value;
            }
            $ids = array_column(cms_options($f['options']), 'value');
            if (!in_array((int) $value, $ids, true)) {
                throw new CmsError(t('cms_err_required'), $column);
            }
            return (string) (int) $value;

        case 'file':
            $name = media_safe_name($value);
            if ($name === null || !is_file(media_dir() . '/' . $name)) {
                throw new CmsError(t('cms_err_file'), $column);
            }
            $accept = $f['accept'] ?? 'any';
            if ($accept !== 'any' && media_type($name) !== $accept) {
                throw new CmsError(t('cms_err_file_type'), $column);
            }
            return $name;
    }

    throw new CmsError(t('cms_err_unknown'), $column);
}

/** Uloží záznam (nový, keď $id je null). Vracia jeho id. */
function cms_save(string $entity, ?int $id, array $input): int
{
    $def = cms_def($entity);
    $data = [];

    foreach ($def['fields'] as $name => $f) {
        foreach (cms_columns_of($name, $f) as $column => $code) {
            // Pri prekladanom poli je povinná len predvolená (slovenská) verzia.
            $required = !empty($f['required']) && ($code === null || $code === config('default_lang'));
            $data[$column] = cms_value($column, $f, $input[$column] ?? null, $required);
        }
    }

    if ($entity === 'videos') {
        if ($data['url'] === null && $data['file'] === null) {
            throw new CmsError(t('cms_err_video'), 'url');
        }
        // Náhľad YouTube videa si stiahneme, aby stránka nenačítavala obrázky z Google.
        $info = $data['url'] !== null ? video_info(['url' => $data['url'], 'file' => null, 'poster' => null]) : null;
        if ($data['poster'] === null && $info && $info['kind'] === 'youtube') {
            $data['poster'] = media_youtube_poster($info['id']);
        }
    }

    // História: záznam je inscenácia z repertoáru alebo udalosť s vlastným názvom.
    if ($entity === 'history' && $data['production_id'] === null && $data['title_sk'] === null) {
        throw new CmsError(t('cms_err_history'), 'title_sk');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id === null) {
            if ($def['sortable']) {
                // nový záznam na koniec, pri 'new_first' (galéria) na začiatok
                $data['sort'] = (string) (!empty($def['new_first'])
                    ? (int) db_value("SELECT coalesce(min(sort), 1) FROM $entity") - 1
                    : (int) db_value("SELECT coalesce(max(sort), 0) FROM $entity") + 1);
            }
            $cols = array_keys($data);
            $id = (int) db_value(
                "INSERT INTO $entity (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') RETURNING id',
                array_values($data)
            );
        } else {
            $sets = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($data)));
            if (!db_exec("UPDATE $entity SET $sets, updated_at = now() WHERE id = ?" . cms_alive($def), array_merge(array_values($data), [$id]))) {
                throw new CmsError(t('cms_err_not_found'));
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $id;
}

/**
 * Kôš na stránke: záznam s mäkkým mazaním sa presunie do archívu (dá sa obnoviť
 * v administrácii), ostatné (termíny) sa zmažú hneď.
 */
function cms_delete(string $entity, int $id): void
{
    $def = cms_def($entity);
    $done = !empty($def['soft_delete'])
        ? db_exec("UPDATE $entity SET deleted_at = now() WHERE id = ? AND deleted_at IS NULL", [$id])
        : db_exec("DELETE FROM $entity WHERE id = ?", [$id]);
    if (!$done) {
        throw new CmsError(t('cms_err_not_found'));
    }
}

/** Posun v poradí o jedno miesto hore / dole. */
function cms_move(string $entity, int $id, string $dir): void
{
    $def = cms_def($entity);
    if (!$def['sortable']) {
        return;
    }

    $conditions = array_filter([!empty($def['soft_delete']) ? 'deleted_at IS NULL' : '', $def['move_scope'] ?? '']);
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $ids = array_map('intval', array_column(db_all("SELECT id FROM $entity$where ORDER BY " . $def['order']), 'id'));
    $pos = array_search($id, $ids, true);
    if ($pos === false) {
        throw new CmsError(t('cms_err_not_found'));
    }

    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($ids)) {
        return;
    }
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE $entity SET sort = ? WHERE id = ?");
    foreach ($ids as $i => $rowId) {
        $stmt->execute([$i + 1, $rowId]);
    }
    $pdo->commit();
}

// ── Archív (kôš na stránke) ──────────────────────────────────────────────────

/**
 * Obsah archívu po skupinách, najnovšie prvé. „Práve hráme" je zároveň
 * história hrania: inscenácia + od kedy do kedy a koľko termínov.
 */
function cms_archive(): array
{
    return [
        'runs' => db_all(
            "SELECT r.id, p.title_sk AS label, coalesce(r.poster, p.image) AS image, r.deleted_at, p.deleted_at IS NOT NULL AS parent_archived,
                    min(pf.starts_at) AS first_date, max(pf.starts_at) AS last_date, count(pf.id) AS dates
               FROM runs r
               JOIN productions p ON p.id = r.production_id
          LEFT JOIN performances pf ON pf.run_id = r.id
              WHERE r.deleted_at IS NOT NULL
              GROUP BY r.id, p.title_sk, r.poster, p.image, p.deleted_at
              ORDER BY max(pf.starts_at) DESC NULLS LAST, r.deleted_at DESC"
        ),
        'productions' => db_all(
            'SELECT p.id, p.title_sk AS label, p.image, p.deleted_at,
                    (SELECT count(*) FROM runs r WHERE r.production_id = p.id) AS runs
               FROM productions p WHERE p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC'
        ),
        'ensemble_groups' => db_all(
            "SELECT g.id, g.name_sk || coalesce(' — ' || (SELECT string_agg(p.person->>'name', ', ' ORDER BY p.ord)
                                                           FROM jsonb_array_elements(g.people) WITH ORDINALITY AS p (person, ord)), '') AS label,
                    NULL AS image, g.deleted_at
               FROM ensemble_groups g WHERE g.deleted_at IS NOT NULL ORDER BY g.deleted_at DESC"
        ),
        'photos'  => db_all("SELECT id, coalesce(nullif(caption_sk, ''), image) AS label, image, deleted_at FROM photos WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"),
        'videos'  => db_all("SELECT id, coalesce(nullif(title_sk, ''), url, file) AS label, poster AS image, deleted_at FROM videos WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"),
        'history' => db_all(
            "SELECT h.id, h.year || ' — ' || coalesce(p.title_sk, h.title_sk) || coalesce(' · ' || h.place_sk, '') AS label, h.image, h.deleted_at
               FROM history h LEFT JOIN productions p ON p.id = h.production_id
              WHERE h.deleted_at IS NOT NULL ORDER BY h.deleted_at DESC"
        ),
    ];
}

/** Vráti záznam z archívu späť na stránku. */
function cms_restore(string $entity, int $id): void
{
    $def = cms_def($entity);
    if (empty($def['soft_delete']) || !db_exec("UPDATE $entity SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL", [$id])) {
        throw new CmsError(t('cms_err_not_found'));
    }
}

/**
 * Zmaže natrvalo — len to, čo už je v archíve. Pri inscenácii odídu aj jej
 * položky „Práve hráme" a termíny (cudzí kľúč ON DELETE CASCADE).
 */
function cms_purge(string $entity, int $id): void
{
    $def = cms_def($entity);
    if (empty($def['soft_delete']) || !db_exec("DELETE FROM $entity WHERE id = ? AND deleted_at IS NOT NULL", [$id])) {
        throw new CmsError(t('cms_err_not_found'));
    }
}

// ── Nastavenia ───────────────────────────────────────────────────────────────

function cms_settings_def(string $group): array
{
    $def = entities()['settings'][$group] ?? null;
    if ($def === null) {
        throw new CmsError(t('cms_err_unknown'));
    }

    return $def;
}

/** Čo sa zobrazí, keď je pole prázdne — vo formulári ako placeholder (stĺpec → text). */
function cms_settings_placeholders(array $fields): array
{
    $out = [];
    foreach ($fields as $name => $f) {
        if (!empty($f['i18n'])) {
            if (t_has('default_' . $name)) {
                $out[$name . '_' . config('default_lang')] = default_text($name);
            }
        } elseif (($v = config('contact.' . $name) ?? config('links.' . $name)) !== null && $v !== '') {
            $out[$name] = (string) $v;
        } elseif ($name === 'founded') {
            $out[$name] = (string) config('founded');
        } elseif (isset($f['default'])) {
            $out[$name] = (string) $f['default'];
        }
    }

    return $out;
}

/**
 * Hodnoty do formulára — presne to, čo je teraz na stránke (pozri setting_tr a contact):
 *   - kontakty, odkazy, čísla: uložená hodnota, prázdna = predvolená z config.php;
 *   - texty: uložený text (aj prázdny = skrytý); kým ho nikto neuložil, predvolený
 *     z lang.php. Text pre editor (richtext) ide ako vyčistené HTML — aj starší
 *     obyčajný text sa tak v editore zobrazí po odsekoch.
 */
function cms_settings_values(string $group): array
{
    $fields   = cms_settings_def($group);
    $defaults = cms_settings_placeholders($fields);
    $stored   = settings_all();
    $mainLang = (string) config('default_lang');

    $values = [];
    foreach ($fields as $name => $f) {
        foreach (cms_columns_of($name, $f) as $key => $code) {
            $value = (string) ($stored[$key] ?? '');
            if ($code === null) {
                if ($value === '') {
                    $value = $defaults[$key] ?? '';
                }
            } elseif (!array_key_exists($key, $stored)
                && ($code === $mainLang || !array_key_exists($name . '_' . $mainLang, $stored))) {
                $value = $defaults[$key] ?? '';
            }
            $values[$key] = $f['type'] === 'richtext' ? rich_html($value) : $value;
        }
    }

    return $values;
}

function cms_settings_save(string $group, array $input): void
{
    $values = [];
    foreach (cms_settings_def($group) as $name => $f) {
        foreach (cms_columns_of($name, $f) as $key => $code) {
            $values[$key] = cms_value($key, $f, $input[$key] ?? null, false);
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    foreach ($values as $key => $value) {
        save_setting($key, $value);
    }
    $pdo->commit();
}
