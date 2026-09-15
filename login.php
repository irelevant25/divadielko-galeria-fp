<?php
/**
 * Prihlásenie. Na stránke naň nevedie žiadny odkaz — otvára sa priamo
 * zadaním adresy /login.php. Registrácia neexistuje, účty zakladá administrátor.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

private_headers();

$action = (string) ($_GET['action'] ?? '');

if ($action === 'logout') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_valid()) {
        logout();
    }
    redirect('/'); // späť na stránku (tak, ako ju vidí návštevník)
}

// Kam po prihlásení: len na vlastné stránky (žiadne presmerovanie na cudzí web).
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '/');
if (!preg_match('~^/(?!/)[A-Za-z0-9._/?=&#%-]*$~', $next)) {
    $next = '/';
}

if (current_user()) {
    redirect($next);
}

$error    = '';
$username = '';
$dbOk     = db_available();

if ($dbOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 255);
    $password = (string) ($_POST['password'] ?? '');

    // Prihlasovací formulár má vlastný CSRF token v cookie session — tá sa založí pri zobrazení.
    if (!csrf_valid()) {
        $error = t('cms_err_csrf');
    } else {
        $result = attempt_login($username, $password);
        if (isset($result['user'])) {
            redirect($next);
        }
        $error = t($result['error']);
    }
}

$token = $dbOk ? csrf_token() : '';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= e(t('html_lang')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('login_title')) ?> — <?= e(t('brand')) ?></title>
<link rel="icon" href="/static/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset_version('static/css/admin.css')) ?>">
</head>
<body class="login-page">
<main class="login">
  <img class="login__logo" src="/static/img/favicon.svg" alt="" width="56" height="56">
  <h1 class="login__title"><?= e(t('login_title')) ?></h1>
  <p class="login__brand"><?= e(t('brand')) ?></p>

<?php if (!$dbOk): ?>
  <p class="notice notice--error"><?= e(t('login_no_db')) ?></p>
<?php elseif ($error !== ''): ?>
  <p class="notice notice--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($dbOk): ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label class="form__field">
      <span><?= e(t('login_username')) ?></span>
      <input type="text" name="username" value="<?= e($username) ?>" required autocomplete="username" autofocus>
    </label>
    <label class="form__field">
      <span><?= e(t('login_password')) ?></span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="button button--primary button--wide"><?= e(t('login_submit')) ?></button>
  </form>
<?php endif; ?>

  <p class="login__back"><a href="/"><?= e(t('login_back')) ?></a></p>
  <nav class="login__lang" aria-label="<?= e(t('lang_switch')) ?>">
<?php foreach (config('languages') as $code): ?>
    <a href="<?= e(lang_url($code)) ?>"<?= $code === lang() ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
<?php endforeach; ?>
  </nav>
</main>
</body>
</html>
