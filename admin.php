<?php
/**
 * Administrácia: správy z formulára, súbory, používatelia, režim stránky, vlastný účet.
 * Obsah stránky (texty, inscenácie, fotky, …) sa upravuje priamo na stránke ceruzkou.
 *
 * Na stránke naň nevedie odkaz (okrem lišty pre prihlásených) — otvára sa zadaním /admin.php.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/cms.php';
require __DIR__ . '/includes/backup.php';

private_headers();

$user = current_user();
if ($user === null) {
    redirect('/login.php?next=' . rawurlencode('/admin.php' . (isset($_GET['tab']) ? '?tab=' . (string) $_GET['tab'] : '')));
}
$admin = is_admin();

$tabs = ['messages' => 'adm_tab_messages', 'files' => 'adm_tab_files', 'archive' => 'adm_tab_archive', 'sections' => 'adm_tab_sections'];
if ($admin) {
    $tabs += ['users' => 'adm_tab_users', 'backups' => 'adm_tab_backups', 'settings' => 'adm_tab_settings'];
}
$tabs['account'] = 'adm_tab_account';

$tab = (string) ($_GET['tab'] ?? 'messages');
if (!isset($tabs[$tab])) {
    $tab = 'messages';
}

function flash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function back(string $tab, string $query = ''): void
{
    redirect('/admin.php?tab=' . $tab . ($query !== '' ? '&' . $query : ''));
}

function forbid(): void
{
    http_response_code(403);
    exit(e(t('cms_err_forbidden')));
}

// ── Stiahnutie originálu ─────────────────────────────────────────────────────

if (isset($_GET['download'])) {
    $name = basename(str_replace('\\', '/', (string) $_GET['download']));
    $path = originals_dir() . '/' . $name;
    if ($name === '' || $name[0] === '.' || media_type($name) === null || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: attachment; filename=\"" . str_replace('"', '', $name) . '"');
    readfile($path);
    exit;
}

// ── Stiahnutie zálohy (obsahuje aj používateľov a správy — len administrátor) ─

if (isset($_GET['backup'])) {
    $admin || forbid();
    try {
        $path = backup_path((string) $_GET['backup']);
    } catch (Throwable $e) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

// ── Akcie (POST → presmerovanie späť) ────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valid()) {
        flash('error', t('cms_err_csrf'));
        back($tab);
    }

    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        switch ($do) {
            case 'msg_status':
                $status = (string) ($_POST['status'] ?? '');
                if (in_array($status, ['new', 'read', 'archived', 'spam'], true)) {
                    db_exec('UPDATE messages SET status = ? WHERE id = ?', [$status, $id]);
                }
                back('messages', http_build_query(['status' => (string) ($_POST['filter'] ?? '')]) . '#m' . $id);

            case 'msg_delete':
                $admin || forbid();
                db_exec('DELETE FROM messages WHERE id = ?', [$id]);
                flash('ok', t('adm_deleted'));
                back('messages', http_build_query(['status' => (string) ($_POST['filter'] ?? '')]));

            case 'file_delete':
                $admin || forbid();
                media_delete((string) ($_POST['name'] ?? ''));
                flash('ok', t('adm_deleted'));
                back('files');

            case 'file_convert':
                $name = basename(str_replace('\\', '/', (string) ($_POST['name'] ?? '')));
                $path = originals_dir() . '/' . $name;
                if ($name === '' || $name[0] === '.' || media_type($name) === null || !is_file($path)) {
                    throw new InvalidArgumentException(t('cms_err_file'));
                }
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $base = pathinfo($name, PATHINFO_FILENAME);
                // Súbor z FTP môže mať v názve medzery či diakritiku — premenujeme ho.
                if (media_slug($name) !== $base) {
                    $base = media_unique_base(media_slug($name));
                    rename($path, originals_dir() . '/' . $base . '.' . $ext);
                    $path = originals_dir() . '/' . $base . '.' . $ext;
                }
                $result = media_convert($path, $base);
                if ($result === null) {
                    throw new InvalidArgumentException(t('up_err_convert'));
                }
                flash('ok', t('adm_converted', $result['file']) . ($result['converted'] ? '' : ' — ' . t('js_not_converted')));
                back('files');

            case 'arch_restore':
                cms_restore((string) ($_POST['entity'] ?? ''), $id);
                flash('ok', t('adm_restored'));
                back('archive');

            case 'arch_purge':
                $admin || forbid();
                cms_purge((string) ($_POST['entity'] ?? ''), $id);
                flash('ok', t('adm_deleted'));
                back('archive');

            case 'sections_save':
                // Poradie sekcií (= menu) a názvy v menu. Šípky formulár odošlú celý,
                // takže rozpísané názvy sa pri posune neztratia.
                $order = array_values(array_unique(array_intersect(array_map('strval', (array) ($_POST['order'] ?? [])), SECTIONS)));
                if (preg_match('/^([a-z]+):(up|down)$/', (string) ($_POST['move'] ?? ''), $mv)
                    && ($pos = array_search($mv[1], $order, true)) !== false) {
                    $swap = $mv[2] === 'up' ? $pos - 1 : $pos + 1;
                    // Domov (na začiatku) ostáva prvý.
                    if ($pos > 0 && $swap > 0 && $swap < count($order)) {
                        [$order[$pos], $order[$swap]] = [$order[$swap], $order[$pos]];
                    }
                }
                save_setting('section_order', implode(',', $order));
                cms_settings_save('nav', (array) ($_POST['nav'] ?? []));
                flash('ok', t('adm_saved'));
                back('sections', isset($mv[1]) ? 'moved=' . $mv[1] : '');

            case 'user_save':
                $admin || forbid();
                $email = trim((string) ($_POST['email'] ?? ''));
                $role  = in_array($_POST['role'] ?? '', ROLES, true) ? $_POST['role'] : 'editor';
                $active = !empty($_POST['active']);
                $password = (string) ($_POST['password'] ?? '');

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException(t('adm_user_bad_email'));
                }
                if ($password !== '' && ($problem = password_problem($password))) {
                    throw new InvalidArgumentException(t($problem));
                }

                if ($id === 0) {
                    $username = trim((string) ($_POST['username'] ?? ''));
                    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
                        throw new InvalidArgumentException(t('adm_user_bad_name'));
                    }
                    if (db_value('SELECT 1 FROM users WHERE lower(username) = lower(?)', [$username])) {
                        throw new InvalidArgumentException(t('adm_user_exists'));
                    }
                    if ($password === '') {
                        throw new InvalidArgumentException(t('pw_too_short'));
                    }
                    db_exec(
                        'INSERT INTO users (username, email, password_hash, role, active) VALUES (?, ?, ?, ?, ?)',
                        [$username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $role, $active ? 't' : 'f']
                    );
                } else {
                    if ($id === (int) $user['id'] && ($role !== 'admin' || !$active)) {
                        throw new InvalidArgumentException(t('adm_user_self'));
                    }
                    db_exec('UPDATE users SET email = ?, role = ?, active = ? WHERE id = ?', [$email !== '' ? $email : null, $role, $active ? 't' : 'f', $id]);
                    if ($password !== '') {
                        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                    }
                }
                flash('ok', t('adm_saved'));
                back('users');

            case 'user_delete':
                $admin || forbid();
                if ($id === (int) $user['id']) {
                    throw new InvalidArgumentException(t('adm_user_self'));
                }
                db_exec('DELETE FROM users WHERE id = ?', [$id]);
                flash('ok', t('adm_deleted'));
                back('users');

            case 'backup_create':
                $admin || forbid();
                try {
                    $made = backup_create('rucne', (string) ($_POST['note'] ?? ''), (string) $user['username']);
                } catch (Throwable $e) {
                    error_log('[backup] ' . $e->getMessage());
                    throw new InvalidArgumentException(t('bk_err_create', $e->getMessage()));
                }
                flash('ok', t('bk_created', $made['name']));
                back('backups');

            case 'backup_delete':
                $admin || forbid();
                backup_delete((string) ($_POST['name'] ?? ''));
                flash('ok', t('bk_deleted'));
                back('backups');

            case 'backup_restore':
                $admin || forbid();
                $from = (string) ($_POST['name'] ?? '');
                try {
                    $done = backup_restore($from, !empty($_POST['users']), (string) $user['username']);
                } catch (InvalidArgumentException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    error_log('[backup] ' . $e->getMessage());
                    throw new InvalidArgumentException($e->getMessage());
                }
                flash('ok', t('bk_restored', $from, array_sum($done['restored']), $done['safety']));
                back('backups');

            case 'mode_save':
                $admin || forbid();
                $mode = (string) ($_POST['mode'] ?? '');
                if (in_array($mode, SITE_MODES, true)) {
                    save_setting('site_mode', $mode);
                    flash('ok', t('adm_saved'));
                }
                back('settings');

            case 'account_password':
                $row = db_one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
                $new = (string) ($_POST['new'] ?? '');
                if (!$row || !password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) {
                    throw new InvalidArgumentException(t('adm_account_wrong'));
                }
                if ($new !== (string) ($_POST['repeat'] ?? '')) {
                    throw new InvalidArgumentException(t('adm_account_mismatch'));
                }
                if ($problem = password_problem($new)) {
                    throw new InvalidArgumentException(t($problem));
                }
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                flash('ok', t('adm_saved'));
                back('account');
        }
        back($tab);
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
        back($tab);
    } catch (Throwable $e) {
        error_log('[admin] ' . $e->getMessage());
        flash('error', t('adm_error', t('cms_err_db')));
        back($tab);
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= e(t('html_lang')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t($tabs[$tab])) ?> — <?= e(t('adm_title')) ?></title>
<link rel="icon" href="/static/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset_version('static/css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_version('static/css/cms.css')) ?>">
</head>
<body class="admin-page">

<header class="admin-head">
  <a class="admin-head__brand" href="/admin.php">
    <img src="/static/img/favicon.svg" alt="" width="32" height="32">
    <span><?= e(t('brand')) ?> <small><?= e(t('adm_title')) ?></small></span>
  </a>
  <nav class="admin-tabs" aria-label="<?= e(t('adm_title')) ?>">
<?php foreach ($tabs as $key => $label): ?>
    <a href="/admin.php?tab=<?= e($key) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= e(t($label)) ?><?php
      if ($key === 'messages' && ($new = (int) db_value("SELECT count(*) FROM messages WHERE status = 'new'")) > 0): ?> <span class="count"><?= $new ?></span><?php endif; ?></a>
<?php endforeach; ?>
  </nav>
  <div class="admin-head__user">
    <a class="button button--ghost" href="/"><?= e(t('adm_view_site')) ?></a>
    <form method="post" action="/login.php?action=logout">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="button button--ghost"><?= e(t('ab_logout')) ?> (<?= e($user['username']) ?>)</button>
    </form>
  </div>
</header>

<main class="admin-main">
<?php if ($flash): ?>
  <p class="notice notice--<?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></p>
<?php endif; ?>

<?php if ($tab === 'messages'):
    $filter = (string) ($_GET['status'] ?? '');
    $filter = in_array($filter, ['new', 'read', 'archived', 'spam'], true) ? $filter : '';
    $counts = [];
    foreach (db_all('SELECT status, count(*) AS n FROM messages GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    $messages = db_all('SELECT * FROM messages' . ($filter !== '' ? ' WHERE status = ?' : " WHERE status <> 'spam'") . ' ORDER BY created_at DESC LIMIT 200', $filter !== '' ? [$filter] : []);
?>
  <h1 class="admin-title"><?= e(t('adm_tab_messages')) ?></h1>
  <nav class="filters">
    <a href="/admin.php?tab=messages"<?= $filter === '' ? ' aria-current="true"' : '' ?>><?= e(t('adm_filter_all')) ?></a>
<?php foreach (['new', 'read', 'archived', 'spam'] as $s): ?>
    <a href="/admin.php?tab=messages&amp;status=<?= $s ?>"<?= $filter === $s ? ' aria-current="true"' : '' ?>><?= e(t('adm_status_' . $s)) ?> <span class="count count--muted"><?= $counts[$s] ?? 0 ?></span></a>
<?php endforeach; ?>
  </nav>

<?php if (!$messages): ?>
  <p class="empty"><?= e(t('adm_msg_empty')) ?></p>
<?php endif; ?>
  <div class="messages">
<?php foreach ($messages as $m): ?>
    <details class="message message--<?= e($m['status']) ?>" id="m<?= (int) $m['id'] ?>"<?= $m['status'] === 'new' ? ' open' : '' ?>>
      <summary>
        <span class="message__from"><?= e($m['name']) ?> <small>&lt;<?= e($m['email']) ?>&gt;</small></span>
        <span class="message__subject"><?= e($m['subject'] ?? mb_substr($m['body'], 0, 80)) ?></span>
        <span class="message__date"><?= e(date('j. n. Y H:i', strtotime((string) $m['created_at']))) ?></span>
        <span class="badge badge--<?= e($m['status']) ?>"><?= e(t('adm_status_' . $m['status'])) ?></span>
      </summary>
      <div class="message__body"><?= paragraphs($m['body']) ?></div>
      <p class="message__meta"><?= e(t($m['mailed'] ? 'adm_msg_mailed' : 'adm_msg_not_mailed')) ?></p>
      <div class="message__actions">
        <a class="button button--primary" href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: ' . ($m['subject'] ?? 'Divadielko Galéria')) ?>"><?= e(t('adm_msg_reply')) ?></a>
<?php foreach (['read', 'archived', 'spam', 'new'] as $s): if ($s === $m['status']) { continue; } ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="msg_status">
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <input type="hidden" name="status" value="<?= $s ?>">
          <input type="hidden" name="filter" value="<?= e($filter) ?>">
          <button type="submit" class="button button--ghost">→ <?= e(t('adm_status_' . $s)) ?></button>
        </form>
<?php endforeach; ?>
<?php if ($admin): ?>
        <form method="post" data-confirm="<?= e(t('adm_confirm')) ?>">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="msg_delete">
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <input type="hidden" name="filter" value="<?= e($filter) ?>">
          <button type="submit" class="button button--danger"><?= e(t('cms_delete')) ?></button>
        </form>
<?php endif; ?>
      </div>
    </details>
<?php endforeach; ?>
  </div>

<?php elseif ($tab === 'files'):
    $files = media_list();
    $usage = media_usage_map();
    $unconverted = media_unconverted();
    $ffmpeg = media_ffmpeg_binary();
?>
  <h1 class="admin-title"><?= e(t('adm_tab_files')) ?></h1>
  <p class="lead"><?= e(t('adm_files_intro')) ?></p>
  <p class="small muted"><?= e(t('adm_files_caps',
      media_can_avif() ? t('js_yes') : t('js_no'),
      $ffmpeg !== null ? t('js_yes') : t('js_no'),
      format_bytes(min(ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size'))))
  )) ?></p>

  <div class="panel" data-cms-uploader data-reload="1"></div>

<?php if ($unconverted): ?>
  <h2 class="admin-subtitle"><?= e(t('adm_unconverted')) ?></h2>
  <p class="small muted"><?= e(t('adm_unconverted_intro')) ?></p>
  <table class="table">
    <tbody>
<?php foreach ($unconverted as $u): ?>
      <tr>
        <td><?= e($u['name']) ?></td>
        <td><?= e(format_bytes($u['size'])) ?></td>
        <td class="table__actions">
          <a class="button button--ghost" href="/admin.php?download=<?= rawurlencode($u['name']) ?>"><?= e(t('adm_file_download')) ?></a>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="file_convert">
            <input type="hidden" name="name" value="<?= e($u['name']) ?>">
            <button type="submit" class="button button--primary"><?= e(t('adm_convert')) ?></button>
          </form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if (!$files): ?>
  <p class="empty"><?= e(t('js_empty')) ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table table--files">
    <thead>
      <tr>
        <th></th>
        <th><?= e(t('adm_file_name')) ?></th>
        <th><?= e(t('adm_file_size')) ?></th>
        <th><?= e(t('adm_file_original')) ?></th>
        <th><?= e(t('adm_file_used')) ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($files as $f): $used = $usage[$f['name']] ?? []; ?>
      <tr>
        <td class="table__thumb">
<?php if ($f['type'] === 'image'): ?>
          <a href="<?= e($f['url']) ?>" target="_blank" rel="noopener"><img src="<?= e($f['url']) ?>" alt="" loading="lazy"></a>
<?php elseif ($f['type'] === 'video'): ?>
          <video src="<?= e($f['url']) ?>#t=1" preload="metadata" muted controls></video>
<?php else: ?>
          <audio src="<?= e($f['url']) ?>" preload="none" controls></audio>
<?php endif; ?>
        </td>
        <td>
          <a href="<?= e($f['url']) ?>" target="_blank" rel="noopener"><?= e($f['name']) ?></a>
<?php if (isset($f['width'])): ?>
          <br><small class="muted"><?= (int) $f['width'] ?> × <?= (int) $f['height'] ?> px</small>
<?php endif; ?>
        </td>
        <td><?= e(format_bytes($f['size'])) ?></td>
        <td>
<?php if ($f['original']): ?>
          <a href="/admin.php?download=<?= rawurlencode($f['original']) ?>"><?= e($f['original']) ?></a>
          <br><small class="muted"><?= e(format_bytes((int) $f['original_size'])) ?></small>
<?php else: ?>
          <span class="muted">—</span>
<?php endif; ?>
        </td>
        <td>
<?php if ($used): ?>
          <?= e(implode(', ', array_map(static fn ($ent, $n) => t('ent_' . $ent) . ($n > 1 ? " ×$n" : ''), array_keys($used), $used))) ?>
<?php else: ?>
          <span class="muted"><?= e(t('adm_file_unused')) ?></span>
<?php endif; ?>
        </td>
        <td class="table__actions">
<?php if ($admin): ?>
          <form method="post" data-confirm="<?= e($used ? t('adm_file_delete_used') : t('js_confirm_delete')) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="file_delete">
            <input type="hidden" name="name" value="<?= e($f['name']) ?>">
            <button type="submit" class="button button--danger"><?= e(t('cms_delete')) ?></button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php elseif ($tab === 'archive'):
    $archive = cms_archive();
    $dateOf = static fn ($v) => $v ? date('j. n. Y', strtotime((string) $v)) : '';
?>
  <h1 class="admin-title"><?= e(t('adm_tab_archive')) ?></h1>
  <p class="lead"><?= e(t('adm_archive_intro')) ?></p>

<?php if (!array_filter($archive)): ?>
  <p class="empty"><?= e(t('adm_archive_empty')) ?></p>
<?php endif; ?>

<?php foreach ($archive as $entity => $rows): if (!$rows) { continue; } ?>
  <h2 class="admin-subtitle"><?= e(t('adm_arch_' . $entity)) ?></h2>
  <div class="table-wrap">
  <table class="table table--archive">
    <tbody>
<?php foreach ($rows as $row): ?>
      <tr>
        <td class="table__thumb">
<?php if (!empty($row['image'])): ?>
          <img src="<?= e(media_url($row['image'])) ?>" alt="" loading="lazy">
<?php endif; ?>
        </td>
        <td>
          <strong><?= e($row['label']) ?></strong>
<?php if ($entity === 'runs'): ?>
          <br><small class="muted"><?= $row['dates'] > 0
              ? e($dateOf($row['first_date']) . ($row['first_date'] !== $row['last_date'] ? ' – ' . $dateOf($row['last_date']) : '') . ' · ' . t('adm_arch_dates', (int) $row['dates']))
              : e(t('adm_arch_no_dates')) ?><?= $row['parent_archived'] ? ' · ' . e(t('adm_arch_parent')) : '' ?></small>
<?php elseif ($entity === 'productions' && $row['runs'] > 0): ?>
          <br><small class="muted"><?= e(t('adm_arch_runs_of', (int) $row['runs'])) ?></small>
<?php endif; ?>
        </td>
        <td class="muted small"><?= e(t('adm_archived_at', $dateOf($row['deleted_at']))) ?></td>
        <td class="table__actions">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="arch_restore">
            <input type="hidden" name="entity" value="<?= e($entity) ?>">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <button type="submit" class="button button--primary"><?= e(t('adm_restore')) ?></button>
          </form>
<?php if ($admin): ?>
          <form method="post" data-confirm="<?= e(t($entity === 'productions' ? 'adm_purge_confirm_production' : 'adm_purge_confirm')) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="arch_purge">
            <input type="hidden" name="entity" value="<?= e($entity) ?>">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <button type="submit" class="button button--danger"><?= e(t('adm_purge')) ?></button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>

<?php elseif ($tab === 'sections'):
    $order    = section_order();
    $navDef   = cms_settings_def('nav');
    $labels   = cms_settings_values('nav');
    $defaults = cms_settings_placeholders($navDef);
    $moved    = (string) ($_GET['moved'] ?? '');
?>
  <h1 class="admin-title"><?= e(t('adm_tab_sections')) ?></h1>
  <p class="lead"><?= e(t('adm_sections_intro')) ?></p>

  <form method="post" class="sections-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="sections_save">
    <!-- Enter v poli uloží (prvé tlačidlo formulára), nie posunie sekciu -->
    <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true"><?= e(t('adm_user_save')) ?></button>
    <div class="table-wrap">
    <table class="table table--sections">
      <thead>
        <tr>
          <th><?= e(t('adm_sections_order')) ?></th>
          <th><?= e(t('adm_sections_section')) ?></th>
          <th><?= e(t('adm_sections_label')) ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($order as $i => $key): ?>
        <tr<?= $key === $moved ? ' class="is-moved"' : '' ?>>
          <td class="table--sections__move">
            <input type="hidden" name="order[]" value="<?= e($key) ?>">
            <span class="table--sections__pos"><?= $i + 1 ?>.</span>
<?php if ($i === 0): ?>
            <small class="muted"><?= e(t('adm_sections_fixed')) ?></small>
<?php else: ?>
            <button type="submit" name="move" value="<?= e($key) ?>:up" class="button button--ghost button--icon" title="<?= e(t('cms_move_up')) ?>" aria-label="<?= e(t('cms_move_up')) ?>: <?= e(t('sec_' . $key)) ?>"<?= $i === 1 ? ' disabled' : '' ?>>&uarr;</button>
            <button type="submit" name="move" value="<?= e($key) ?>:down" class="button button--ghost button--icon" title="<?= e(t('cms_move_down')) ?>" aria-label="<?= e(t('cms_move_down')) ?>: <?= e(t('sec_' . $key)) ?>"<?= $i === count($order) - 1 ? ' disabled' : '' ?>>&darr;</button>
<?php endif; ?>
          </td>
          <td>
            <strong><?= e(t('sec_' . $key)) ?></strong>
            <br><a class="small" href="/#<?= e($key) ?>" target="_blank" rel="noopener">/#<?= e($key) ?></a>
          </td>
<?php $field = 'nav_' . $key . '_' . config('default_lang'); ?>
          <td>
            <input type="text" name="nav[<?= e($field) ?>]" value="<?= e($labels[$field] ?? '') ?>" placeholder="<?= e($defaults[$field] ?? '') ?>"
                   maxlength="<?= (int) ($navDef['nav_' . $key]['max'] ?? 40) ?>" aria-label="<?= e(t('adm_sections_label')) ?> — <?= e(t('sec_' . $key)) ?>">
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="small muted"><?= e(t('adm_sections_note')) ?></p>
    <button type="submit" class="button button--primary"><?= e(t('adm_user_save')) ?></button>
  </form>

<?php elseif ($tab === 'users'):
    $users = db_all('SELECT * FROM users ORDER BY username');
?>
  <h1 class="admin-title"><?= e(t('adm_tab_users')) ?></h1>
  <p class="small muted"><?= e(t('adm_roles_help')) ?></p>

  <div class="users">
<?php foreach ($users as $u): ?>
    <form method="post" class="panel user-row">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="user_save">
      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <div class="user-row__name">
        <strong><?= e($u['username']) ?></strong>
        <small class="muted"><?= e(t('adm_user_last')) ?>: <?= $u['last_login_at'] ? e(date('j. n. Y H:i', strtotime((string) $u['last_login_at']))) : e(t('adm_never')) ?></small>
      </div>
      <label class="form__field"><span><?= e(t('adm_user_email')) ?></span><input type="email" name="email" value="<?= e($u['email']) ?>"></label>
      <label class="form__field"><span><?= e(t('adm_user_role')) ?></span>
        <select name="role">
<?php foreach (ROLES as $r): ?>
          <option value="<?= $r ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= e(t('adm_role_' . $r)) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="form__field"><span><?= e(t('adm_user_password_keep')) ?></span><input type="password" name="password" autocomplete="new-password"></label>
      <label class="form__check"><input type="checkbox" name="active" value="1"<?= $u['active'] ? ' checked' : '' ?>> <?= e(t('adm_user_active')) ?></label>
      <div class="user-row__actions">
        <button type="submit" class="button button--primary"><?= e(t('adm_user_save')) ?></button>
<?php if ((int) $u['id'] !== (int) $user['id']): ?>
        <button type="submit" class="button button--danger" name="do" value="user_delete" data-confirm="<?= e(t('adm_confirm')) ?>"><?= e(t('adm_user_delete')) ?></button>
<?php endif; ?>
      </div>
    </form>
<?php endforeach; ?>
  </div>

  <h2 class="admin-subtitle"><?= e(t('adm_users_add')) ?></h2>
  <form method="post" class="panel user-row">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="user_save">
    <input type="hidden" name="id" value="0">
    <label class="form__field"><span><?= e(t('adm_user_name')) ?></span><input type="text" name="username" required pattern="[A-Za-z0-9._\-]{3,60}" autocomplete="off"></label>
    <label class="form__field"><span><?= e(t('adm_user_email')) ?></span><input type="email" name="email"></label>
    <label class="form__field"><span><?= e(t('adm_user_role')) ?></span>
      <select name="role">
        <option value="editor"><?= e(t('adm_role_editor')) ?></option>
        <option value="admin"><?= e(t('adm_role_admin')) ?></option>
      </select>
    </label>
    <label class="form__field"><span><?= e(t('adm_user_password')) ?></span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <input type="hidden" name="active" value="1">
    <div class="user-row__actions">
      <button type="submit" class="button button--primary"><?= e(t('adm_user_create')) ?></button>
    </div>
  </form>

<?php elseif ($tab === 'backups'):
    $backupError = null;
    try {
        $backups = backup_list();
    } catch (Throwable $e) {
        $backups = [];
        $backupError = $e->getMessage();
    }
    // Tabuľky s obsahom stránky v poradí, v akom ich návštevník pozná; ostatné len v „Všetky tabuľky".
    $contentTables = ['productions', 'runs', 'performances', 'ensemble_groups', 'photos', 'videos', 'history', 'messages', 'users'];
    $structure = $backupError === null ? backup_structure() : [];
    $compat = [];
    foreach ($backups as $b) {
        $compat[$b['name']] = backup_compat($b, $structure);
    }
    // Potvrdenie obnovy: /admin.php?tab=backups&restore=<súbor>
    $restore = null;
    if (isset($_GET['restore']) && isset($compat[(string) $_GET['restore']])) {
        foreach ($backups as $b) {
            if ($b['name'] === (string) $_GET['restore']) {
                $restore = $b;
            }
        }
    }
    $tableLabel = static fn (string $table): string => t_has('bk_t_' . $table) ? t('bk_t_' . $table) : $table;
?>

<?php if ($restore !== null): $rc = $compat[$restore['name']];
    $now = [];
    foreach (array_keys($structure) as $table) {
        $now[$table] = (int) db_value('SELECT count(*) FROM "' . $table . '"');
    }
?>
  <h1 class="admin-title"><?= e(t('bk_restore_head')) ?></h1>
  <p class="notice notice--warn"><?= e(t('bk_restore_warning')) ?></p>
  <p class="lead"><?= e(t('bk_restore_from', date('j. n. Y H:i', $restore['time']), $restore['name'])) ?></p>

  <div class="table-wrap">
  <table class="table table--backups">
    <thead><tr><th><?= e(t('bk_col_contents')) ?></th><th><?= e(t('bk_restore_now')) ?></th><th><?= e(t('bk_restore_after')) ?></th></tr></thead>
    <tbody>
<?php
    // najprv tabuľky, ktoré používateľ pozná zo stránky, potom ostatné
    $rowsOrder = array_merge(array_values(array_intersect($contentTables, array_keys($structure))), array_diff(array_keys($structure), $contentTables));
    foreach ($rowsOrder as $table):
    if (in_array($table, backup_skipped_tables(), true)) { continue; }
    $keepUsers = $table === 'users';
    $after = $keepUsers ? null : (int) ($restore['tables'][$table] ?? 0);
?>
      <tr>
        <td><?= e($tableLabel($table)) ?></td>
        <td><?= (int) $now[$table] ?></td>
        <td<?= $after !== null && $after !== $now[$table] ? ' class="table--backups__change"' : '' ?>><?= $after === null ? e(t('bk_restore_keep')) : (int) $after ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>

<?php if ($rc['extra_tables'] || $rc['extra_columns'] || $rc['missing_columns'] || $rc['missing_tables']): ?>
  <p class="small muted">
<?php if ($rc['missing_tables']): ?><?= e(t('bk_restore_empty_tables', implode(', ', array_map($tableLabel, $rc['missing_tables'])))) ?><br><?php endif; ?>
<?php if ($rc['extra_tables']): ?><?= e(t('bk_restore_skip_tables', implode(', ', array_map($tableLabel, $rc['extra_tables'])))) ?><br><?php endif; ?>
<?php foreach ($rc['extra_columns'] as $table => $cols): ?><?= e(t('bk_restore_skip_columns', $tableLabel($table), implode(', ', $cols))) ?><br><?php endforeach; ?>
<?php foreach ($rc['missing_columns'] as $table => $cols): ?><?= e(t('bk_restore_default_columns', $tableLabel($table), implode(', ', $cols))) ?><br><?php endforeach; ?>
  </p>
<?php endif; ?>

  <form method="post" class="panel form" data-confirm="<?= e(t('bk_restore_confirm')) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="backup_restore">
    <input type="hidden" name="name" value="<?= e($restore['name']) ?>">
    <label class="form__check"><input type="checkbox" name="users" value="1"> <?= e(t('bk_restore_users')) ?></label>
    <p class="small muted"><?= e(t('bk_restore_users_note')) ?></p>
    <div class="user-row__actions">
      <button type="submit" class="button button--danger" data-busy="<?= e(t('bk_restoring')) ?>"><?= e(t('bk_restore_do')) ?></button>
      <a class="button button--ghost" href="/admin.php?tab=backups"><?= e(t('js_cancel')) ?></a>
    </div>
  </form>

  <h2 class="admin-subtitle"><?= e(t('adm_tab_backups')) ?></h2>
<?php endif; ?>
  <h1 class="admin-title"><?= e(t('adm_tab_backups')) ?></h1>
  <p class="lead"><?= e(t('bk_intro')) ?></p>
  <p class="small muted"><?= e(t('bk_files_note')) ?></p>
<?php if ($backupError !== null): ?>
  <p class="notice notice--error"><?= e($backupError) ?></p>
<?php endif; ?>

  <form method="post" class="panel backup-new">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="backup_create">
    <label class="form__field"><span><?= e(t('bk_note')) ?></span><input type="text" name="note" maxlength="200" placeholder="<?= e(t('bk_note_placeholder')) ?>"></label>
    <button type="submit" class="button button--primary" data-busy="<?= e(t('bk_creating')) ?>"><?= e(t('bk_create')) ?></button>
  </form>

<?php if (!$backups): ?>
  <p class="empty"><?= e(t('bk_empty')) ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table table--backups">
    <thead>
      <tr>
        <th><?= e(t('bk_col_date')) ?></th>
        <th><?= e(t('bk_col_type')) ?></th>
        <th><?= e(t('bk_col_contents')) ?></th>
        <th><?= e(t('bk_col_size')) ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($backups as $b):
    $c = $compat[$b['name']];
    $reason = $b['reason'] === 'rucne' ? t('bk_reason_manual')
        : ($b['reason'] === 'pred-obnovou' ? t('bk_reason_restore')
        : (str_starts_with($b['reason'], 'pred-') ? t('bk_reason_migration', substr($b['reason'], 5)) : t('bk_reason_other')));
    $summary = [];
    foreach ($contentTables as $table) {
        if (!empty($b['tables'][$table])) {
            $summary[] = t('bk_t_' . $table) . ' ' . $b['tables'][$table];
        }
    }
?>
      <tr>
        <td class="table--backups__date">
          <strong><?= e(date('j. n. Y', $b['time'])) ?></strong> <?= e(date('H:i', $b['time'])) ?>
        </td>
        <td>
          <?= e($reason) ?>
          <br><small class="muted"><?= e($b['by'] !== null ? $b['by'] : t('bk_auto')) ?></small>
<?php if ($b['note'] !== ''): ?>
          <br><small class="table--backups__note"><?= e($b['note']) ?></small>
<?php endif; ?>
        </td>
        <td>
          <?= e($summary ? implode(' · ', $summary) : '—') ?>
          <p class="small">
            <span class="badge badge--<?= e($c['level']) ?>" title="<?= e($c['level'] === 'blocked' ? implode(' ', $c['blockers']) : '') ?>"><?= e(t('bk_compat_' . $c['level'])) ?></span>
            <span class="muted"><?= e(t('bk_version', backup_version($b['migration']))) ?></span>
          </p>
          <details class="table--backups__details">
            <summary><?= e(t('bk_all_tables')) ?></summary>
            <p class="small muted"><?php foreach ($b['tables'] as $table => $n): ?><?= e($table) ?>&nbsp;<?= (int) $n ?> &nbsp; <?php endforeach; ?></p>
            <p class="small muted"><?= e($b['name']) ?></p>
          </details>
        </td>
        <td class="table--backups__size"><?= e(format_bytes($b['size'])) ?></td>
        <td class="table__actions">
<?php if ($c['level'] !== 'blocked'): ?>
          <a class="button button--primary" href="/admin.php?tab=backups&amp;restore=<?= rawurlencode($b['name']) ?>"><?= e(t('bk_restore')) ?></a>
<?php endif; ?>
          <a class="button button--ghost" href="/admin.php?backup=<?= rawurlencode($b['name']) ?>"><?= e(t('bk_download')) ?></a>
          <form method="post" data-confirm="<?= e(t('bk_delete_confirm')) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="backup_delete">
            <input type="hidden" name="name" value="<?= e($b['name']) ?>">
            <button type="submit" class="button button--danger"><?= e(t('bk_delete')) ?></button>
          </form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small muted"><?= e(t('bk_total', count($backups), format_bytes((int) array_sum(array_column($backups, 'size'))))) ?></p>
<?php endif; ?>

<?php elseif ($tab === 'settings'):
    $forced = config('mode');
    $current = site_mode();
?>
  <h1 class="admin-title"><?= e(t('adm_tab_settings')) ?></h1>
  <h2 class="admin-subtitle"><?= e(t('adm_mode_head')) ?></h2>
<?php if (is_string($forced) && $forced !== ''): ?>
  <p class="notice notice--warn"><?= e(t('adm_mode_forced', $forced)) ?></p>
<?php endif; ?>
  <form method="post" class="panel modes">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="mode_save">
<?php foreach (SITE_MODES as $m): ?>
    <label class="mode-option mode-option--<?= $m ?>">
      <input type="radio" name="mode" value="<?= $m ?>"<?= setting('site_mode', 'wip') === $m ? ' checked' : '' ?><?= is_string($forced) && $forced !== '' ? ' disabled' : '' ?>>
      <span>
        <strong><?= e(t('mode_' . $m)) ?><?= $current === $m ? ' ✓' : '' ?></strong>
        <small><?= e(t('mode_' . $m . '_desc')) ?></small>
      </span>
    </label>
<?php endforeach; ?>
    <div>
      <button type="submit" class="button button--primary"<?= is_string($forced) && $forced !== '' ? ' disabled' : '' ?>><?= e(t('adm_mode_save')) ?></button>
    </div>
  </form>

<?php elseif ($tab === 'account'): ?>
  <h1 class="admin-title"><?= e(t('adm_tab_account')) ?></h1>
  <h2 class="admin-subtitle"><?= e(t('adm_account_pw')) ?></h2>
  <form method="post" class="panel form form--narrow">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="account_password">
    <label class="form__field"><span><?= e(t('adm_account_current')) ?></span><input type="password" name="current" required autocomplete="current-password"></label>
    <label class="form__field"><span><?= e(t('adm_account_new')) ?></span><input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
    <label class="form__field"><span><?= e(t('adm_account_repeat')) ?></span><input type="password" name="repeat" required minlength="10" autocomplete="new-password"></label>
    <button type="submit" class="button button--primary"><?= e(t('adm_user_save')) ?></button>
  </form>
<?php endif; ?>
</main>

<?= cms_client_config() ?>
</body>
</html>
