<?php
/**
 * Inštalácia a aktualizácia databázy.
 *
 * Z príkazového riadku:
 *   php setup.php                              migrácie; ak nie je žiadny používateľ, opýta sa na prvého
 *   php setup.php --admin=meno --password=…    migrácie + administrátor (alebo nové heslo existujúcemu)
 *   php setup.php --create-db                  najprv vytvorí databázu z config (pripojí sa na db "postgres")
 *   php setup.php --demo                       ukážkový obsah s vygenerovanými obrázkami (len na skúšanie!)
 *   php setup.php --backup                     záloha databázy (storage/backups/); pred migráciou sa robí aj sama
 *
 * Z prehliadača (hosting bez SSH) — otvorte /setup.php:
 *   1. Kým includes/config.local.php neexistuje, ukáže sa inštalácia: zadajú sa
 *      údaje k databáze, skript ich overí a súbor zapíše (aj s tajným kľúčom
 *      a 'setup_key'). Tento krok ide bez kľúča — inak by sa nedal urobiť.
 *   2. Keď config.local.php existuje, stránka je chránená kľúčom:
 *      /setup.php?key=… aplikuje migrácie a ponúkne prvého administrátora.
 * Po nasadení novej verzie s migráciou stačí znova otvoriť /setup.php?key=…
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/cms.php';

const LOCAL_CONFIG = __DIR__ . '/includes/config.local.php';

$cli = PHP_SAPI === 'cli';

/**
 * Prvé spustenie v prehliadači: údaje k databáze → overenie pripojenia →
 * zápis includes/config.local.php → ďalej už chránené kľúčom.
 *
 * Beží bez kľúča, lebo kým súbor s nastaveniami neexistuje, žiadny kľúč nie je
 * kde vziať. Len čo sa súbor zapíše, sem sa už bez kľúča nikto nedostane.
 */
function install_page(): void
{
    $errors = [];
    $form = [
        'host'    => trim((string) ($_POST['host'] ?? '127.0.0.1')),
        'port'    => trim((string) ($_POST['port'] ?? '5432')),
        'dbname'  => trim((string) ($_POST['dbname'] ?? '')),
        'user'    => trim((string) ($_POST['user'] ?? '')),
        'pass'    => (string) ($_POST['pass'] ?? ''),
        // Predvolene schránka na hlavnej doméne (nie na podradenej ako test.…).
        'from'    => trim((string) ($_POST['from'] ?? 'web@' . (preg_match('/([^.]+\.[^.]+)$/', current_host(), $m) ? $m[1] : current_host()))),
    ];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $form['host'])) {
            $errors[] = 'Neplatná adresa servera databázy.';
        }
        if (!ctype_digit($form['port']) || (int) $form['port'] < 1 || (int) $form['port'] > 65535) {
            $errors[] = 'Neplatný port.';
        }
        foreach (['dbname' => 'Názov databázy', 'user' => 'Používateľ databázy'] as $field => $label) {
            if (!preg_match('/^[A-Za-z0-9._-]{1,63}$/', $form[$field])) {
                $errors[] = "$label: povolené sú písmená bez diakritiky, číslice, bodka, pomlčka a podčiarkovník.";
            }
        }
        if ($form['from'] !== '' && !filter_var($form['from'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Neplatná e-mailová adresa odosielateľa.';
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $form['host'], (int) $form['port'], $form['dbname']);
        if (!$errors) {
            try {
                new PDO($dsn, $form['user'], $form['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
            } catch (Throwable $e) {
                $errors[] = 'K databáze sa nedá pripojiť: ' . $e->getMessage();
            }
        }

        if (!$errors) {
            $key = bin2hex(random_bytes(16));
            $settings = [
                'secret' => bin2hex(random_bytes(32)),
                'db'     => ['dsn' => $dsn, 'user' => $form['user'], 'password' => $form['pass']],
                'mail'   => ['driver' => 'mail'] + ($form['from'] !== '' ? ['from' => $form['from']] : []),
                'setup_key' => $key,
            ];
            $php = "<?php\n// Nastavenia tejto inštalácie — vytvoril setup.php. Necommituje sa.\n\nreturn "
                . var_export($settings, true) . ";\n";

            if (@file_put_contents(LOCAL_CONFIG, $php, LOCK_EX) === false) {
                $errors[] = 'Súbor includes/config.local.php sa nepodarilo zapísať — priečinok includes/ nie je zapisovateľný. Nahrajte súbor cez FTP ručne.';
            } else {
                @chmod(LOCAL_CONFIG, 0640);
                redirect('/setup.php?key=' . urlencode($key));
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="sk"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Inštalácia — Divadielko Galéria</title><link rel="stylesheet" href="<?= e(asset_version('static/css/admin.css')) ?>"></head>
<body class="login-page"><main class="login login--wide">
<img class="login__logo" src="/static/img/favicon.svg" alt="" width="56" height="56">
<h1 class="login__title">Inštalácia</h1>
<p class="login__brand">Divadielko Galéria</p>
<p class="small muted">Zadajte údaje k databáze PostgreSQL (vytvorí sa v administrácii hostingu).
Skript ich overí a uloží do <code>includes/config.local.php</code>. Potom vytvoríte prvého administrátora.</p>
<?php foreach ($errors as $error): ?>
<p class="notice notice--error"><?= e($error) ?></p>
<?php endforeach; ?>
<form method="post" class="form">
  <div class="form__row">
    <label class="form__field"><span>Server</span><input name="host" value="<?= e($form['host']) ?>" required></label>
    <label class="form__field"><span>Port</span><input name="port" value="<?= e($form['port']) ?>" inputmode="numeric" required></label>
  </div>
  <label class="form__field"><span>Názov databázy</span><input name="dbname" value="<?= e($form['dbname']) ?>" required autofocus></label>
  <label class="form__field"><span>Používateľ</span><input name="user" value="<?= e($form['user']) ?>" required autocomplete="off"></label>
  <label class="form__field"><span>Heslo</span><input type="password" name="pass" value="<?= e($form['pass']) ?>" autocomplete="off"></label>
  <label class="form__field"><span>E-mail odosielateľa (schránka na tejto doméne)</span><input type="email" name="from" value="<?= e($form['from']) ?>"></label>
  <button class="button button--primary button--wide">Overiť a uložiť</button>
</form>
</main></body></html>
    <?php
}

// ── Prehliadač ───────────────────────────────────────────────────────────────

if (!$cli) {
    private_headers();
    header('Content-Type: text/html; charset=UTF-8');

    // 1. krok — ešte nie je nastavená databáza: formulár, ktorý zapíše config.local.php.
    if (!is_file(LOCAL_CONFIG)) {
        install_page();
        exit;
    }

    $key = (string) config('setup_key');
    if (strlen($key) < 16 || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        http_response_code(404);
        exit;
    }

    $out = [];
    try {
        $applied = db_migrate($backup);
        if ($backup !== null) {
            $out[] = 'Záloha pred aktualizáciou: ' . $backup . ' (administrácia → Zálohy).';
        }
        $out[] = $applied ? 'Aplikované migrácie: ' . implode(', ', $applied) : 'Databáza je aktuálna.';

        $hasUsers = (int) db_value('SELECT count(*) FROM users') > 0;
        if (!$hasUsers && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $name = trim((string) ($_POST['username'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $name)) {
                $out[] = 'Neplatné meno (3–60 znakov: písmená bez diakritiky, číslice, . _ -).';
            } elseif ($problem = password_problem($pass)) {
                $out[] = t($problem);
            } else {
                db_exec("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')", [$name, password_hash($pass, PASSWORD_DEFAULT)]);
                $out[] = "Administrátor $name je vytvorený. Prihláste sa na /login.php.";
                $hasUsers = true;
            }
        }
    } catch (Throwable $e) {
        $out[] = 'Chyba: ' . $e->getMessage();
        $hasUsers = true;
    }
    ?>
<!DOCTYPE html>
<html lang="sk"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Setup</title><link rel="stylesheet" href="<?= e(asset_version('static/css/admin.css')) ?>"></head>
<body class="login-page"><main class="login">
<h1 class="login__title">Setup</h1>
<?php foreach ($out as $line): ?><p class="notice"><?= e($line) ?></p><?php endforeach; ?>
<?php if (!$hasUsers): ?>
<form method="post" class="form">
  <label class="form__field"><span>Meno administrátora</span><input name="username" required pattern="[A-Za-z0-9._\-]{3,60}"></label>
  <label class="form__field"><span>Heslo (aspoň 10 znakov)</span><input type="password" name="password" required minlength="10"></label>
  <button class="button button--primary button--wide">Vytvoriť administrátora</button>
</form>
<?php endif; ?>
</main></body></html>
    <?php
    exit;
}

// ── Príkazový riadok ─────────────────────────────────────────────────────────

$opts = getopt('', ['admin:', 'password:', 'email:', 'demo', 'create-db', 'backup']);

function say(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function ask(string $question, bool $hidden = false): string
{
    fwrite(STDOUT, $question . ' ');
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
        $answer = trim((string) fgets(STDIN));
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $answer;
    }

    return trim((string) fgets(STDIN));
}

try {
    if (isset($opts['create-db'])) {
        $cfg = config('db');
        if (!preg_match('/dbname=([A-Za-z0-9_]+)/', (string) $cfg['dsn'], $m)) {
            throw new RuntimeException('V dsn chýba dbname.');
        }
        $server = new PDO(preg_replace('/dbname=[A-Za-z0-9_]+/', 'dbname=postgres', (string) $cfg['dsn']), (string) $cfg['user'], (string) $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $exists = $server->query("SELECT 1 FROM pg_database WHERE datname = " . $server->quote($m[1]))->fetchColumn();
        if ($exists) {
            say("Databáza {$m[1]} už existuje.");
        } else {
            $server->exec('CREATE DATABASE "' . $m[1] . '" ENCODING \'UTF8\' TEMPLATE template0');
            say("Databáza {$m[1]} je vytvorená.");
        }
    }

    if (isset($opts['backup'])) {
        require_once __DIR__ . '/includes/backup.php';
        say('Záloha: ' . backup_create('rucne', 'php setup.php --backup', 'cli')['name']);
    }

    $applied = db_migrate($backup);
    if ($backup !== null) {
        say('Záloha pred aktualizáciou: ' . $backup);
    }
    say($applied ? 'Aplikované migrácie: ' . implode(', ', $applied) : 'Databáza je aktuálna.');

    $name = $opts['admin'] ?? null;
    $pass = $opts['password'] ?? null;
    if ($name === null && (int) db_value('SELECT count(*) FROM users') === 0) {
        say('Zatiaľ nie je žiadny používateľ — vytvoríme administrátora.');
        $name = ask('Meno:');
        $pass = ask('Heslo (aspoň 10 znakov):', true);
    }
    if ($name !== null) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', (string) $name)) {
            throw new RuntimeException('Neplatné meno (3–60 znakov: písmená bez diakritiky, číslice, . _ -).');
        }
        if ($pass === null || password_problem((string) $pass)) {
            throw new RuntimeException('Heslo musí mať aspoň 10 znakov.');
        }
        $hash = password_hash((string) $pass, PASSWORD_DEFAULT);
        if (db_value('SELECT 1 FROM users WHERE lower(username) = lower(?)', [$name])) {
            db_exec("UPDATE users SET password_hash = ?, role = 'admin', active = true WHERE lower(username) = lower(?)", [$hash, $name]);
            say("Používateľ $name: nové heslo, rola administrátor.");
        } else {
            db_exec("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')", [$name, $opts['email'] ?? null, $hash]);
            say("Administrátor $name je vytvorený.");
        }
    }

    if (isset($opts['demo'])) {
        require __DIR__ . '/includes/demo.php';
        demo_seed();
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Chyba: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
