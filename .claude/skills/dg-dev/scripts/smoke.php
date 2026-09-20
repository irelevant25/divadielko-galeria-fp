<?php
/**
 * End-to-end smoke / regression test against a RUNNING test copy of the site.
 * Normally started through:   php .claude/skills/dg-dev/scripts/testsite.php smoke
 *
 *   php smoke.php --root=<test copy> --base=http://127.0.0.1:<port> [--only=<section>[,<section>]] [--verbose]
 *
 * Sections: units, public, login, editor, roles, api, upload, order, visibility, placeholder,
 *           contact, backup, throttle
 *
 * It talks to the server over HTTP like a browser would, and loads the copy's own PHP to set up
 * fixtures and look into the database. Everything it creates is marked SMOKE; when it finishes
 * the database is restored from a backup taken at the start, uploaded files and mails are removed.
 *
 * It refuses to run unless the copy's database name ends with _claude_test — it archives,
 * purges, restores and floods the contact form, none of which belongs near real content.
 */

declare(strict_types=1);

$opts = getopt('', ['root:', 'base:', 'only:', 'verbose']);
$root = rtrim(str_replace('\\', '/', (string) realpath((string) ($opts['root'] ?? ''))), '/');
$base = rtrim((string) ($opts['base'] ?? ''), '/');
if ($root === '' || $base === '' || !is_file($root . '/includes/bootstrap.php')) {
    fwrite(STDERR, "usage: php smoke.php --root=<test copy> --base=http://127.0.0.1:<port>   (or simply: testsite.php smoke)\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "smoke.php needs the PHP curl extension.\n");
    exit(2);
}

require $root . '/includes/bootstrap.php';
require $root . '/includes/cms.php';
require $root . '/includes/backup.php';
require $root . '/includes/mail.php';

if (!preg_match('/dbname=[A-Za-z0-9_]*_claude_test\b/', (string) config('db.dsn'))) {
    fwrite(STDERR, "Refusing to run: the database of $root is not a *_claude_test database.\n");
    exit(2);
}
if (!db_available()) {
    fwrite(STDERR, "The test database is not reachable or not migrated — run: testsite.php create\n");
    exit(2);
}

// ── Tiny HTTP client (one cookie jar per instance = one browser) ─────────────

final class SmokeHttp
{
    private $curl;

    public function __construct(private string $base)
    {
        $this->curl = curl_init();
    }

    /** @return array{status: int, headers: array<string, string>, cookies: string[], body: string, location: string} */
    public function request(string $method, string $path, array $options = []): array
    {
        curl_reset($this->curl);
        $headers = $options['headers'] ?? [];
        $set = [
            CURLOPT_URL            => $this->base . $path,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_COOKIEFILE     => '', // in-memory cookie engine; survives curl_reset()
        ];
        if (isset($options['json'])) {
            $headers[] = 'Content-Type: application/json';
            $set[CURLOPT_POSTFIELDS] = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (isset($options['multipart'])) {
            $set[CURLOPT_POSTFIELDS] = $options['multipart'];
        } elseif (isset($options['form'])) {
            $set[CURLOPT_POSTFIELDS] = http_build_query($options['form']);
        }
        $set[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($this->curl, $set);

        $raw = curl_exec($this->curl);
        if (!is_string($raw)) {
            throw new RuntimeException("$method $path: " . curl_error($this->curl));
        }
        $split = (int) curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $out = ['status' => (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), 'headers' => [], 'cookies' => [], 'body' => substr($raw, $split), 'location' => ''];
        foreach (preg_split('/\r?\n/', substr($raw, 0, $split)) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $name = strtolower($name);
            if ($name === 'set-cookie') {
                $out['cookies'][] = $value;
            }
            $out['headers'][$name] = $value;
        }
        $out['location'] = $out['headers']['location'] ?? '';

        return $out;
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, ['headers' => $headers]);
    }

    public function post(string $path, array $form, array $headers = []): array
    {
        return $this->request('POST', $path, ['form' => $form, 'headers' => $headers]);
    }
}

// ── Test runner ──────────────────────────────────────────────────────────────

final class Smoke
{
    public int $passed = 0;
    public array $failed = [];
    private string $section = '';

    public function __construct(private bool $verbose, private array $only)
    {
    }

    public function section(string $name, callable $body): void
    {
        if ($this->only && !in_array($name, $this->only, true)) {
            return;
        }
        $this->section = $name;
        echo PHP_EOL, '== ', $name, PHP_EOL;
        try {
            $body();
        } catch (Throwable $e) {
            $this->ok(false, 'section finished without an exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    public function ok(bool $condition, string $label, string $detail = ''): bool
    {
        if ($condition) {
            $this->passed++;
            if ($this->verbose) {
                echo '  ok    ', $label, PHP_EOL;
            }
        } else {
            $detail = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $detail)), 0, 300);
            $this->failed[] = "[$this->section] $label" . ($detail !== '' ? " — $detail" : '');
            echo '  FAIL  ', $label, $detail !== '' ? PHP_EOL . '        ' . $detail : '', PHP_EOL;
        }

        return $condition;
    }

    public function same($expected, $actual, string $label): bool
    {
        return $this->ok($expected === $actual, $label, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public function status(int $expected, array $response, string $label): bool
    {
        return $this->ok($response['status'] === $expected, $label, "expected HTTP $expected, got {$response['status']}" . ($response['location'] !== '' ? " → {$response['location']}" : '')
            . ($response['status'] >= 400 ? ' · ' . mb_substr(trim(strip_tags($response['body'])), 0, 160) : ''));
    }

    /** A PHP warning/notice printed into the page (the test server runs with display_errors=1). */
    public function clean(array $response, string $label): bool
    {
        $hit = preg_match('~<b>(?:Warning|Notice|Deprecated|Fatal error|Parse error)</b>[^\n]{0,200}|Uncaught [^\n]{0,200}~', $response['body'], $m);

        return $this->ok(!$hit, $label . ' renders without PHP errors', $hit ? trim(strip_tags($m[0])) : '');
    }
}

$t = new Smoke(isset($opts['verbose']), array_filter(explode(',', (string) ($opts['only'] ?? ''))));
$visitor = new SmokeHttp($base);
$editor  = new SmokeHttp($base); // logs in as the admin "tester" (created by testsite.php)
$csrf    = '';

$loginAs = static function (SmokeHttp $http, string $username, string $password, string $next = '/') {
    $page = $http->get('/login.php');
    preg_match('/name="csrf" value="([a-f0-9]{64})"/', $page['body'], $m);

    return $http->post('/login.php', ['csrf' => $m[1] ?? '', 'username' => $username, 'password' => $password, 'next' => $next]);
};
$api = static function (SmokeHttp $http, string $action, array $body, string $token) {
    $response = $http->request('POST', '/api.php?action=' . $action, ['json' => $body, 'headers' => ['X-CSRF-Token: ' . $token]]);
    $response['json'] = json_decode($response['body'], true);

    return $response;
};
$apiGet = static function (SmokeHttp $http, string $query) {
    $response = $http->get('/api.php?' . $query);
    $response['json'] = json_decode($response['body'], true);

    return $response;
};
$admin = static function (SmokeHttp $http, string $tab, array $form, string $token) {
    return $http->post('/admin.php?tab=' . $tab, ['csrf' => $token] + $form);
};
$setMode = static function (string $mode): void {
    save_setting('site_mode', $mode);
};

// ── Start state: backup to return to, leftovers of a crashed run removed ─────

$cleanup = static function (): void {
    db_exec("DELETE FROM productions WHERE title_sk LIKE 'SMOKE%'"); // cascades to runs, performances, history
    db_exec("DELETE FROM history WHERE title_sk LIKE 'SMOKE%'");
    db_exec("DELETE FROM photos WHERE caption_sk LIKE 'SMOKE%'");
    db_exec("DELETE FROM messages WHERE name LIKE 'SMOKE%'");
    db_exec("DELETE FROM users WHERE username IN ('smoke_editor', 'smoke_editor_video')");
    db_exec('DELETE FROM login_attempts');
    // conversion jobs: a crashed run may have left one half-done, and it would be picked up by the next run
    foreach (glob(ROOT . '/storage/uploads/job-*', GLOB_ONLYDIR) ?: [] as $dir) {
        video_job_remove(substr(basename($dir), 4));
    }
};
$cleanup();
$filesBefore = [
    'assets'          => array_flip(scandir($root . '/assets') ?: []),
    'assets_original' => array_flip(scandir($root . '/assets_original') ?: []),
    'storage/mail'    => array_flip(is_dir($root . '/storage/mail') ? scandir($root . '/storage/mail') : []),
];
$backupsBefore = array_column(backup_list(), 'name');
$modeBefore = (string) (db_value("SELECT value FROM settings WHERE key = 'site_mode'") ?? 'wip');
$startBackup = backup_create('smoke-start', 'smoke test — state to return to', 'smoke')['name'];

// Fixtures: one public and one hidden production, runs, dates, history.
$pPublic = (int) db_value("INSERT INTO productions (title_sk, description_sk, is_public, sort) VALUES ('SMOKE-PUBLIC', 'Opis verejnej inscenácie.', true, 9001) RETURNING id");
$pHidden = (int) db_value("INSERT INTO productions (title_sk, is_public, sort) VALUES ('SMOKE-HIDDEN', false, 9002) RETURNING id");
$rPublic = (int) db_value("INSERT INTO runs (production_id, is_public, price_sk, venue_sk, sort) VALUES (?, true, '3 €', 'SMOKE-VENUE', 9001) RETURNING id", [$pPublic]);
$rHiddenRun = (int) db_value("INSERT INTO runs (production_id, is_public, venue_sk, sort) VALUES (?, false, 'SMOKE-SECRET-RUN-VENUE', 9002) RETURNING id", [$pPublic]);
$rOfHidden = (int) db_value("INSERT INTO runs (production_id, is_public, sort) VALUES (?, false, 9003) RETURNING id", [$pHidden]);
db_exec("INSERT INTO performances (run_id, starts_at) VALUES (?, now() + interval '20 days'), (?, now() - interval '400 days')", [$rPublic, $rPublic]);
db_exec("INSERT INTO performances (run_id, starts_at) VALUES (?, now() - interval '800 days')", [$rOfHidden]);
db_exec("INSERT INTO history (year, production_id, place_sk) VALUES (1987, ?, 'SMOKE-HIDDEN-PLACE')", [$pHidden]);
// The one deliberate asymmetry: a hidden production with a PUBLISHED run shows in „Práve hráme", never in História.
$pRunOnly = (int) db_value("INSERT INTO productions (title_sk, is_public, sort) VALUES ('SMOKE-RUNONLY', false, 9004) RETURNING id");
$rRunOnly = (int) db_value("INSERT INTO runs (production_id, is_public, sort) VALUES (?, true, 9004) RETURNING id", [$pRunOnly]);
db_exec("INSERT INTO performances (run_id, starts_at) VALUES (?, now() + interval '30 days'), (?, now() - interval '500 days')", [$rRunOnly, $rRunOnly]);
db_exec("INSERT INTO history (year, title_sk, text_sk) VALUES (1988, 'SMOKE-EVENT', 'Text udalosti.')");

try {
    // ── units: pure helpers, no HTTP ─────────────────────────────────────────

    $t->section('units', static function () use ($t): void {
        $t->same('plagat-sipkova-ruzenka', media_slug('Plagát – Šípková Ruženka.JPG'), 'media_slug strips diacritics');
        $t->same('subor', media_slug('!!!.jpg'), 'media_slug falls back for symbol-only names');
        $t->same(null, media_safe_name('../../includes/config.local.php'), 'media_safe_name rejects non-media paths');
        $t->same('foto.jpg', media_safe_name('../x/foto.jpg'), 'media_safe_name keeps only the base name');
        $t->same(null, media_safe_name('shell.php'), 'media_safe_name rejects .php');

        $t->same('<p>Prvý<br>riadok</p><p>Druhý <strong>tučný</strong></p>', rich_html("Prvý\nriadok\n\nDruhý <b>tučný</b>"), 'rich_html turns legacy plain text into paragraphs');
        $t->same('<p>x</p>', rich_html('<p><a href="javascript:alert(1)">x</a></p>'), 'rich_html drops javascript: links');
        $t->same('<p>x</p>', rich_html('<p><a href="//evil.example">x</a></p>'), 'rich_html drops protocol-relative links');
        $t->same('<p>a</p>', rich_html('<p onclick="x()">a</p><script>alert(1)</script><img src=x onerror=alert(1)>'), 'rich_html drops handlers, scripts and images');
        $t->same('', rich_html('<p><br></p><p>&nbsp;</p>'), 'rich_html treats empty markup as empty');
        $once = rich_html('<div>x <i>y</i></div><h2>z</h2><ul><li><p>a</p></li></ul>');
        $t->same($once, rich_html($once), 'rich_html is idempotent');

        $kind = static fn (string $url): ?string => video_info(['url' => $url, 'file' => null, 'poster' => null])['kind'] ?? null;
        $t->same('youtube', $kind('https://www.youtube.com/watch?feature=share&v=aqz-KE-bpKQ'), 'video_info: youtube watch URL');
        $t->same('youtube', $kind('https://youtu.be/aqz-KE-bpKQ?t=10'), 'video_info: youtu.be');
        $t->same('youtube', $kind('https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ'), 'video_info: youtube-nocookie embed');
        $t->same('link', $kind('https://notyoutube.com/watch?v=aqz-KE-bpKQ'), 'video_info: look-alike host is not YouTube');
        $t->same('link', $kind('https://evil.example/youtube.com/embed/aqz-KE-bpKQ'), 'video_info: youtube.com in the path is not YouTube');
        $t->same('youtube', $kind('https://Youtu.be/aqz-KE-bpKQ'), 'video_info: host is case-insensitive (phone keyboards capitalise)');
        $t->same('aqz-KE-bpKQ', video_info(['url' => 'https://M.YouTube.com/watch?v=aqz-KE-bpKQ', 'file' => null, 'poster' => null])['id'] ?? null, 'video_info: the video id keeps its case');
        $t->same('instagram', $kind('https://www.instagram.com/reel/Cabc123/'), 'video_info: instagram reel');

        $value = static function (array $field, $raw) {
            try {
                return cms_value('col', $field, $raw, false);
            } catch (CmsError $e) {
                return 'ERR';
            }
        };
        $t->same('2026-10-01 16:00:00', $value(['type' => 'datetime'], '2026-10-01T16:00'), 'cms_value: datetime');
        $t->same('2026-10-01 16:00:00', $value(['type' => 'datetime'], '2026-10-01 16:00:59'), 'cms_value: datetime with a space and seconds');
        $t->same('ERR', $value(['type' => 'datetime'], '2026-02-31T16:00'), 'cms_value: 31 February is rejected, not rolled over to March');
        $t->same('ERR', $value(['type' => 'datetime'], '2026-10-01T--:--'), 'cms_value: date without time is rejected');
        $t->same('ERR', $value(['type' => 'date'], '2026-02-30'), 'cms_value: impossible date is rejected');
        $t->same('https://example.com/x', $value(['type' => 'url'], 'example.com/x'), 'cms_value: url gets https://');
        $t->same('https://sk.wikipedia.org/wiki/Nov%C3%A9_Mesto', $value(['type' => 'url'], 'https://sk.wikipedia.org/wiki/Nové_Mesto'), 'cms_value: diacritics in a URL are percent-encoded');
        $t->same('https://a.sk/x%20y%C3%A9', $value(['type' => 'url'], 'https://a.sk/x y%C3%A9'), 'cms_value: a space is encoded, an existing %XX is not encoded twice');
        $t->same('ERR', $value(['type' => 'url'], 'https://a.sk/' . str_repeat('é', 90)), 'cms_value: a URL over 500 characters is refused, not cut in the middle of %XX');
        $t->same('ERR', $value(['type' => 'url'], 'javascript:alert(1)'), 'cms_value: javascript: URL is rejected');
        $t->same('ERR', $value(['type' => 'number', 'min' => 1900, 'max' => 2100], '1800'), 'cms_value: number range');
        $t->same(null, $value(['type' => 'text'], ['array']), 'cms_value: array for a text field becomes empty');

        $t->same('"Novak, Jan (riaditel)"', mail_name('Novak, Jan (riaditel)'), 'mail_name quotes an ASCII name with a comma');
        $t->same('"Jan \\"J\\" Novak\\\\"', mail_name('Jan "J" Novak\\'), 'mail_name escapes quotes and backslashes');
        $t->ok(str_starts_with(mail_name('Ján Novák'), '=?UTF-8?B?'), 'mail_name encodes diacritics');
        $t->same('Jedna dva tri štyri…', excerpt('Jedna dva tri štyri päť šesť sedem osem deväť desať', 20), 'excerpt cuts at a word');
        $t->same(SECTIONS[0], section_order()[0], 'section_order keeps Domov first');
    });

    // ── public: what an anonymous visitor may reach ──────────────────────────

    $t->section('public', static function () use ($t, $visitor, $setMode): void {
        $setMode('wip');
        $home = $visitor->get('/');
        $t->status(503, $home, 'wip mode answers 503');
        $t->ok(isset($home['headers']['retry-after']), '503 carries Retry-After (search engines must not index the placeholder for good)');
        $t->ok(!$home['cookies'], 'a visitor gets no cookie (that is why the site needs no cookie banner)');
        $t->ok(str_contains($home['headers']['content-security-policy'] ?? '', "default-src 'self'"), 'Content-Security-Policy is sent');
        $t->same('nosniff', $home['headers']['x-content-type-options'] ?? '', 'X-Content-Type-Options: nosniff');
        $t->clean($home, 'placeholder');

        $t->status(404, $visitor->get('/missing-image.png'), 'a missing static file is a plain 404, not the whole page');
        foreach (['/includes/config.php', '/includes/config.local.php', '/storage/', '/pages/site.php', '/README.md', '/.git/config', '/router.php', '/assets/x.php', '/includes/migrations/001_init.sql'] as $path) {
            $t->status(403, $visitor->get($path), "$path is forbidden");
        }
        $t->status(404, $visitor->get('/setup.php'), 'setup.php without the key pretends not to exist');
        $t->status(404, $visitor->get('/setup.php?key=wrong'), 'setup.php with a wrong key pretends not to exist');
        $adminPage = $visitor->get('/admin.php?tab=files');
        $t->ok($adminPage['status'] === 303 && str_contains($adminPage['location'], '/login.php?next='), 'admin.php sends an anonymous visitor to the login');
        $t->status(401, $visitor->get('/api.php?action=item&entity=productions&id=1'), 'the API needs a login');
        $t->status(401, $visitor->request('POST', '/api.php?action=upload'), 'upload needs a login');
        $t->status(200, $visitor->get('/robots.txt'), 'robots.txt is served');
    });

    // ── login ────────────────────────────────────────────────────────────────

    $t->section('login', static function () use ($t, $base, $editor, $loginAs, &$csrf): void {
        $stranger = new SmokeHttp($base);
        $page = $stranger->get('/login.php');
        $t->clean($page, 'login page');
        $t->ok(str_contains($page['headers']['x-robots-tag'] ?? '', 'noindex'), 'login page is noindex');
        $noToken = $stranger->post('/login.php', ['csrf' => 'x', 'username' => 'tester', 'password' => 'tester-heslo-123']);
        $t->ok($noToken['status'] === 200 && !str_contains($noToken['location'], '/'), 'login without a valid CSRF token is refused');
        $wrong = $loginAs($stranger, 'tester', 'wrong-password');
        $t->ok($wrong['status'] === 200 && str_contains($wrong['body'], e(t('login_failed'))), 'a wrong password shows the generic message');

        $evil = $loginAs(new SmokeHttp($base), 'tester', 'tester-heslo-123', '//evil.example/x');
        $t->ok($evil['status'] === 303 && $evil['location'] === '/', 'next=//evil.example is not followed (no open redirect)', 'Location: ' . $evil['location']);

        $good = $loginAs($editor, 'tester', 'tester-heslo-123', '/admin.php?tab=files');
        $t->ok($good['status'] === 303 && $good['location'] === '/admin.php?tab=files', 'a correct login redirects to next', 'Location: ' . $good['location']);
        $t->ok((bool) array_filter($good['cookies'], static fn (string $c): bool => stripos($c, 'httponly') !== false && stripos($c, 'samesite=lax') !== false), 'session cookie is HttpOnly + SameSite=Lax');

        $home = $editor->get('/');
        preg_match('/"csrf":"([a-f0-9]{64})"/', $home['body'], $m);
        $csrf = $m[1] ?? '';
        $t->ok($csrf !== '', 'the editor page carries the CSRF token for cms.js');
        db_exec('DELETE FROM login_attempts');
    });
    if ($csrf === '') { // sections below need a session even when "login" was filtered out by --only
        $loginAs($editor, 'tester', 'tester-heslo-123');
        preg_match('/"csrf":"([a-f0-9]{64})"/', $editor->get('/')['body'], $m);
        $csrf = $m[1] ?? '';
    }

    // ── editor: every page a logged-in user can open ─────────────────────────

    $t->section('editor', static function () use ($t, $editor, $setMode): void {
        $setMode('wip');
        $home = $editor->get('/');
        $t->status(200, $home, 'a logged-in user sees the live page even in wip mode');
        $t->clean($home, 'live page (editor)');
        $t->ok(str_contains($home['body'], 'data-cms-action') && str_contains($home['body'], 'class="adminbar"'), 'pencils and the admin bar are rendered');
        $t->ok(str_contains($home['headers']['cache-control'] ?? '', 'no-store'), 'editor pages are never cached');
        $t->ok(str_contains($home['headers']['x-robots-tag'] ?? '', 'noindex'), 'editor view of a non-live site is noindex');
        $t->status(503, $editor->get('/?preview=wip'), '?preview=wip shows the editor what the public sees');
        $t->ok(!str_contains($editor->get('/?preview=maintenance')['body'], 'data-cms-action'), 'the preview has no pencils');

        foreach (['messages', 'files', 'archive', 'sections', 'users', 'backups', 'settings', 'account'] as $tab) {
            $page = $editor->get('/admin.php?tab=' . $tab);
            $t->status(200, $page, "admin tab $tab");
            $t->clean($page, "admin tab $tab");
        }
    });

    // ── roles: what a redaktor must not be able to do ────────────────────────

    $t->section('roles', static function () use ($t, $base, $loginAs, $admin, $startBackup): void {
        db_exec("INSERT INTO users (username, password_hash, role) VALUES ('smoke_editor', ?, 'editor')", [password_hash('smoke-editor-heslo', PASSWORD_DEFAULT)]);
        $redaktor = new SmokeHttp($base);
        $loginAs($redaktor, 'smoke_editor', 'smoke-editor-heslo');
        preg_match('/"csrf":"([a-f0-9]{64})"/', $redaktor->get('/')['body'], $m);
        $token = $m[1] ?? '';

        $users = $redaktor->get('/admin.php?tab=users');
        $t->ok(!str_contains($users['body'], 'name="do" value="user_save"'), 'the users tab is not shown to an editor');
        $t->status(403, $redaktor->get('/admin.php?backup=' . rawurlencode($startBackup)), 'an editor cannot download a backup (it holds users and messages)');
        foreach ([
            'mode_save' => ['mode' => 'live'], 'user_save' => ['id' => 0, 'username' => 'smoke_x', 'password' => 'dlhe-heslo-12345', 'role' => 'admin'],
            'backup_create' => [], 'backup_restore' => ['name' => $startBackup], 'backup_delete' => ['name' => $startBackup],
            'file_delete' => ['name' => 'whatever.avif'], 'arch_purge' => ['entity' => 'photos', 'id' => 1], 'msg_delete' => ['id' => 1], 'user_delete' => ['id' => 1],
        ] as $do => $form) {
            $t->status(403, $admin($redaktor, 'messages', ['do' => $do] + $form, $token), "editor → $do is forbidden");
        }
        $t->same(0, (int) db_value("SELECT count(*) FROM users WHERE username = 'smoke_x'"), 'no account was created by the editor');
        $t->ok(is_file(backup_path($startBackup)), 'the backup the editor tried to delete still exists');
        db_exec("DELETE FROM users WHERE username = 'smoke_editor'");
    });

    // ── api: guards and validation ───────────────────────────────────────────

    $t->section('api', static function () use ($t, $editor, $api, $apiGet, &$csrf, $pPublic, $rPublic): void {
        $item = $apiGet($editor, 'action=item&entity=productions&id=' . $pPublic);
        $t->ok($item['status'] === 200 && ($item['json']['item']['title_sk'] ?? '') === 'SMOKE-PUBLIC' && is_array($item['json']['schema'] ?? null), 'GET item returns the row and the form schema');
        $t->status(422, $apiGet($editor, 'action=item&entity=users&id=1'), 'a table outside entities.php is not reachable');
        $t->status(422, $apiGet($editor, 'action=item&entity=' . rawurlencode('productions; DROP TABLE users') . '&id=1'), 'entity names are never interpolated unchecked');
        $t->status(422, $apiGet($editor, 'action=item&entity[]=productions&id=1'), 'entity sent as an array');
        $t->status(422, $apiGet($editor, 'action=settings&group=nope'), 'unknown settings group');
        $t->status(419, $api($editor, 'delete', ['entity' => 'productions', 'id' => $pPublic], 'wrong-token'), 'POST without the CSRF token is refused');
        $t->same(1, (int) db_value('SELECT count(*) FROM productions WHERE id = ? AND deleted_at IS NULL', [$pPublic]), '… and nothing was deleted');

        $full = $item['json']['item'] ?? [];
        unset($full['id']);
        $saved = $api($editor, 'save', ['entity' => 'productions', 'id' => $pPublic, 'values' => ['subtitle_sk' => 'Podnázov', 'deleted_at' => '2020-01-01', 'sort' => 1, 'id' => 99999] + $full], $csrf);
        $row = db_one('SELECT subtitle_sk, deleted_at, sort FROM productions WHERE id = ?', [$pPublic]);
        $t->ok($saved['status'] === 200 && $row['subtitle_sk'] === 'Podnázov' && $row['deleted_at'] === null && (int) $row['sort'] === 9001, 'save writes only columns described in entities.php (no mass assignment)', json_encode($row));

        $expect = static function (array $body, string $field, string $label) use ($t, $editor, $api, &$csrf): void {
            $r = $api($editor, 'save', $body, $csrf);
            $t->ok($r['status'] === 422 && ($r['json']['field'] ?? null) === $field, $label, "HTTP {$r['status']} " . $r['body']);
        };
        $expect(['entity' => 'productions', 'id' => null, 'values' => ['title_sk' => '  ']], 'title_sk', 'required field');
        $expect(['entity' => 'photos', 'id' => null, 'values' => ['image' => '../../includes/config.local.php']], 'image', 'file field rejects a path');
        $expect(['entity' => 'history', 'id' => null, 'values' => ['year' => '2020']], 'title_sk', 'history needs a production or a title');
        $expect(['entity' => 'history', 'id' => null, 'values' => ['year' => '1800', 'title_sk' => 'SMOKE x']], 'year', 'year out of range');
        $expect(['entity' => 'performances', 'id' => null, 'values' => ['run_id' => $rPublic, 'starts_at' => '2026-02-31T10:00']], 'starts_at', 'impossible date');
        $expect(['entity' => 'performances', 'id' => null, 'values' => ['run_id' => 99999999, 'starts_at' => '2030-01-01T10:00']], 'run_id', 'select pointing at a missing row');
        $expect(['entity' => 'ensemble_groups', 'id' => null, 'values' => ['name_sk' => 'SMOKE skupina', 'people' => [['name' => '', 'since' => '2006']]]], 'people', 'person without a name');
        $expect(['entity' => 'videos', 'id' => null, 'values' => ['title_sk' => 'SMOKE video']], 'url', 'video needs a link or a file');
        $expect(['entity' => 'productions', 'id' => null, 'values' => ['title_sk' => 'SMOKE trailer', 'trailer_url' => 'https://vimeo.com/123456']], 'trailer_url', 'a trailer link that is not YouTube is refused (the button would never show)');
        $ok = $api($editor, 'save', ['entity' => 'productions', 'id' => null, 'values' => ['title_sk' => 'SMOKE trailer', 'trailer_url' => 'youtu.be/aqz-KE-bpKQ']], $csrf);
        $t->ok($ok['status'] === 200 && db_value('SELECT trailer_url FROM productions WHERE id = ?', [(int) ($ok['json']['id'] ?? 0)]) === 'https://youtu.be/aqz-KE-bpKQ', 'a YouTube trailer link is accepted (and gets https://)', $ok['body']);

        $before = db_value("SELECT value FROM settings WHERE key = 'about_text_sk'");
        $dirty = '<p onclick="x()">Ahoj <b>svet</b> <img src=x onerror=alert(1)><a href="javascript:alert(1)">zlý</a> <a href="https://example.com" onclick="x">dobrý</a></p><script>alert(2)</script>';
        $api($editor, 'settings_save', ['group' => 'about', 'values' => ['about_title_sk' => '', 'about_text_sk' => $dirty]], $csrf);
        $t->same('<p>Ahoj <strong>svet</strong> zlý <a href="https://example.com" target="_blank" rel="noopener">dobrý</a></p>',
            db_value("SELECT value FROM settings WHERE key = 'about_text_sk'"), 'rich text is sanitized on save');
        save_setting('about_text_sk', $before);

        $xss = $api($editor, 'save', ['entity' => 'history', 'id' => null, 'values' => ['year' => '1989', 'title_sk' => 'SMOKE <script>alert(1)</script>']], $csrf);
        $home = $editor->get('/');
        $t->ok($xss['status'] === 200 && str_contains($home['body'], 'SMOKE &lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($home['body'], 'SMOKE <script>'), 'stored text is escaped on output');
    });

    // ── upload: chunked protocol, conversion, hostile files ──────────────────

    $uploaded = '';
    $t->section('upload', static function () use ($t, $editor, $visitor, $api, $loginAs, $base, &$csrf, $root, &$uploaded): void {
        $png = tempnam(sys_get_temp_dir(), 'dgs');
        $image = imagecreatetruecolor(1200, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 125, 26, 40));
        for ($i = 0; $i < 3000; $i++) {
            imagesetpixel($image, random_int(0, 1199), random_int(0, 799), random_int(0, 0xFFFFFF));
        }
        imagepng($image, $png);
        $bytes = (string) file_get_contents($png);
        $size = strlen($bytes);
        $half = intdiv($size, 2);

        $send = static function (string $id, int $offset, int $declared, string $name, string $data) use ($editor, &$csrf): array {
            $part = tempnam(sys_get_temp_dir(), 'dgc');
            file_put_contents($part, $data);
            $r = $editor->request('POST', '/api.php?action=upload', [
                'headers'   => ['X-CSRF-Token: ' . $csrf],
                'multipart' => ['upload_id' => $id, 'offset' => (string) $offset, 'size' => (string) $declared, 'name' => $name, 'chunk' => new CURLFile($part, 'application/octet-stream', 'chunk')],
            ]);
            @unlink($part);
            $r['json'] = json_decode($r['body'], true);

            return $r;
        };

        $id = bin2hex(random_bytes(16));
        $first = $send($id, 0, $size, 'SMOKE Plagát – Šípková Ruženka.PNG', substr($bytes, 0, $half));
        $t->ok(($first['json']['done'] ?? null) === false && ($first['json']['received'] ?? 0) === $half, 'first chunk is acknowledged with the byte count', $first['body']);
        $wrong = $send($id, 5, $size, 'x.png', 'junk');
        $t->ok(($wrong['json']['received'] ?? 0) === $half, 'a chunk at the wrong offset is ignored and the server says where it is');
        $last = $send($id, $half, $size, 'SMOKE Plagát – Šípková Ruženka.PNG', substr($bytes, $half));
        $uploaded = (string) ($last['json']['file']['name'] ?? '');
        $t->ok(($last['json']['done'] ?? null) === true && $uploaded !== '', 'last chunk finishes the upload', $last['body']);
        $t->ok(str_starts_with($uploaded, 'smoke-plagat-sipkova-ruzenka.'), 'the stored name is a slug without diacritics', $uploaded);
        $t->ok(is_file($root . '/assets/' . $uploaded) && filesize($root . '/assets/' . $uploaded) > 0, 'the web version exists in assets/');
        if (media_can_avif()) {
            $t->ok(str_ends_with($uploaded, '.avif') && ($last['json']['file']['converted'] ?? null) === true, 'images are converted to AVIF on this server');
        }
        $t->ok(is_file($root . '/assets_original/smoke-plagat-sipkova-ruzenka.png'), 'the original is kept in assets_original/');
        $t->status(403, $editor->get('/assets_original/smoke-plagat-sipkova-ruzenka.png'), 'originals are not served directly');
        $t->status(200, $editor->get('/admin.php?download=smoke-plagat-sipkova-ruzenka.png'), '… but a logged-in user can download them');
        $t->same(0, count(glob($root . '/storage/uploads/*.part') ?: []), 'no .part file is left behind');

        $php = '<?php system($_GET[0]); ?>';
        $t->status(422, $send(bin2hex(random_bytes(16)), 0, strlen($php), 'evil.jpg', $php), 'PHP code named .jpg is rejected by content');
        $t->status(422, $send(bin2hex(random_bytes(16)), 0, strlen($php), 'shell.php', $php), 'a .php upload is rejected by extension');
        $t->status(422, $send(bin2hex(random_bytes(16)), 0, strlen($php), 'shell.php.png', $php), 'a double extension does not help');
        $t->status(422, $send('../../x', 0, 10, 'a.png', 'x'), 'upload id must be 32 hex characters');
        $t->status(422, $send(bin2hex(random_bytes(16)), 0, 5, 'a.png', $bytes), 'more bytes than declared');
        $t->status(422, $send(bin2hex(random_bytes(16)), 0, PHP_INT_MAX, 'a.png', 'x'), 'declared size over upload.max_size');
        $t->same([], glob($root . '/assets/*.{php,phtml,phar,html,svg,js}', GLOB_BRACE) ?: [], 'nothing executable landed in assets/');
        $t->status(404, $editor->get('/admin.php?download=' . rawurlencode('../includes/config.local.php')), 'download cannot leave assets_original/');
        @unlink($png);

        // ── ffmpeg (only where this machine has one) ──
        $ffmpeg = media_ffmpeg_binary();
        if ($ffmpeg === null) {
            echo '  note  no ffmpeg on this machine - video conversion is not covered by this run', PHP_EOL;
            return;
        }
        // Shared hosting restricts open_basedir: PHP may open nothing outside the site, not even /dev/null
        // or the system temp dir. Starting a program must not depend on either (it did, and on the
        // hosting every ffmpeg "could not be found"). A child process repeats the lookup under that limit.
        $probe = $root . '/storage/uploads/smoke-open-basedir.php';
        file_put_contents($probe, "<?php\nrequire 'includes/bootstrap.php';\nrequire 'includes/media.php';\necho media_ffmpeg_binary() !== null ? 'found' : 'missing';\n");
        $child = proc_open([PHP_BINARY, '-d', 'open_basedir=' . $root, '-d', 'display_errors=0', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $answer = is_resource($child) ? trim((string) stream_get_contents($pipes[1])) : 'could not start php';
        is_resource($child) && proc_close($child);
        @unlink($probe);
        $t->same('found', $answer, 'ffmpeg is still found when open_basedir confines PHP to the site folder (shared hosting)');

        $clip = $root . '/storage/uploads/smoke-clip.mkv';
        media_ffmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=10', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $clip]);
        if (!$t->ok(is_file($clip) && filesize($clip) > 0, 'a test clip can be generated with ffmpeg')) {
            return;
        }
        $bytes = (string) file_get_contents($clip);

        // Videos go to the web as WebM with AV1 + Opus. Which AV1 encoder does the work depends on the
        // ffmpeg build (SVT-AV1 where there is one, libaom on the hosting), so every encoder this machine
        // has is tried on its own - the hosting's command line stays covered on a developer's newer ffmpeg.
        $encoders = media_av1_encoders();
        if (!$encoders) {
            @unlink($clip);
            echo '  note  this ffmpeg has no AV1 encoder - video conversion is not covered by this run', PHP_EOL;
            return;
        }
        $streams = static function (string $file) use ($ffmpeg): string {
            preg_match_all('/Stream #\S+ (Video|Audio): (\w+)/', media_run([$ffmpeg, '-hide_banner', '-i', $file])['output'], $m);
            return implode(' + ', $m[2]);
        };
        foreach ($encoders as $encoder) {
            $out = $root . '/storage/uploads/smoke-' . $encoder . '.webm';
            $t->ok(media_video_to_webm($clip, $out, [$encoder]) && $streams($out) === 'av1 + opus', "$encoder turns a clip into WebM with AV1 + Opus", is_file($out) ? $streams($out) : 'no output file');
            @unlink($out);
        }
        // Sources that used to break such conversions: an odd frame width (yuv420p needs even sizes),
        // 5.1 sound from a camera (libopus refuses the "side" layout), and a clip with no sound at all.
        $odd = $root . '/storage/uploads/smoke-odd.mkv';
        $out = $root . '/storage/uploads/smoke-odd.webm';
        $picture = ['-f', 'lavfi', '-i', 'testsrc=duration=1:size=321x240:rate=10'];
        $encode = ['-c:v', 'libx264', '-pix_fmt', 'yuv444p'];
        foreach ([
            'an odd frame width and 5.1 sound still convert' => [array_merge($picture, ['-f', 'lavfi', '-i', 'anullsrc=channel_layout=5.1(side):sample_rate=44100', '-shortest'], $encode, ['-c:a', 'ac3', $odd]), 'av1 + opus'],
            'a clip without sound converts too'              => [array_merge($picture, $encode, [$odd]), 'av1'],
        ] as $label => [$make, $expected]) {
            media_ffmpeg($make);
            $t->ok(is_file($odd) && media_video_to_webm($odd, $out) && $streams($out) === $expected, $label, is_file($out) ? $streams($out) : 'no output file');
            @unlink($odd);
            @unlink($out);
        }
        @unlink($clip);

        // A phone writes where and when a clip was shot, and on what, into the file; a voice memo is often
        // titled with the address it was recorded at. The public version must carry none of it (images
        // lose theirs in stripImage()). The coordinates are searched for in the raw bytes as well.
        $tags = ['-metadata', 'location=+48.7558+017.8305/', '-metadata', 'title=Doma na Hviezdoslavovej 7', '-metadata', 'make=Apple', '-metadata', 'creation_time=2026-09-01T18:00:00Z'];
        $leaks = static function (string $file) use ($ffmpeg): string {
            $seen = preg_match_all('/^\s*(location[\w-]*|title|make|creation_time)\s*:/mi', media_run([$ffmpeg, '-hide_banner', '-i', $file])['output'], $m) ? implode(', ', array_unique(array_map('strtolower', $m[1]))) : '';
            return $seen . (str_contains((string) file_get_contents($file), '48.7558') ? ' + raw coordinates' : '');
        };
        $phone = $root . '/storage/uploads/smoke-phone.mkv';
        media_ffmpeg(array_merge(['-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=10', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac'], $tags, [$phone]));
        if ($t->ok(is_file($phone) && $leaks($phone) !== '', 'a test clip with phone metadata can be made', is_file($phone) ? 'tags seen: ' . $leaks($phone) : 'no file')) {
            $out = $root . '/storage/uploads/smoke-phone.webm';
            $t->ok(media_video_to_webm($phone, $out) && $leaks($out) === '', 'position, title, device and recording time do not reach the public video', is_file($out) ? 'left in the output: ' . $leaks($out) : 'no output file');
            @unlink($out);
        }
        @unlink($phone);
        $memo = $root . '/storage/uploads/smoke-memo.flac';
        media_ffmpeg(array_merge(['-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'flac'], $tags, [$memo]));
        if ($t->ok(is_file($memo) && $leaks($memo) !== '', 'a test recording with a title tag can be made', is_file($memo) ? 'tags seen: ' . $leaks($memo) : 'no file')) {
            $flac = (string) file_get_contents($memo);
            $audio = $send(bin2hex(random_bytes(16)), 0, strlen($flac), 'SMOKE memo.flac', $flac);
            $opus = (string) ($audio['json']['file']['name'] ?? '');
            $t->ok($opus === 'smoke-memo.opus' && $leaks($root . '/assets/' . $opus) === '', 'an uploaded recording becomes Opus without the tags of the original', $opus === '' ? $audio['body'] : 'left in the output: ' . $leaks($root . '/assets/' . $opus));
        }
        @unlink($memo);

        // ── long videos: a resumable job, one short step per request ──
        // A 50-minute recording takes the hosting over an hour and no request may run longer than ~100 s,
        // so a video is converted in segments (includes/video.php). The test copy is configured with zero
        // time budgets: every upload answers with a job and every convert_step call does ONE step - a clip
        // of a few seconds walks the path of a whole play.
        $frames = static function (string $file) use ($ffmpeg): int {
            return preg_match_all('/frame=\s*(\d+)/', media_run([$ffmpeg, '-hide_banner', '-nostdin', '-i', $file, '-map', '0:v:0', '-c', 'copy', '-f', 'null', '-'])['output'], $m) ? (int) end($m[1]) : -1;
        };
        /** Drives a job through the API until it is done; returns [final response, number of calls, progress values]. */
        $drive = static function (SmokeHttp $http, string $job, string $token, int $limit = 200) use ($api): array {
            $seen = [];
            for ($calls = 1; $calls <= $limit; $calls++) {
                $r = $api($http, 'convert_step', ['job' => $job], $token);
                $seen[] = (float) ($r['json']['job']['progress'] ?? -1);
                if ($r['status'] !== 200 || ($r['json']['job']['status'] ?? '') !== 'running') {
                    break;
                }
            }

            return [$r, $calls, $seen];
        };

        if ((float) config('upload.video_inline_seconds') > 0) {
            $t->ok(false, 'the test copy is configured for step-by-step conversion', 'its config.local.php is older than this suite - run: php .claude/skills/dg-dev/scripts/testsite.php create');
            return;
        }

        // 7 s at 29.97 fps: the awkward rate - segments must be multiples of 30 frames to last whole milliseconds
        $long = $root . '/storage/uploads/smoke-long.mp4';
        media_ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=duration=7:size=320x240:rate=30000/1001', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=7', '-shortest', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '12', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $long]);
        $sourceFrames = $frames($long);
        $bytesLong = (string) file_get_contents($long);

        $video = $send(bin2hex(random_bytes(16)), 0, strlen($bytesLong), 'SMOKE klip.mp4', $bytesLong);
        $jobId = (string) ($video['json']['job']['id'] ?? '');
        $t->ok(($video['json']['done'] ?? null) === true && preg_match('/^[a-f0-9]{32}$/', $jobId) === 1 && !isset($video['json']['file']), 'a video that is not finished during the upload request answers with a job', $video['body']);
        $t->same('smoke-klip.webm', (string) ($video['json']['job']['name'] ?? ''), 'the job already knows the name the video will get');
        $t->ok(!str_contains($video['body'], 'storage') && !str_contains($video['body'], 'assets_original'), 'the job state sent to the browser holds no server paths', $video['body']);
        $t->ok(!is_file($root . '/assets/smoke-klip.webm'), 'nothing appears in assets/ while the conversion is running');
        $t->ok(!in_array('smoke-klip.mp4', array_column(media_unconverted(), 'name'), true), 'an original that a job is working on is not offered as "unconverted"');
        $files = $editor->get('/admin.php?tab=files');
        $t->ok(str_contains($files['body'], 'data-cms-job="' . $jobId . '"'), 'admin → Súbory lists the unfinished conversion (that page drives it on)');
        $twin = video_job_create($root . '/assets_original/smoke-klip.mp4', $root . '/assets/smoke-klip.webm');
        $t->same($jobId, (string) ($twin['id'] ?? ''), 'asking to convert the same original again joins the running job instead of starting a second one');

        $t->status(401, $api($visitor, 'convert_step', ['job' => $jobId], $csrf), 'convert_step needs a login');
        $t->status(419, $api($editor, 'convert_step', ['job' => $jobId], 'wrong-token'), 'convert_step needs the CSRF token');
        $t->status(422, $api($editor, 'convert_step', ['job' => '../../etc'], $csrf), 'a job id must be 32 hex characters');
        $t->status(404, $api($editor, 'convert_step', ['job' => str_repeat('a', 32)], $csrf), 'an unknown job answers 404');

        [$last, $calls, $seen] = $drive($editor, $jobId, $csrf);
        $sorted = $seen;
        sort($sorted);
        $t->ok(($last['json']['job']['status'] ?? '') === 'done' && ($last['json']['file']['name'] ?? '') === 'smoke-klip.webm', 'convert_step calls bring the job to the end and hand over the file', $last['body']);
        $t->ok($calls >= 4 && $seen === $sorted && end($seen) === 1.0, 'it took several short steps and the progress only ever grew', "calls: $calls, progress: " . implode(' ', $seen));
        $t->same('av1 + opus', $streams($root . '/assets/smoke-klip.webm'), 'the uploaded video holds AV1 video and Opus sound');
        $t->same($sourceFrames, $frames($root . '/assets/smoke-klip.webm'), 'joined from segments it has exactly the frames of the source - none doubled or lost at a join');
        // a frame that slipped at a join would show as a PSNR collapse (testsrc2 moves every frame): aligned ≈ 35 dB, slipped ≈ 19 dB
        $psnr = media_run([$ffmpeg, '-hide_banner', '-nostdin', '-i', $root . '/assets/smoke-klip.webm', '-i', $long, '-lavfi', '[0:v]settb=AVTB,setpts=N[a];[1:v]settb=AVTB,setpts=N[b];[a][b]psnr', '-an', '-f', 'null', '-'], 20000)['output'];
        $worst = preg_match('/PSNR.*min:([\d.]+)/', $psnr, $m) ? (float) $m[1] : 0.0;
        $t->ok($worst > 27, 'every frame of the joined video matches the same frame of the source', "worst frame: $worst dB");
        $t->ok(is_file($root . '/assets/smoke-klip.avif'), 'the conversion also writes a poster image next to the video');
        $t->ok(is_file($root . '/assets_original/smoke-klip.mp4'), 'the original is kept');
        $t->same([], glob($root . '/storage/uploads/job-' . $jobId . '/seg-*') ?: [], 'the segments are removed once the video is joined');
        $t->same([], glob($root . '/storage/uploads/dgf*') ?: [], 'no work file of the conversion is left in storage/uploads');

        // a video that already is AV1 + Opus WebM is not encoded again - a play converted on the owner's
        // own computer goes through in seconds (and still loses its metadata)
        $ready = (string) file_get_contents($root . '/assets/smoke-klip.webm');
        $again = $send(bin2hex(random_bytes(16)), 0, strlen($ready), 'SMOKE hotove.webm', $ready);
        $t->ok(($again['json']['file']['name'] ?? '') === 'smoke-hotove.webm' && !isset($again['json']['job']), 'an upload that already is AV1 + Opus in WebM is finished at once - one step, no job to drive', $again['body']);
        // the same packets, byte for byte = it was re-wrapped, not encoded again
        $packets = static fn (string $file): string => preg_match('/MD5=([a-f0-9]{32})/', media_run([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-i', $file, '-map', '0:v:0', '-c', 'copy', '-f', 'md5', '-'])['output'], $m) ? $m[1] : 'no md5 for ' . basename($file);
        $t->same($packets($root . '/assets/smoke-klip.webm'), $packets($root . '/assets/smoke-hotove.webm'), 'its video stream is passed through untouched, not re-encoded');

        // a step the server killed half-way leaves a partial file; the next step clears it and carries on
        $killed = $send(bin2hex(random_bytes(16)), 0, strlen($bytesLong), 'SMOKE zmazat.mp4', $bytesLong);
        $killedId = (string) ($killed['json']['job']['id'] ?? '');
        $api($editor, 'convert_step', ['job' => $killedId], $csrf);
        $partial = $root . '/storage/uploads/job-' . $killedId . '/seg-00009.dead00.part.webm';
        file_put_contents($partial, 'half a segment');
        touch($partial, time() - 3600);
        [$last] = $drive($editor, $killedId, $csrf);
        $t->ok(($last['json']['job']['status'] ?? '') === 'done' && !is_file($partial) && $frames($root . '/assets/smoke-zmazat.webm') === $sourceFrames, 'a job continues after an interrupted step and the stale partial file is cleared', $last['body']);

        // cancelling: the owner or an administrator, nobody else; the original survives
        db_exec("INSERT INTO users (username, password_hash, role) VALUES ('smoke_editor_video', ?, 'editor')", [password_hash('smoke-editor-heslo', PASSWORD_DEFAULT)]);
        $other = new SmokeHttp($base);
        $loginAs($other, 'smoke_editor_video', 'smoke-editor-heslo');
        preg_match('/"csrf":"([a-f0-9]{64})"/', $other->get('/')['body'], $m);
        $otherCsrf = $m[1] ?? '';
        $mine = $send(bin2hex(random_bytes(16)), 0, strlen($bytesLong), 'SMOKE zrusit.mp4', $bytesLong);
        $mineId = (string) ($mine['json']['job']['id'] ?? '');
        $t->status(403, $api($other, 'convert_cancel', ['job' => $mineId], $otherCsrf), 'another editor may not cancel somebody else\'s conversion');
        $t->status(200, $api($other, 'convert_step', ['job' => $mineId], $otherCsrf), '… but may help it along (an administrator finishes what an editor uploaded)');
        $t->status(200, $api($editor, 'convert_cancel', ['job' => $mineId], $csrf), 'the owner cancels it');
        $t->ok(!is_dir($root . '/storage/uploads/job-' . $mineId) && is_file($root . '/assets_original/smoke-zrusit.mp4') && !is_file($root . '/assets/smoke-zrusit.webm'), 'cancelling removes the work files and keeps the original');
        $t->ok(in_array('smoke-zrusit.mp4', array_column(media_unconverted(), 'name'), true), 'the cancelled original is offered for conversion again');
        db_exec("DELETE FROM users WHERE username = 'smoke_editor_video'");

        // cancelling WHILE a step is running (ffmpeg is writing a segment): the files cannot be pulled from
        // under it, and the step must not save the job back afterwards - a cancelled conversion stays cancelled
        $busy = $send(bin2hex(random_bytes(16)), 0, strlen($bytesLong), 'SMOKE bezi.mp4', $bytesLong);
        $busyId = (string) ($busy['json']['job']['id'] ?? '');
        $busyDir = $root . '/storage/uploads/job-' . $busyId;
        $held = fopen($busyDir . '/lock', 'c');
        flock($held, LOCK_EX); // this process now plays the running step
        $t->status(200, $api($editor, 'convert_cancel', ['job' => $busyId], $csrf), 'a conversion can be cancelled while a step is running');
        $t->ok(is_dir($busyDir) && is_file($busyDir . '/cancel'), 'its files are left to the running step, marked as cancelled');
        $t->status(404, $api($editor, 'convert_step', ['job' => $busyId], $csrf), 'from that moment the job does not exist for anybody');
        $t->ok(!in_array($busyId, array_column(video_jobs(), 'id'), true) && !str_contains($editor->get('/admin.php?tab=files')['body'], $busyId), '… and admin → Súbory no longer lists it');
        flock($held, LOCK_UN);
        fclose($held);
        $t->ok(video_job_run($busyId, 0.0) === null && !is_dir($busyDir) && !is_file($root . '/assets/smoke-bezi.webm'), 'once the step is over the work files go and nothing was published');

        // ── when things go wrong in the middle ──
        // The end of a video is decided by ffmpeg finishing cleanly with fewer frames than asked for - never
        // by the duration in the header, which is often wrong and sometimes absent. With a header-based rule
        // a source WITHOUT a duration turned any ffmpeg failure into "end of video": a truncated video was
        // published and, if asked, the original deleted.
        $engine = static function (string $source, string $target, array $opts = []): array {
            $job = video_job_create($source, $target, $opts);
            return $job ?? [];
        };
        $steps = static function (array $job, int $count): ?array {
            for ($i = 0; $i < $count && $job !== null && $job['status'] === 'running'; $i++) {
                $job = video_job_run($job['id'], 0.0, 0.05);
            }
            return $job;
        };
        $breakSource = static function (string $file): void { // what a disk error or a killed ffmpeg looks like to the job
            rename($file, $file . '.real');
            file_put_contents($file, str_repeat('not a video ', 2000));
        };
        $healSource = static function (string $file): void {
            unlink($file);
            rename($file . '.real', $file);
        };

        $live = $root . '/storage/uploads/smoke-live.mkv'; // written like a live stream: no duration, no index
        media_ffmpeg(['-i', $long, '-c', 'copy', '-f', 'matroska', '-live', '1', $live]);
        $probe = video_probe($live);
        // what ONE pass through the same fps filter gives: this container leaves the last frame's length open, so the
        // filter pads a frame at the very end (210 for 209 packets) - the segmented result has to equal that, not the packets
        $liveFrames = preg_match_all('/frame=\s*(\d+)/', media_run([$ffmpeg, '-hide_banner', '-nostdin', '-i', $live, '-map', '0:v:0', '-vf', 'fps=30000/1001:start_time=0:round=near', '-f', 'null', '-'])['output'], $m) ? (int) end($m[1]) : -1;
        $t->ok($probe !== null && $probe['duration'] <= 0, 'a test source without a duration in its header can be made', json_encode($probe));
        $out = $root . '/storage/uploads/smoke-live.webm';
        $job = $steps($engine($live, $out, ['delete_original' => true]), 3); // sound + two segments
        $t->ok($job !== null && count($job['segments']) === 2, 'the job got two segments into it', json_encode($job['segments'] ?? null));
        $breakSource($live);
        $job = $steps($job, 3);
        $t->ok($job !== null && $job['status'] === 'failed' && !is_file($out), 'ffmpeg failing in the middle of such a source is a FAILURE - nothing is published as if it were the whole video', 'status: ' . ($job['status'] ?? 'null') . ', published: ' . var_export(is_file($out), true));
        $t->ok(is_file($live . '.real'), '… and the original the editor asked to delete afterwards is still there');
        $t->ok(str_contains(video_job_public($job)['error'], '00:00:0'), 'the editor is told where it failed, in a sentence', video_job_public($job)['error']);
        $healSource($live);
        $job = $steps(video_job_retry($job['id']), 50);
        $t->ok($job !== null && $job['status'] === 'done' && $frames($out) === $liveFrames && count($job['segments']) >= 3, '„Skúsiť znova“ carries on from the finished segments and the result is complete', 'status: ' . ($job['status'] ?? 'null') . ', frames: ' . $frames($out) . ' of ' . $liveFrames . ', segments: ' . json_encode($job['segments'] ?? null));
        $t->ok(!is_file($live), 'now that the video is complete the original is deleted as asked');
        video_job_remove($job['id']);
        @unlink($out);

        // a failure in the very first segment must leave the job retryable (it used to empty the encoder list for good)
        $first = $root . '/storage/uploads/smoke-first.mp4';
        copy($long, $first);
        $out = $root . '/storage/uploads/smoke-first.webm';
        $job = $steps($engine($first, $out), 1); // sound only
        $breakSource($first);
        $job = $steps($job, 1);
        $t->ok($job !== null && $job['status'] === 'failed' && $job['encoders'] !== [], 'a failure in the first segment fails the job but keeps its encoders', json_encode($job['encoders'] ?? null));
        $healSource($first);
        $job = $steps(video_job_retry($job['id']), 50);
        $t->ok($job !== null && $job['status'] === 'done' && $frames($out) === $sourceFrames, '… so a retry can finish it', 'status: ' . ($job['status'] ?? 'null'));
        video_job_remove($job['id']);
        @unlink($first);
        @unlink($out);

        // the last segment is exactly full: the next one comes back empty, and that clean "nothing" is the end
        $even = $root . '/storage/uploads/smoke-even.mp4';
        media_ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=duration=8.008:size=320x240:rate=30000/1001', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', $even]);
        $out = $root . '/storage/uploads/smoke-even.webm';
        $job = $steps($engine($even, $out), 50);
        $t->ok($frames($even) === 240 && $job !== null && $job['status'] === 'done' && $frames($out) === 240 && array_sum(array_column($job['segments'], 'frames')) === 240, 'a video that ends exactly on a segment boundary is complete (240 frames in 60-frame segments)', 'source ' . $frames($even) . ', output ' . $frames($out) . ', status ' . ($job['status'] ?? 'null'));
        video_job_remove($job['id']);
        @unlink($even);
        @unlink($out);

        // a step the server keeps killing must not be retried for ever with the percentage standing still
        $stuck = $root . '/storage/uploads/smoke-stuck.mp4';
        copy($long, $stuck);
        $job = $engine($stuck, $root . '/storage/uploads/smoke-stuck.webm');
        $job['attempts'] = VIDEO_STEP_ATTEMPTS; // = that many steps were started and never came back
        video_job_save($job);
        $job = $steps($job, 1);
        $t->ok($job !== null && $job['status'] === 'failed' && $job['error_key'] === 'job_err_killed', 'a step that never finishes fails the job after ' . VIDEO_STEP_ATTEMPTS . ' attempts instead of looping silently', json_encode([$job['status'] ?? null, $job['error_key'] ?? null]));

        // cancelling during the LAST step: the join must not publish the video and delete the original after all
        $job = video_job_retry($job['id']);
        $job['delete_original'] = true;
        $fake = video_job_dir($job['id']) . '/out.abc123.part.webm';
        copy($root . '/assets/smoke-klip.webm', $fake);
        touch(video_job_dir($job['id']) . '/cancel');
        $after = video_job_finish($job, $fake);
        $t->ok($after['status'] !== 'done' && !is_file($root . '/storage/uploads/smoke-stuck.webm') && is_file($stuck), 'a conversion cancelled during its final step publishes nothing and keeps the original');
        video_job_remove($job['id']);
        @unlink($stuck);

        // a second driver while a step is running is told to wait
        $two = $send(bin2hex(random_bytes(16)), 0, strlen($bytesLong), 'SMOKE dvaja.mp4', $bytesLong);
        $twoId = (string) ($two['json']['job']['id'] ?? '');
        $held = fopen($root . '/storage/uploads/job-' . $twoId . '/lock', 'c');
        flock($held, LOCK_EX);
        $waiting = $api($editor, 'convert_step', ['job' => $twoId], $csrf);
        $t->ok(($waiting['json']['job']['busy'] ?? null) === true && ($waiting['json']['job']['status'] ?? '') === 'running', 'a second driver gets "busy" while somebody else works on the job', $waiting['body']);
        flock($held, LOCK_UN);
        fclose($held);
        $guest = new SmokeHttp($base);
        db_exec("INSERT INTO users (username, password_hash, role) VALUES ('smoke_editor_video', ?, 'editor')", [password_hash('smoke-editor-heslo', PASSWORD_DEFAULT)]);
        $loginAs($guest, 'smoke_editor_video', 'smoke-editor-heslo');
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $guest->get('/admin.php?tab=files')['body'], $m);
        $t->status(403, $guest->post('/admin.php?tab=files', ['csrf' => $m[1] ?? '', 'do' => 'job_cancel', 'job' => $twoId]), 'admin → Súbory: another editor may not cancel it there either');
        $t->ok(is_dir($root . '/storage/uploads/job-' . $twoId), '… and the job is still there');
        db_exec("DELETE FROM users WHERE username = 'smoke_editor_video'");
        video_job_remove($twoId);

        // the hosting's scheduler can drive jobs with no browser open
        $t->status(403, $visitor->get('/cron.php'), 'cron.php without the key is refused');
        $t->status(403, $visitor->get('/cron.php?key=wrong'), 'cron.php with a wrong key is refused');
        $cronJob = $send(bin2hex(random_bytes(16)), 0, strlen($bytesLong), 'SMOKE cron.mp4', $bytesLong);
        for ($i = 0, $out = ''; $i < 60 && !is_file($root . '/assets/smoke-cron.webm'); $i++) {
            $out = $visitor->get('/cron.php?key=' . rawurlencode((string) config('cron_key')))['body'];
        }
        $t->ok(is_file($root . '/assets/smoke-cron.webm') && $frames($root . '/assets/smoke-cron.webm') === $sourceFrames, 'calls of cron.php alone finish a conversion', "after $i calls: $out");
        $idle = $visitor->get('/cron.php?key=' . rawurlencode((string) config('cron_key')))['body'];
        $t->ok(str_contains($idle, 'nothing to do'), 'with no job waiting cron.php says so and does nothing', $idle);
        @unlink($long);
    });

    // ── order: new_first, arrows, archive → restore → purge ──────────────────

    $t->section('order', static function () use ($t, $editor, $api, $admin, &$csrf, &$uploaded, $root): void {
        if ($uploaded === '') { // "upload" was filtered out — any image will do
            $uploaded = (string) (array_values(array_filter(scandir($root . '/assets') ?: [], static fn (string $f): bool => media_type($f) === 'image'))[0] ?? '');
        }
        if (!$t->ok($uploaded !== '', 'an image is available for the photo tests')) {
            return;
        }
        $ids = [];
        foreach (['SMOKE A', 'SMOKE B', 'SMOKE C'] as $caption) {
            $ids[] = (int) ($api($editor, 'save', ['entity' => 'photos', 'id' => null, 'values' => ['image' => $uploaded, 'caption_sk' => $caption]], $csrf)['json']['id'] ?? 0);
        }
        [$a, $b, $c] = $ids;
        $order = static fn (): array => array_map('intval', array_column(db_all("SELECT id FROM photos WHERE deleted_at IS NULL AND caption_sk LIKE 'SMOKE%' ORDER BY sort, id"), 'id'));
        $t->same([$c, $b, $a], $order(), 'new photos go first (new_first)');
        $api($editor, 'move', ['entity' => 'photos', 'id' => $a, 'dir' => 'up'], $csrf);
        $t->same([$c, $a, $b], $order(), 'arrow moves one step');
        $api($editor, 'delete', ['entity' => 'photos', 'id' => $a], $csrf);
        $t->ok(db_value('SELECT deleted_at FROM photos WHERE id = ?', [$a]) !== null, 'the bin archives (soft delete), it does not delete');
        $t->ok(str_contains($editor->get('/admin.php?tab=archive')['body'], 'SMOKE A'), 'the archived photo is listed in admin → Archív');
        $t->status(422, $api($editor, 'move', ['entity' => 'photos', 'id' => $a, 'dir' => 'up'], $csrf), 'an archived row cannot be moved');

        $admin($editor, 'archive', ['do' => 'arch_purge', 'entity' => 'photos', 'id' => $b], $csrf);
        $t->same(1, (int) db_value('SELECT count(*) FROM photos WHERE id = ?', [$b]), 'purge refuses a row that is not in the archive');
        $admin($editor, 'archive', ['do' => 'arch_restore', 'entity' => 'photos', 'id' => $a], $csrf);
        $t->ok(db_value('SELECT deleted_at FROM photos WHERE id = ?', [$a]) === null, 'restore brings the photo back');
        $api($editor, 'delete', ['entity' => 'photos', 'id' => $a], $csrf);
        $admin($editor, 'archive', ['do' => 'arch_purge', 'entity' => 'photos', 'id' => $a], $csrf);
        $t->same(0, (int) db_value('SELECT count(*) FROM photos WHERE id = ?', [$a]), 'purge deletes an archived row for good');

        $files = $editor->get('/admin.php?tab=files');
        $t->ok(str_contains($files['body'], e($uploaded)) && str_contains($files['body'], e(t('ent_photos'))), 'admin → Súbory shows where the file is used');
    });

    // ── visibility: what must never reach a visitor ──────────────────────────

    $t->section('visibility', static function () use ($t, $visitor, $editor, $setMode): void {
        $setMode('live');
        $page = $visitor->get('/');
        $t->status(200, $page, 'live mode answers 200');
        $t->clean($page, 'live page (visitor)');
        $t->ok(!$page['cookies'], 'still no cookie for the visitor');
        $html = $page['body'];
        $t->ok(str_contains($html, 'SMOKE-PUBLIC') && str_contains($html, 'SMOKE-VENUE'), 'public production and its public run are shown');
        $t->ok(str_contains($html, 'SMOKE-EVENT'), 'a manual history event is shown');
        $t->ok(!str_contains($html, 'SMOKE-HIDDEN'), 'a hidden production appears nowhere — not in the repertoire and not in History',
            'found near: ' . mb_substr(strip_tags(substr($html, max(0, (int) strpos($html, 'SMOKE-HIDDEN') - 80), 200)), 0, 160));
        $t->ok(!str_contains($html, 'SMOKE-SECRET-RUN-VENUE'), 'a hidden „Práve hráme“ item is not shown');
        $part = static function (string $id) use ($html): string {
            $from = strpos($html, 'id="' . $id . '"');
            return $from === false ? '' : substr($html, $from, (int) strpos($html, '</section>', $from) - $from);
        };
        $t->ok(str_contains($part('domov'), 'SMOKE-RUNONLY'), 'a published „Práve hráme“ item shows even when its production is hidden in the repertoire');
        $t->ok(!str_contains($part('historia'), 'SMOKE-RUNONLY') && !str_contains($part('repertoar'), 'SMOKE-RUNONLY'), '… but that production stays out of História and Repertoár');
        foreach (['data-cms-action', 'id="cms-config"', 'class="adminbar"', 'cms.js', 'cms.css', 'cms-flag'] as $needle) {
            $t->ok(!str_contains($html, $needle), "no editing markup for visitors ($needle)");
        }
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $ld = json_decode($m[1] ?? '', true);
        $t->ok(is_array($ld) && in_array('TheaterEvent', array_column($ld, '@type'), true), 'JSON-LD parses and announces the upcoming performance');
        $t->ok(!str_contains($m[1] ?? '', 'SMOKE-HIDDEN'), 'JSON-LD does not mention hidden content');
        $old = $visitor->get('/stara-stranka/podstranka.html');
        $t->ok($old['status'] === 301 && $old['location'] === '/', 'old URLs redirect to the home page');
        $t->status(200, $visitor->get('/?preview=wip'), '?preview is ignored for visitors');

        $mine = $editor->get('/')['body'];
        $t->ok(str_contains($mine, 'SMOKE-HIDDEN') && str_contains($mine, 'SMOKE-SECRET-RUN-VENUE'), 'the editor sees hidden content');
        $history = substr($mine, (int) strpos($mine, 'id="historia"'));
        $t->ok(str_contains(substr($history, 0, (int) strpos($history, 'id="kontakt"') ?: null), 'cms-flag--hidden'), 'hidden entries in History carry the „Skryté“ flag for the editor');
    });

    // ── placeholder: „Práve hráme" on the temporary pages ────────────────────

    $t->section('placeholder', static function () use ($t, $visitor, $setMode): void {
        foreach (['wip' => 'wip_headline', 'maintenance' => 'mnt_headline'] as $mode => $headline) {
            $setMode($mode);
            $page = $visitor->get('/anything/at/all');
            $t->status(503, $page, "$mode mode answers 503 on every path");
            $t->clean($page, "$mode placeholder");
            $t->ok(str_contains($page['body'], e(t($headline))), "$mode headline is shown");
            $t->ok(str_contains($page['body'], 'SMOKE-PUBLIC') && str_contains($page['body'], 'static/css/site.css'), "$mode page shows „Práve hráme“ when something is playing");
            $t->ok(!str_contains($page['body'], 'SMOKE-SECRET-RUN-VENUE') && !str_contains($page['body'], 'data-cms-action'), "$mode page shows neither hidden items nor pencils");
        }
    });

    // ── contact form ─────────────────────────────────────────────────────────

    $t->section('contact', static function () use ($t, $visitor, $setMode, $root): void {
        $setMode('live');
        $token = static function (int $age): string {
            $time = (string) (time() - $age);
            return $time . '.' . sign('contact|' . $time);
        };
        $send = static function (array $form) use ($visitor): array {
            $r = $visitor->post('/api.php?action=contact', $form, ['X-Requested-With: fetch']);
            $r['json'] = json_decode($r['body'], true);
            return $r;
        };
        $count = static fn (): int => (int) db_value("SELECT count(*) FROM messages WHERE name LIKE 'SMOKE%'");
        $valid = ['name' => 'SMOKE Novak, Jan', 'email' => 'jan@example.com', 'subject' => "Ahoj\r\nBcc: x@example.com", 'message' => 'Dobrý deň, toto je skúška.'];

        $t->status(422, $send(['token' => '1700000000.deadbeef'] + $valid), 'a forged token is refused');
        $t->status(422, $send(['token' => $token(7 * 3600)] + $valid), 'a token older than 6 hours is refused');
        $t->ok($send(['token' => $token(10), 'website' => 'http://spam.example'] + $valid)['status'] === 200 && $count() === 0, 'honeypot: the robot is told “ok”, nothing is stored');
        $t->ok($send(['token' => $token(0)] + $valid)['status'] === 200 && $count() === 0, 'time trap: a form filled in under 3 seconds is dropped silently');
        $r = $send(['token' => $token(10), 'email' => 'not-an-email'] + $valid);
        $t->ok($r['status'] === 422 && ($r['json']['field'] ?? '') === 'email', 'invalid e-mail points at the field');

        $t->ok($send(['token' => $token(10)] + $valid)['status'] === 200 && $count() === 1, 'a valid message is stored');
        $row = db_one("SELECT * FROM messages WHERE name LIKE 'SMOKE%' ORDER BY id DESC LIMIT 1");
        $t->ok(!preg_match('/[\r\n]/', (string) $row['subject']), 'line breaks in the subject are flattened');
        $t->ok(strlen((string) $row['ip_hash']) === 64 && $row['ip_hash'] !== '127.0.0.1', 'only a hash of the IP address is stored');
        $mails = glob($root . '/storage/mail/*.eml') ?: [];
        sort($mails);
        $mail = $mails ? (string) file_get_contents(end($mails)) : '';
        $head = substr($mail, 0, (int) strpos($mail, "\n\n"));
        $t->ok(str_contains($head, 'Reply-To: "SMOKE Novak, Jan" <jan@example.com>'), 'Reply-To display name is quoted', $head);
        $t->ok(!preg_match('/^Bcc:/mi', $head), 'no header injection');

        for ($i = 0; $i < CONTACT_RATE_LIMIT - 1; $i++) {
            $send(['token' => $token(10)] + $valid);
        }
        $t->status(422, $send(['token' => $token(10)] + $valid), 'rate limit: message number ' . (CONTACT_RATE_LIMIT + 1) . ' within an hour is refused');
        $noJs = $visitor->post('/api.php?action=contact', ['token' => $token(10), 'name' => '', 'email' => 'a@example.com', 'message' => 'Dobrý deň.']);
        $t->ok($noJs['status'] === 303 && str_contains($noJs['location'], '/?cf=cf_err_'), 'without JavaScript the answer is a redirect back to the form', $noJs['location']);
    });

    // ── backup → change → restore ────────────────────────────────────────────

    $t->section('backup', static function () use ($t, $editor, $admin, &$csrf): void {
        $known = array_column(backup_list(), 'name');
        $admin($editor, 'backups', ['do' => 'backup_create', 'note' => 'smoke'], $csrf);
        $made = array_values(array_diff(array_column(backup_list(), 'name'), $known));
        if (!$t->ok(count($made) === 1, 'admin → Zálohy creates a backup file')) {
            return;
        }
        $info = backup_info(backup_path($made[0]));
        $t->ok($info['by'] === 'tester' && $info['note'] === 'smoke' && $info['migration'] !== null, 'the backup header says who, why and which database version');
        $t->same('same', backup_compat($info)['level'], 'a fresh backup matches the current structure');

        $counts = static fn (): array => array_map(static fn (string $table): int => (int) db_value("SELECT count(*) FROM \"$table\""), array_combine($x = ['productions', 'runs', 'performances', 'photos', 'history', 'settings', 'users'], $x));
        $before = $counts();
        $images = db_value("SELECT images::text FROM productions ORDER BY id LIMIT 1");
        db_exec('DELETE FROM performances');
        db_exec("INSERT INTO productions (title_sk) VALUES ('SMOKE-AFTER-BACKUP')");
        db_exec("INSERT INTO users (username, password_hash) VALUES ('smoke_editor', 'x')");

        $confirm = $editor->get('/admin.php?tab=backups&restore=' . rawurlencode($made[0]));
        $t->clean($confirm, 'restore confirmation');
        $admin($editor, 'backups', ['do' => 'backup_restore', 'name' => $made[0]], $csrf);
        $after = $counts();
        $t->same($before['performances'], $after['performances'], 'restore brings deleted rows back');
        $t->same(0, (int) db_value("SELECT count(*) FROM productions WHERE title_sk = 'SMOKE-AFTER-BACKUP'"), 'restore removes what was added after the backup');
        $t->same($before['users'] + 1, $after['users'], 'accounts are left alone unless asked for');
        $t->same($images, db_value("SELECT images::text FROM productions ORDER BY id LIMIT 1"), 'jsonb columns survive the round trip');
        $next = (int) db_value("SELECT nextval(pg_get_serial_sequence('productions', 'id'))");
        $t->ok($next > (int) db_value('SELECT max(id) FROM productions'), 'id sequences continue after the restored rows');
        $t->ok((bool) array_filter(array_column(backup_list(), 'reason'), static fn (string $r): bool => $r === 'pred-obnovou'), 'a safety backup of the state before the restore exists');
        $t->status(200, $editor->get('/admin.php?tab=backups'), 'the admin is still logged in after the restore');
        $t->status(404, $editor->get('/admin.php?backup=' . rawurlencode('../../includes/config.local.php')), 'backup download cannot leave storage/backups/');
        db_exec("DELETE FROM users WHERE username = 'smoke_editor'");
    });

    // ── throttle: brute-force protection ─────────────────────────────────────

    $t->section('throttle', static function () use ($t, $base, $loginAs): void {
        db_exec('DELETE FROM login_attempts');
        $attacker = new SmokeHttp($base);
        for ($i = 1; $i <= LOGIN_MAX_ATTEMPTS; $i++) {
            $loginAs($attacker, 'tester', 'guess-' . $i);
        }
        $blocked = $loginAs($attacker, 'tester', 'tester-heslo-123');
        $t->ok($blocked['status'] === 200 && str_contains($blocked['body'], e(t('login_throttled'))), 'after ' . LOGIN_MAX_ATTEMPTS . ' failures even the right password is refused for a while');
        $t->same(0, (int) db_value("SELECT count(*) FROM login_attempts WHERE username LIKE '%guess%'"), 'passwords are never written to login_attempts');
        db_exec('DELETE FROM login_attempts');
    });
} finally {
    // ── Back to the start state ──────────────────────────────────────────────
    try {
        backup_restore($startBackup, false, 'smoke');
    } catch (Throwable $e) {
        echo PHP_EOL, 'WARNING: could not restore the start state — run "testsite.php create" for a clean copy. ', $e->getMessage(), PHP_EOL;
    }
    save_setting('site_mode', $modeBefore);
    $cleanup();
    foreach (array_diff(array_column(backup_list(), 'name'), $backupsBefore) as $name) {
        @unlink(backup_dir() . '/' . $name);
    }
    foreach ($filesBefore as $folder => $known) {
        foreach (is_dir($root . '/' . $folder) ? scandir($root . '/' . $folder) : [] as $name) {
            if (!isset($known[$name]) && is_file($root . '/' . $folder . '/' . $name)) {
                @unlink($root . '/' . $folder . '/' . $name);
            }
        }
    }
}

echo PHP_EOL, str_repeat('─', 60), PHP_EOL;
if ($t->failed) {
    echo count($t->failed), ' FAILED, ', $t->passed, ' passed', PHP_EOL;
    foreach ($t->failed as $line) {
        echo '  ✗ ', $line, PHP_EOL;
    }
    $log = $root . '/storage/php-error.log';
    if (is_file($log) && filesize($log) > 0) {
        echo PHP_EOL, 'last lines of ', $log, ':', PHP_EOL, implode('', array_slice(file($log), -8));
    }
    exit(1);
}
echo 'ALL ', $t->passed, ' CHECKS PASSED', PHP_EOL;
exit(0);
