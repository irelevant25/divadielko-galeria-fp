<?php
/**
 * JSON API pre úpravy na stránke (static/js/cms.js) a kontaktný formulár.
 *
 *   POST ?action=contact             verejné — kontaktný formulár
 *
 *   GET  ?action=item&entity=&id=    záznam + popis formulára
 *   GET  ?action=blank&entity=       prázdny záznam (pridanie)
 *   GET  ?action=settings&group=     skupina voľných textov
 *   GET  ?action=files&type=         súbory v assets/
 *   POST ?action=save                {entity, id, values}
 *   POST ?action=delete              {entity, id}
 *   POST ?action=move                {entity, id, dir}
 *   POST ?action=settings_save       {group, values}
 *   POST ?action=upload              jeden kúsok súboru (multipart)
 *
 * Všetko okrem contact vyžaduje prihlásenie; POST navyše CSRF token v hlavičke X-CSRF-Token.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/cms.php';
require __DIR__ . '/includes/mail.php';

private_headers();

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Kontaktný formulár ───────────────────────────────────────────────────────

if ($action === 'contact') {
    // Bez JavaScriptu prehliadač odošle klasický formulár → výsledok ukážeme presmerovaním.
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    if ($method !== 'POST') {
        redirect('/#kontakt');
    }

    try {
        $result = db_available() ? contact_submit($_POST) : ['error' => 'cf_err_server'];
    } catch (Throwable $e) {
        error_log('[contact] ' . $e->getMessage());
        $result = ['error' => 'cf_err_server'];
    }

    if ($wantsJson) {
        json_response(isset($result['ok'])
            ? ['ok' => true, 'message' => t('cf_ok')]
            : ['error' => t($result['error']), 'field' => $result['field'] ?? null], isset($result['ok']) ? 200 : 422);
    }
    redirect('/?cf=' . (isset($result['ok']) ? 'ok' : $result['error']) . '#kontakt');
}

// ── Všetko ostatné len pre prihlásených ──────────────────────────────────────

$user = current_user();
if ($user === null) {
    json_response(['error' => t('cms_err_login')], 401);
}
if ($method === 'POST' && !csrf_valid()) {
    json_response(['error' => t('cms_err_csrf')], 419);
}

// API do session nezapisuje. Uvoľnením zámku session sa počas dlhej konverzie
// videa nezablokujú ostatné požiadavky toho istého používateľa.
session_write_close();

/** Telo požiadavky ako JSON (fetch posiela application/json). */
function input(): array
{
    static $data = null;
    if ($data === null) {
        $data = json_decode((string) file_get_contents('php://input'), true);
        $data = is_array($data) ? $data : $_POST;
    }

    return $data;
}

function entity_param(string $from = 'get'): string
{
    $value = $from === 'get' ? ($_GET['entity'] ?? '') : (input()['entity'] ?? '');

    return is_string($value) ? $value : '';
}

try {
    switch ("$method $action") {
        case 'GET item':
            $entity = entity_param();
            $def = cms_def($entity);
            json_response([
                'title'  => t('ent_' . $entity),
                'item'   => cms_get($entity, (int) ($_GET['id'] ?? 0)),
                'schema' => cms_form_schema($def['fields']),
            ]);

        case 'GET blank':
            $entity = entity_param();
            $def = cms_def($entity);
            $preset = json_decode((string) ($_GET['preset'] ?? ''), true);
            json_response([
                'title'  => t('ent_' . $entity),
                'item'   => cms_blank($entity, is_array($preset) ? $preset : []),
                'schema' => cms_form_schema($def['fields']),
            ]);

        case 'GET settings':
            $group = (string) ($_GET['group'] ?? '');
            $fields = cms_settings_def($group);
            json_response([
                'title'  => t('sg_' . $group),
                'item'   => cms_settings_values($group),
                'schema' => cms_form_schema($fields, cms_settings_placeholders($fields)),
            ]);

        case 'GET files':
            $type = (string) ($_GET['type'] ?? '');
            json_response(['files' => media_list(in_array($type, ['image', 'video', 'audio'], true) ? $type : '')]);

        case 'POST save':
            $in = input();
            $id = isset($in['id']) && $in['id'] !== null && $in['id'] !== '' ? (int) $in['id'] : null;
            $id = cms_save(entity_param('post'), $id, is_array($in['values'] ?? null) ? $in['values'] : []);
            json_response(['ok' => true, 'id' => $id]);

        case 'POST delete':
            cms_delete(entity_param('post'), (int) (input()['id'] ?? 0));
            json_response(['ok' => true]);

        case 'POST move':
            cms_move(entity_param('post'), (int) (input()['id'] ?? 0), (input()['dir'] ?? '') === 'up' ? 'up' : 'down');
            json_response(['ok' => true]);

        case 'POST settings_save':
            $in = input();
            cms_settings_save((string) ($in['group'] ?? ''), is_array($in['values'] ?? null) ? $in['values'] : []);
            json_response(['ok' => true]);

        case 'POST upload':
            json_response(upload_chunk($_POST, $_FILES, (int) $user['id']));
    }

    json_response(['error' => t('cms_err_unknown')], 404);
} catch (CmsError $e) {
    json_response(['error' => $e->getMessage(), 'field' => $e->field], 422);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 422);
} catch (PDOException $e) {
    error_log('[api] ' . $e->getMessage());
    json_response(['error' => t('cms_err_db')], 500);
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_response(['error' => t('js_error')], 500);
}
