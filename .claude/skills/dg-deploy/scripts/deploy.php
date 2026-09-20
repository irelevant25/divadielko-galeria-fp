<?php
/**
 * Deployment helper — the deterministic half of a deploy. It never opens a network
 * connection itself; it writes the command files for sftp and checks the result.
 *
 *   php .claude/skills/dg-deploy/scripts/deploy.php plan   --target=test|main [--with-htaccess] [--since=<git ref>] [--only=<path prefix>]
 *   php .claude/skills/dg-deploy/scripts/deploy.php verify --target=test|main --listing=<file with the sftp output>
 *
 *   plan    lists what belongs on the server and writes two sftp command files to the temp folder
 *           (it prints their paths):  …-upload.sftp  mkdir + put for every file
 *                                     …-verify.sftp  ls -la of every remote folder
 *           Refuses a working tree with uncommitted changes: only tracked files are planned, so a
 *           new, still untracked migration would stay behind. --allow-dirty plans anyway (to look, not to upload).
 *           --since=<ref>     only files changed since that commit/tag (default: everything — 60 small
 *                             files, and uploading all of them is what keeps the server from drifting)
 *           --only=<prefix>   only paths starting with this, e.g. --only=includes/migrations/ to send
 *                             an additive migration ahead of the code that uses it
 *           --with-htaccess   include the root .htaccess (left out by default: the server's copy may
 *                             have the HTTPS block switched on — compare before overwriting)
 *   verify  reads the output of the verify command file and compares every size with the local file.
 *           A size that differs — typically 0 bytes after a broken transfer — fails the check.
 *
 * What never goes to the server: includes/config.local.php, uploaded media (assets/*, assets_original/*),
 * storage/*, router.php (dev only), README/CLAUDE.md/.claude/.gitignore. The .htaccess files inside
 * assets/, assets_original/, storage/, includes/ and pages/ DO go — they are what keeps those folders shut.
 */

declare(strict_types=1);

const TARGETS = [
    'test' => '/divadielkogaleria.sk/sub/test',
    'main' => '/divadielkogaleria.sk/web',
];

$repo = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 4)));
$command = $argv[1] ?? 'help';
$args = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

function fail(string $message): void
{
    fwrite(STDERR, 'deploy: ' . $message . PHP_EOL);
    exit(1);
}

function git(string $repo, string $arguments): string
{
    // rtrim only: the first column of "git status --porcelain" is a significant space
    return rtrim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' ' . $arguments));
}

/** Files that belong on the server, relative to the repository root. */
function deployable(string $repo, bool $withHtaccess): array
{
    $files = [];
    foreach (preg_split('/\R/', git($repo, 'ls-files')) as $rel) {
        if ($rel === '' || !is_file($repo . '/' . $rel)) {
            continue;
        }
        $skip = preg_match('~^(\.claude/|\.git|README\.md$|CLAUDE\.md$|router\.php$)~', $rel)
            || str_ends_with($rel, '.gitkeep')
            || (!$withHtaccess && $rel === '.htaccess');
        if (!$skip) {
            $files[] = $rel;
        }
    }
    sort($files);

    return $files;
}

$target = (string) ($args['target'] ?? '');
if (in_array($command, ['plan', 'verify'], true) && !isset(TARGETS[$target])) {
    fail('say where: --target=test (test subdomain) or --target=main (the live site)');
}
$remote = TARGETS[$target] ?? '';
$tmp = str_replace('\\', '/', rtrim(sys_get_temp_dir(), '/\\'));

switch ($command) {
    case 'plan':
        // Only committed-and-tracked files are planned. A dirty tree is how a half release happens:
        // a modified entities.php would go up while its brand-new (untracked) migration stays behind.
        $dirty = git($repo, 'status --porcelain -- . ":(exclude).claude" ":(exclude)CLAUDE.md" ":(exclude)README.md"');
        if ($dirty !== '' && !isset($args['allow-dirty'])) {
            fwrite(STDERR, "deploy: the working tree has uncommitted changes — commit first.\n\n$dirty\n\n"
                . "  Modified files would be uploaded as they are on disk; files marked ?? are NOT uploaded at all\n"
                . "  (only tracked files are), so a new migration or partial would be missing on the server.\n"
                . "  To inspect a plan anyway: add --allow-dirty (never upload from it).\n");
            exit(1);
        }
        $files = deployable($repo, isset($args['with-htaccess']));
        if (is_string($args['since'] ?? null)) {
            $changed = array_filter(preg_split('/\R/', git($repo, 'diff --name-only ' . escapeshellarg($args['since']) . ' HEAD')));
            $files = array_values(array_intersect($files, $changed));
        }
        if (is_string($args['only'] ?? null)) {
            $files = array_values(array_filter($files, static fn (string $f): bool => str_starts_with($f, $args['only'])));
        }
        if (!$files) {
            fail('nothing to upload.');
        }

        $dirs = [];
        foreach ($files as $rel) {
            for ($dir = dirname($rel); $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
                $dirs[$dir] = true;
            }
        }
        $dirs = array_keys($dirs);
        sort($dirs); // parents before children

        $upload = [];
        foreach ($dirs as $dir) {
            $upload[] = '-mkdir "' . $remote . '/' . $dir . '"'; // "-" = carry on when the folder already exists
        }
        foreach ($files as $rel) {
            $upload[] = 'put "' . $rel . '" "' . $remote . '/' . $rel . '"';
        }
        // -a: sftp hides dotfiles otherwise, and the .htaccess files are exactly what must not go missing
        $verify = ['ls -la "' . $remote . '"'];
        foreach ($dirs as $dir) {
            $verify[] = 'ls -la "' . $remote . '/' . $dir . '"';
        }
        $tag = substr(md5($repo), 0, 6); // per checkout, so two working copies do not overwrite each other's plan
        $uploadFile = "$tmp/dg-deploy-$tag-$target-upload.sftp";
        $verifyFile = "$tmp/dg-deploy-$tag-$target-verify.sftp";
        file_put_contents($uploadFile, implode("\n", $upload) . "\nbye\n");
        file_put_contents($verifyFile, implode("\n", $verify) . "\nbye\n");

        $migrations = array_values(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'includes/migrations/')));
        echo 'target:     ', $target, '  →  ', $remote, PHP_EOL;
        echo 'commit:     ', git($repo, 'log -1 --format="%h %s"'), PHP_EOL;
        echo 'files:      ', count($files), '  (', number_format(array_sum(array_map(static fn (string $f): int => (int) filesize($repo . '/' . $f), $files)) / 1024, 0, ',', ' '), ' kB)',
            is_string($args['since'] ?? null) ? '  changed since ' . $args['since'] : '', PHP_EOL;
        echo 'migrations: ', $migrations ? 'newest in this upload: ' . basename((string) end($migrations)) . '  — open setup.php?key=… afterwards (once; the database is shared)' : 'none in this upload', PHP_EOL;
        echo '.htaccess:  ', isset($args['with-htaccess']) ? 'root .htaccess INCLUDED — make sure the server copy has no local changes (HTTPS block)' : 'root .htaccess left out (compare with the server copy, then use --with-htaccess)', PHP_EOL;
        if ($dirty !== '') {
            echo PHP_EOL, 'WARNING (--allow-dirty): uncommitted changes — do NOT upload from this plan. Files marked ?? are not in it:', PHP_EOL, $dirty, PHP_EOL;
        }
        echo PHP_EOL, 'upload commands: ', $uploadFile, PHP_EOL, 'verify commands: ', $verifyFile, PHP_EOL;
        echo 'Run sftp from the repository root (the put paths are relative) — see the dg-deploy skill.', PHP_EOL;
        exit(0);

    case 'verify':
        $listing = (string) ($args['listing'] ?? '');
        if (!is_file($listing)) {
            fail('--listing=<file> — save the output of sftp run with the verify command file.');
        }
        // sftp echoes each command ("sftp> ls -l "/remote/dir"") and prints names bare or with the folder in front.
        $sizes = [];
        $dir = '';
        foreach (preg_split('/\R/', (string) file_get_contents($listing)) as $line) {
            if (preg_match('~^sftp>\s*-?ls\s+-[la]+\s+"?([^"]+?)"?\s*$~', $line, $m)) {
                $dir = rtrim($m[1], '/');
                continue;
            }
            if (preg_match('~^-\S+\s+\S+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\d+\s+[\d:]+\s+(.+)$~', $line, $m)) {
                $path = str_starts_with($m[2], '/') ? $m[2] : $dir . '/' . basename($m[2]);
                $sizes[$path] = (int) $m[1];
            }
        }
        if (!$sizes) {
            fail('no "ls -l" lines found in ' . $listing . ' — is it the output of the verify command file?');
        }

        $problems = 0;
        $checked = 0;
        foreach (deployable($repo, true) as $rel) {
            $path = $remote . '/' . $rel;
            $local = (int) filesize($repo . '/' . $rel);
            if (!isset($sizes[$path])) {
                if ($rel === '.htaccess') {
                    continue;
                }
                $problems++;
                echo '  MISSING   ', $rel, PHP_EOL;
            } elseif ($sizes[$path] !== $local) {
                if ($rel === '.htaccess') {
                    echo '  note      .htaccess differs from the repository (', $sizes[$path], ' vs ', $local, ' bytes) — expected when the HTTPS block is switched on there', PHP_EOL;
                    continue;
                }
                $problems++;
                echo '  DIFFERENT ', $rel, '  remote ', $sizes[$path], ' B, local ', $local, ' B', $sizes[$path] === 0 ? '   ← empty file: the transfer broke, the site is running broken code' : '', PHP_EOL;
            } else {
                $checked++;
            }
        }
        $known = array_flip(array_map(static fn (string $rel): string => $remote . '/' . $rel, deployable($repo, true)));
        foreach (array_keys($sizes) as $path) {
            if (!str_starts_with($path, $remote . '/')) {
                continue; // a listing may cover both installs
            }
            $rel = substr($path, strlen($remote) + 1);
            if ($rel === 'router.php') {
                echo '  note      router.php is on the server — it is for local development only (.htaccess blocks it, but it can be removed)', PHP_EOL;
            } elseif (!isset($known[$path]) && preg_match('~\.(php|js|css|sql)$~', $path) && $rel !== 'includes/config.local.php') {
                echo '  note      ', $rel, ' exists only on the server (deleted or renamed in the repository?)', PHP_EOL;
            }
        }
        echo PHP_EOL, $problems ? "FAILED: $problems file(s) wrong — upload again and verify again before doing anything else" : "OK: $checked files match", PHP_EOL;
        exit($problems ? 1 : 0);

    default:
        echo preg_replace('~^/\*\*|^ \*/?| ?\*/$~m', '', (string) preg_replace('~^.*?(/\*\*.*?\*/).*$~s', '$1', (string) file_get_contents(__FILE__))), PHP_EOL;
        exit($command === 'help' ? 0 : 1);
}
