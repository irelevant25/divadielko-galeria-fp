<?php
/**
 * An isolated copy of the site with its OWN database — the place to try changes, run
 * migrations and break things without touching the developer's real local content.
 *
 *   php .claude/skills/dg-dev/scripts/testsite.php <command> [options]
 *
 *   create   copy the working tree to a temp folder, create database <dbname>_claude_test,
 *            run every migration, create the admin "tester" and the demo content
 *              --clone-db     instead of an empty database, clone the developer's local one
 *                             (CREATE DATABASE … TEMPLATE) and apply only the pending migrations —
 *                             this is how a new migration is rehearsed against real content
 *              --with-assets  also copy assets/ (so cloned content shows its images)
 *              --no-demo      skip the demo content
 *   sync     copy changed files from the working tree again and apply migrations that are new to
 *            the copy's database (serve and smoke do this themselves)
 *   serve    php -S 127.0.0.1:8765 router.php in the copy   (--port=N; blocks — run it in the background)
 *   smoke    sync, start a server on a free port, run smoke.php against it, stop the server
 *              --only=a,b     run only these sections (see smoke.php)     --verbose  list passing checks too
 *              --no-sync      test the copy as it is (e.g. after editing it by hand)
 *   status   where the copy is and whether its database exists
 *   path     print only the folder (for scripts: check.php --root="$(… path)")
 *   destroy  drop the test database and delete the folder
 *
 * Safety: the database it creates and drops is always named <dbname>_claude_test, credentials
 * are read from includes/config.local.php and never printed, and it refuses to work when that
 * config points at a database server that is not on this machine.
 */

declare(strict_types=1);

const TEST_ADMIN    = 'tester';
const TEST_PASSWORD = 'tester-heslo-123';
const DB_SUFFIX     = '_claude_test';

$repo = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 4)));
$command = $argv[1] ?? 'help';
$flags = array_slice($argv, 2);
$has = static fn (string $flag): bool => in_array('--' . $flag, $flags, true);
$value = static function (string $name, string $default) use ($flags): string {
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--' . $name . '=')) {
            return substr($flag, strlen($name) + 3);
        }
    }
    return $default;
};

function out(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function stop(string $message): void
{
    fwrite(STDERR, 'testsite: ' . $message . PHP_EOL);
    exit(1);
}

/** Where the copy lives — one per repository checkout. */
function test_dir(string $repo): string
{
    return str_replace('\\', '/', rtrim(sys_get_temp_dir(), '/\\')) . '/dg-testsite-' . substr(md5($repo), 0, 8);
}

/** @return array{config: array, dsn: string, db: string, real: string} */
function test_database(string $repo): array
{
    $file = $repo . '/includes/config.local.php';
    if (!is_file($file)) {
        stop('includes/config.local.php is missing — create it first (README → Lokálne spustenie).');
    }
    $config = require $file;
    $dsn = (string) ($config['db']['dsn'] ?? '');
    if (!preg_match('/dbname=([A-Za-z0-9_]+)/', $dsn, $m)) {
        stop('db.dsn in config.local.php has no dbname=…');
    }
    $host = preg_match('/host=([^;]+)/', $dsn, $h) ? strtolower($h[1]) : 'localhost';
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        stop("config.local.php points at the database server '$host'. The test site only works with a server on this machine — it creates and drops databases.");
    }
    $real = str_ends_with($m[1], DB_SUFFIX) ? substr($m[1], 0, -strlen(DB_SUFFIX)) : $m[1];
    $db = substr($real, 0, 63 - strlen(DB_SUFFIX)) . DB_SUFFIX;

    return ['config' => $config, 'dsn' => (string) preg_replace('/dbname=[A-Za-z0-9_]+/', 'dbname=' . $db, $dsn), 'db' => $db, 'real' => $real];
}

function server_pdo(array $info): PDO
{
    $dsn = (string) preg_replace('/dbname=[A-Za-z0-9_]+/', 'dbname=postgres', $info['dsn']);

    return new PDO($dsn, (string) $info['config']['db']['user'], (string) $info['config']['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function database_exists(PDO $server, string $db): bool
{
    $stmt = $server->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
    $stmt->execute([$db]);

    return (bool) $stmt->fetchColumn();
}

function drop_database(PDO $server, string $db): void
{
    if (!str_ends_with($db, DB_SUFFIX)) {
        stop("refusing to drop '$db' — not a test database");
    }
    $server->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()')->execute([$db]);
    $server->exec('DROP DATABASE IF EXISTS "' . $db . '"');
}

/** Files of the working tree that belong to the site: tracked + new and not ignored, without .claude/. */
function source_files(string $repo): array
{
    $list = shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files --cached --others --exclude-standard');
    if (!is_string($list) || trim($list) === '') {
        stop('git ls-files returned nothing — run this inside the repository.');
    }
    $files = [];
    foreach (preg_split('/\R/', trim($list)) as $rel) {
        if (!str_starts_with($rel, '.claude/') && is_file($repo . '/' . $rel)) {
            $files[] = $rel;
        }
    }

    return $files;
}

/** Copies the code; returns [copied, removed]. Uploaded files, storage and the test config are left alone. */
function sync_files(string $repo, string $dir): array
{
    $copied = 0;
    $wanted = [];
    foreach (source_files($repo) as $rel) {
        $wanted[$rel] = true;
        $from = $repo . '/' . $rel;
        $to = $dir . '/' . $rel;
        if (is_file($to) && filesize($to) === filesize($from) && md5_file($to) === md5_file($from)) {
            continue;
        }
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0775, true);
        }
        copy($from, $to);
        $copied++;
    }

    // Files that no longer exist in the working tree (renamed, deleted) would keep working in the copy.
    $removed = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
        if (isset($wanted[$rel]) || $rel === 'includes/config.local.php' || preg_match('~^(assets|assets_original|storage)/~', $rel)) {
            continue;
        }
        unlink($file->getPathname());
        $removed++;
    }

    return [$copied, $removed];
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    if (!str_starts_with(basename($dir), 'dg-testsite-')) {
        stop("refusing to delete '$dir'");
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        @chmod($file->getPathname(), 0666);
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

function copy_dir(string $from, string $to): int
{
    $bytes = 0;
    if (!is_dir($from)) {
        return 0;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $target = $to . '/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
        if ($file->isDir()) {
            is_dir($target) || mkdir($target, 0775, true);
        } else {
            copy($file->getPathname(), $target);
            $bytes += $file->getSize();
        }
    }

    return $bytes;
}

/**
 * Runs PHP in the copy and relays its output. Returns the exit code. The child writes into a pipe,
 * not into our STDOUT handle: when output is redirected to a file, a child that inherits the handle
 * starts writing at the beginning of that file and overwrites what this script printed before it.
 * With $quietWhen, output is swallowed when it consists of just that line (e.g. "nothing to migrate").
 */
function run_php(array $args, string $cwd, string $quietWhen = ''): int
{
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($process)) {
        return 1;
    }
    $held = '';
    while (($line = fgets($pipes[1])) !== false) {
        if ($quietWhen !== '') {
            $held .= $line; // decide at the end
        } else {
            fwrite(STDOUT, $line);
        }
    }
    fclose($pipes[1]);
    $code = proc_close($process);
    if ($quietWhen !== '' && ($code !== 0 || trim($held) !== $quietWhen)) {
        fwrite(STDOUT, $held);
    }

    return $code;
}

/**
 * Brings the copy's database up to date. sync / serve / smoke call it, so a copy made before a new
 * migration was written does not run new code on an old schema. Safe: this is the throw-away database.
 */
function migrate_copy(string $dir): void
{
    if (run_php(['setup.php'], $dir, 'Databáza je aktuálna.') !== 0) {
        stop('a migration failed in the test copy — the message above says which one and why. Fix the file and run "create" again.');
    }
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

/** Starts the built-in server in the copy. PHP warnings are shown in the page, so the smoke test sees them. */
function start_server(string $dir, int $port)
{
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $log = $dir . '/storage/server.log';
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-d', 'log_errors=1', '-d', 'error_log=' . $dir . '/storage/php-error.log',
         '-S', '127.0.0.1:' . $port, 'router.php'],
        [0 => ['file', $null, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $dir
    );
    if (!is_resource($process)) {
        stop('could not start php -S');
    }
    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            return $process;
        }
        usleep(100000);
    }
    proc_terminate($process);
    stop("the server did not start on port $port — see $log");
}

// ── Commands ─────────────────────────────────────────────────────────────────

$dir = test_dir($repo);

switch ($command) {
    case 'path':
        echo $dir, PHP_EOL;
        exit(0);

    case 'status':
        $info = test_database($repo);
        out('folder:   ' . $dir . (is_dir($dir) ? '' : '   (does not exist — run create)'));
        try {
            out('database: ' . $info['db'] . (database_exists(server_pdo($info), $info['db']) ? '' : '   (does not exist — run create)'));
        } catch (Throwable $e) {
            out('database: cannot reach the server — ' . $e->getMessage());
        }
        out('login:    ' . TEST_ADMIN . ' / ' . TEST_PASSWORD);
        exit(0);

    case 'create':
        $info = test_database($repo);
        try {
            $server = server_pdo($info);
        } catch (Throwable $e) {
            stop('cannot connect to PostgreSQL: ' . $e->getMessage());
        }
        drop_database($server, $info['db']);
        remove_dir($dir);
        mkdir($dir, 0775, true);

        [$copied] = sync_files($repo, $dir);
        out("copied $copied files → $dir");

        $config = [
            'secret'    => bin2hex(random_bytes(32)),
            'db'        => ['dsn' => $info['dsn'], 'user' => (string) $info['config']['db']['user'], 'password' => (string) $info['config']['db']['password']],
            'mail'      => ['driver' => 'file'],
            'analytics' => ['enabled' => false],
        ] + (empty($info['config']['ffmpeg']) ? [] : ['ffmpeg' => $info['config']['ffmpeg']]);
        file_put_contents($dir . '/includes/config.local.php', "<?php\n// Test copy — written by testsite.php.\n\nreturn " . var_export($config, true) . ";\n");
        @chmod($dir . '/includes/config.local.php', 0600);

        if ($has('clone-db')) {
            try {
                $server->exec('CREATE DATABASE "' . $info['db'] . '" TEMPLATE "' . $info['real'] . '"');
            } catch (Throwable $e) {
                stop("cannot clone '{$info['real']}': " . $e->getMessage() . PHP_EOL
                    . '  PostgreSQL clones a database only while nobody else is connected to it — close pgAdmin/psql and stop the dev server, then retry.');
            }
            out("database {$info['db']} cloned from {$info['real']}");
        } else {
            $server->exec('CREATE DATABASE "' . $info['db'] . "\" ENCODING 'UTF8' TEMPLATE template0");
            out("database {$info['db']} created (empty)");
        }

        if ($has('with-assets')) {
            out('copied assets/: ' . round(copy_dir($repo . '/assets', $dir . '/assets') / 1048576, 1) . ' MB');
        }

        $args = ['setup.php', '--admin=' . TEST_ADMIN, '--password=' . TEST_PASSWORD];
        if (!$has('clone-db') && !$has('no-demo')) {
            $args[] = '--demo';
        }
        if (run_php($args, $dir) !== 0) {
            stop('setup.php failed — the message above says which migration and why.');
        }
        out('');
        out('ready.  serve:  php .claude/skills/dg-dev/scripts/testsite.php serve');
        out('        login:  ' . TEST_ADMIN . ' / ' . TEST_PASSWORD . '   (' . ($has('clone-db') ? 'site mode is whatever the cloned database had' : 'site mode starts as "wip"')
            . '; a logged-in user always sees the live page)');
        exit(0);

    case 'sync':
        is_dir($dir) || stop('no test copy yet — run create first.');
        [$copied, $removed] = sync_files($repo, $dir);
        out("synced: $copied copied, $removed removed");
        migrate_copy($dir);
        exit(0);

    case 'serve':
        is_dir($dir) || stop('no test copy yet — run create first.');
        [$copied, $removed] = sync_files($repo, $dir);
        migrate_copy($dir);
        $port = (int) $value('port', '8765');
        out("synced ($copied copied, $removed removed) — http://127.0.0.1:$port/   login: " . TEST_ADMIN . ' / ' . TEST_PASSWORD);
        exit(run_php(['-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', '127.0.0.1:' . $port, 'router.php'], $dir));

    case 'smoke':
        is_dir($dir) || stop('no test copy yet — run create first.');
        if (!$has('no-sync')) {
            [$copied, $removed] = sync_files($repo, $dir);
            out("synced: $copied copied, $removed removed");
            migrate_copy($dir);
        }
        $port = free_port();
        $server = start_server($dir, $port);
        $pass = array_values(array_filter($flags, static fn (string $f): bool => str_starts_with($f, '--only=') || $f === '--verbose'));
        $code = run_php(array_merge([__DIR__ . '/smoke.php', '--root=' . $dir, '--base=http://127.0.0.1:' . $port], $pass), $dir);
        proc_terminate($server);
        proc_close($server);
        exit($code);

    case 'destroy':
        $info = test_database($repo);
        try {
            drop_database(server_pdo($info), $info['db']);
            out('dropped database ' . $info['db']);
        } catch (Throwable $e) {
            out('database not dropped: ' . $e->getMessage());
        }
        remove_dir($dir);
        out('removed ' . $dir);
        exit(0);

    default:
        out(preg_replace('~^/\*\*|^ \*/?| ?\*/$~m', '', (string) preg_replace('~^.*?(/\*\*.*?\*/).*$~s', '$1', (string) file_get_contents(__FILE__))));
        exit($command === 'help' ? 0 : 1);
}
