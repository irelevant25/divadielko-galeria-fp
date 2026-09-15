<?php
/**
 * Prihlasovanie. Registrácia neexistuje — účty zakladá administrátor.
 *
 * Session sa otvára len vtedy, keď prehliadač už má prihlasovaciu cookie
 * (alebo keď sa práve prihlasuje). Bežný návštevník tak nedostane žiadnu
 * cookie a stránka nepotrebuje cookie lištu.
 */

declare(strict_types=1);

const SESSION_NAME = 'dg_session';
const ROLES = ['admin', 'editor'];

/** Po koľkých neúspešných pokusoch z jednej IP sa prihlasovanie na chvíľu zablokuje. */
const LOGIN_MAX_ATTEMPTS = 8;
const LOGIN_WINDOW_MINUTES = 15;

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (8 * 60 * 60));
    session_start();
}

/** Prihlásený používateľ alebo null. Z databázy sa načíta raz za požiadavku. */
function current_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }
    $user = null;

    if (empty($_COOKIE[SESSION_NAME])) {
        return null;
    }

    session_start_secure();
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0 || !db_available()) {
        return null;
    }

    // Po 8 hodinách nečinnosti odhlásiť.
    if (time() - (int) ($_SESSION['seen_at'] ?? 0) > 8 * 60 * 60) {
        logout();
        return null;
    }
    $_SESSION['seen_at'] = time();

    $row = db_one('SELECT id, username, email, role, active FROM users WHERE id = ?', [$id]);
    if (!$row || !$row['active']) {
        logout();
        return null;
    }

    return $user = $row;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/**
 * Overí meno a heslo. Vracia používateľa alebo chybový kľúč prekladu.
 *
 * @return array{user?: array, error?: string}
 */
function attempt_login(string $username, string $password): array
{
    $ip = ip_hash();

    $recent = (int) db_value(
        "SELECT count(*) FROM login_attempts WHERE ip_hash = ? AND created_at > now() - make_interval(mins => ?)",
        [$ip, LOGIN_WINDOW_MINUTES]
    );
    if ($recent >= LOGIN_MAX_ATTEMPTS) {
        return ['error' => 'login_throttled'];
    }

    $row = db_one('SELECT * FROM users WHERE lower(username) = lower(?) OR lower(email) = lower(?)', [$username, $username]);

    // password_verify beží aj pre neexistujúce meno, aby sa podľa času odpovede
    // nedalo zistiť, ktoré mená existujú.
    $hash = $row['password_hash'] ?? password_hash('dummy-' . $username, PASSWORD_DEFAULT);
    $ok   = password_verify($password, $hash) && $row && $row['active'];

    if (!$ok) {
        db_exec('INSERT INTO login_attempts (ip_hash, username) VALUES (?, ?)', [$ip, mb_substr($username, 0, 100)]);
        return ['error' => 'login_failed'];
    }

    db_exec('DELETE FROM login_attempts WHERE ip_hash = ? OR created_at < now() - interval \'1 day\'', [$ip]);

    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    db_exec('UPDATE users SET last_login_at = now() WHERE id = ?', [$row['id']]);

    session_start_secure();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['seen_at'] = time();
    $_SESSION['csrf']    = bin2hex(random_bytes(32));

    return ['user' => $row];
}

function logout(): void
{
    session_start_secure();
    $_SESSION = [];
    session_destroy();
    setcookie(SESSION_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function csrf_token(): string
{
    session_start_secure();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/** Token z formulára (csrf) alebo z hlavičky X-CSRF-Token (fetch). */
function csrf_valid(): bool
{
    session_start_secure();
    $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function password_problem(string $password): ?string
{
    return mb_strlen($password) < 10 ? 'pw_too_short' : null;
}
