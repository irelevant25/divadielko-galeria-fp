<?php
/**
 * ffmpeg-check — can this web server run ffmpeg from PHP, and HOW?
 *
 * Standalone (no includes, PHP 7.2+), so the same file works on any project's hosting.
 *
 *   1. Set KEY below to a long random string (an empty KEY refuses to run).
 *   2. Upload the file as ffmpeg.php next to index.php.
 *   3. Open  https://<site>/ffmpeg.php?key=<KEY>   — the answer is plain text, copy all of it.
 *   4. DELETE the file from the server. (It also switches itself off 48 hours after upload.)
 *
 * It looks for ffmpeg (PATH, the usual system paths, copies carried by the project), then starts
 * "<ffmpeg> -version" with every method PHP offers — separately, showing the PHP error when one
 * fails — and finally does three tiny real conversions. Shared hosting typically restricts
 * open_basedir and disables some process functions, so "ffmpeg exists" and "this project's way of
 * starting it works" are two different questions; the report answers both.
 *
 * Safe by construction: nothing from the request ever reaches a command line — the commands are
 * fixed, the key is only compared. Without the right key it runs nothing and answers 403 with a
 * sentence saying what is missing. From the command line (php ffmpeg.php) no key is needed.
 */

const KEY = '';

// ─────────────────────────────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(180);

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    // Nothing runs without the key, so saying what is wrong gives nothing away - a bare 404 only
    // made the owner think the upload had failed.
    $given = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    if (strlen(KEY) < 16) {
        http_response_code(403);
        exit("ffmpeg-check: this copy has no KEY.\n\nOpen the file, set  const KEY = '<a long random string>';  near the top, upload it again\nand open  ffmpeg.php?key=<that string>\n");
    }
    if (!hash_equals(KEY, $given)) {
        http_response_code(403);
        exit("ffmpeg-check: the key is missing or wrong.\n\nOpen  ffmpeg.php?key=<KEY>  - the KEY is the constant near the top of this file.\n");
    }
    if (time() - (int) filemtime(__FILE__) > 48 * 3600) {
        http_response_code(410);
        exit("This diagnostic switched itself off 48 hours after it was uploaded. Upload it again to use it - and delete it afterwards.\n");
    }
}

$windows = DIRECTORY_SEPARATOR === '\\';
$exe     = $windows ? '.exe' : '';
$devNull = $windows ? 'NUL' : '/dev/null';
$started = microtime(true);

function out($line = '')
{
    echo $line, "\n";
}

function head($title)
{
    out();
    out('== ' . $title);
}

function row($label, $value)
{
    out('  ' . str_pad($label, 34) . (strlen($label) >= 34 ? '  ' : '') . $value);
}

/** Runs $fn and returns [result, text of the PHP warnings it raised] — @ would hide the reason. */
function watched($fn)
{
    $warnings = array();
    set_error_handler(function ($no, $message) use (&$warnings) {
        $warnings[] = html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8'); // html_errors may be on
        return true;
    });
    try {
        $result = $fn();
    } catch (Throwable $e) {
        $result = null;
        $warnings[] = get_class($e) . ': ' . $e->getMessage();
    }
    restore_error_handler();

    return array($result, implode(' | ', array_unique($warnings)));
}

function usable($function)
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    return function_exists($function) && !in_array($function, $disabled, true);
}

function first_line($text)
{
    $lines = preg_split('/\R/', trim((string) $text));

    return substr((string) $lines[0], 0, 110);
}

function command_line(array $command)
{
    return implode(' ', array_map('escapeshellarg', $command));
}

/**
 * The ways of starting a program. Each returns array(exit code or null, output, php warnings).
 * A and B are what the two projects really do; C is B without the two files that open_basedir
 * tends to forbid.
 */
$workDir = null; // set below: a folder this script may write to
$methods = array(
    'A' => array('proc_open · argument array · output through pipes            [anotoki runs it like this]', function (array $command) {
        if (!usable('proc_open')) {
            return array(null, '', 'proc_open is not available');
        }
        return watched_run(function () use ($command) {
            $process = proc_open(PHP_VERSION_ID >= 70400 ? $command : command_line($command), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            if (!is_resource($process)) {
                return array(null, '');
            }
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return array(proc_close($process), $output);
        });
    }),
    'B' => array('proc_open · argument array · stdin = ' . $devNull . ' · output to a file in the system temp dir   [Divadielko runs it like this]', function (array $command) use ($devNull) {
        if (!usable('proc_open')) {
            return array(null, '', 'proc_open is not available');
        }
        return watched_run(function () use ($command, $devNull) {
            $log = tempnam(sys_get_temp_dir(), 'dgff');
            $process = proc_open(PHP_VERSION_ID >= 70400 ? $command : command_line($command), array(0 => array('file', $devNull, 'r'), 1 => array('file', $log, 'w'), 2 => array('file', $log, 'a')), $pipes);
            if (!is_resource($process)) {
                @unlink($log);
                return array(null, '');
            }
            $code = proc_close($process);
            $output = (string) file_get_contents($log);
            @unlink($log);
            return array($code, $output);
        });
    }),
    'C' => array('proc_open · argument array · stdin = closed pipe · output to a file in a folder of the site', function (array $command) use (&$workDir) {
        if (!usable('proc_open')) {
            return array(null, '', 'proc_open is not available');
        }
        if ($workDir === null) {
            return array(null, '', 'no writable folder next to this script');
        }
        return watched_run(function () use ($command, &$workDir) {
            $log = tempnam($workDir, 'ffc');
            $process = proc_open(PHP_VERSION_ID >= 70400 ? $command : command_line($command), array(0 => array('pipe', 'r'), 1 => array('file', $log, 'w'), 2 => array('file', $log, 'a')), $pipes);
            if (!is_resource($process)) {
                @unlink($log);
                return array(null, '');
            }
            fclose($pipes[0]); // end of input at once: ffmpeg must never wait for a key press
            $code = proc_close($process);
            $output = (string) file_get_contents($log);
            @unlink($log);
            return array($code, $output);
        });
    }),
    'D' => array('exec() · escapeshellarg · 2>&1', function (array $command) {
        if (!usable('exec')) {
            return array(null, '', 'exec is not available');
        }
        return watched_run(function () use ($command) {
            $lines = array();
            $code = null;
            exec(command_line($command) . ' 2>&1', $lines, $code);
            return array($code, implode("\n", $lines));
        });
    }),
    'E' => array('shell_exec() · escapeshellarg · 2>&1', function (array $command) {
        if (!usable('shell_exec')) {
            return array(null, '', 'shell_exec is not available');
        }
        return watched_run(function () use ($command) {
            // shell_exec gives no exit code, so it is judged by what the program printed. (Appending
            // '; echo $?' is not an option: some hosts refuse a command line that chains commands.)
            $output = shell_exec(command_line($command) . ' 2>&1');
            if (!is_string($output)) {
                return array(null, '');
            }
            return array(stripos($output, 'ffmpeg version') !== false || trim($output) === '' ? 0 : 1, $output);
        });
    }),
);

function watched_run($fn)
{
    list($result, $warnings) = watched($fn);
    if (!is_array($result)) {
        $result = array(null, '');
    }

    return array($result[0], (string) $result[1], $warnings);
}

// ── PHP and its limits ───────────────────────────────────────────────────────

out('ffmpeg-check · ' . gmdate('Y-m-d H:i:s') . ' UTC');
head('PHP');
row('version / SAPI', PHP_VERSION . ' / ' . PHP_SAPI);
row('system', php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'));
row('runs as', (function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user()));
row('script', __FILE__);
row('open_basedir', ini_get('open_basedir') !== '' && ini_get('open_basedir') !== false ? ini_get('open_basedir') : '(not set - PHP may open any path)');
row('disable_functions', trim((string) ini_get('disable_functions')) !== '' ? ini_get('disable_functions') : '(none)');
row('max_execution_time / memory', ini_get('max_execution_time') . ' s / ' . ini_get('memory_limit'));
row('PATH seen by PHP', getenv('PATH') !== false ? getenv('PATH') : '(not set)');

list($tmpOk, $tmpWhy) = watched(function () {
    $file = tempnam(sys_get_temp_dir(), 'ffc');
    $ok = $file !== false && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', rtrim(sys_get_temp_dir(), '/\\'))) === 0 && file_put_contents($file, 'x') === 1;
    if ($file !== false) {
        @unlink($file);
    }
    return $ok;
});
row('system temp dir', sys_get_temp_dir() . ' - ' . ($tmpOk ? 'writable' : 'NOT usable' . ($tmpWhy !== '' ? ' (' . $tmpWhy . ')' : '')));
list($nullOk, $nullWhy) = watched(function () use ($devNull) {
    $handle = fopen($devNull, 'r');
    if ($handle) {
        fclose($handle);
    }
    return (bool) $handle;
});
row('PHP may open ' . $devNull, $nullOk ? 'yes' : 'NO' . ($nullWhy !== '' ? ' (' . $nullWhy . ')' : ''));

foreach (array(__DIR__ . '/storage/uploads', __DIR__ . '/storage', __DIR__, sys_get_temp_dir()) as $dir) {
    list($ok) = watched(function () use ($dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $probe = tempnam($dir, 'ffc');
        if ($probe === false) {
            return false;
        }
        @unlink($probe);
        return strpos(str_replace('\\', '/', $probe), str_replace('\\', '/', rtrim($dir, '/\\'))) === 0;
    });
    if ($ok) {
        $workDir = $dir;
        break;
    }
}
row('folder used for test files', $workDir !== null ? $workDir : 'NONE writable - conversions are skipped');

head('ways of starting a program');
foreach (array('proc_open', 'exec', 'shell_exec', 'passthru', 'system', 'popen') as $function) {
    row($function . '()', usable($function) ? 'available' : (function_exists($function) ? 'DISABLED (disable_functions)' : 'does not exist'));
}

// ── Where could ffmpeg be? ───────────────────────────────────────────────────

head('looking for ffmpeg');
out('  (is_file obeys open_basedir: "hidden from PHP" does not mean the program cannot be started)');

$candidates = array('ffmpeg' => 'on PATH');
foreach (array('/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/bin/ffmpeg', '/opt/bin/ffmpeg', '/opt/ffmpeg/bin/ffmpeg', '/usr/local/ffmpeg/bin/ffmpeg', '/snap/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg') as $path) {
    $candidates[$path] = 'system path';
}
$extra = getenv('FFMPEG_CHECK_EXTRA'); // for trying the script on a developer machine
if (is_string($extra) && $extra !== '') {
    $candidates[$extra] = 'FFMPEG_CHECK_EXTRA';
}
// copies a project carries with it: Divadielko → tools/, anotoki → formats-converters/
$roots = array(__DIR__, dirname(__DIR__), dirname(__DIR__, 2));
foreach ($roots as $root) {
    foreach (array('tools/ffmpeg', 'formats-converters/ffmpeg', 'formats-converters/node_modules/ffmpeg-static/ffmpeg', 'node_modules/ffmpeg-static/ffmpeg', 'bin/ffmpeg') as $relative) {
        $candidates[$root . '/' . $relative . $exe] = 'carried by the project';
    }
}

$present = array(); // files PHP can see
$hidden  = array(); // outside open_basedir: PHP cannot look, but the program may still be startable
foreach ($candidates as $path => $kind) {
    if ($path === 'ffmpeg') {
        continue;
    }
    list($state, $why) = watched(function () use ($path) {
        return is_file($path) ? (is_executable($path) ? 'exists, executable' : 'exists, NOT executable (chmod 755 needed)') : 'no';
    });
    if ($why !== '' && stripos($why, 'open_basedir') !== false) {
        $hidden[] = $path;
    } elseif ($state !== 'no') {
        $present[$path] = $state;
        row($path, $state . '  [' . $kind . ']');
    }
}
if (!$present) {
    out('  no file found in the ' . (count($candidates) - 1 - count($hidden)) . ' locations PHP is allowed to look at');
}
if ($hidden) {
    out('  ' . count($hidden) . ' locations are outside open_basedir (PHP cannot look there) - they are tried blindly below');
}
$locator = $windows ? 'where ffmpeg' : 'command -v ffmpeg';
foreach (array('exec', 'shell_exec') as $function) {
    if (usable($function)) {
        list($found) = watched(function () use ($function, $locator) {
            if ($function === 'exec') {
                $lines = array();
                exec($locator . ' 2>&1', $lines);
                return implode(' ', $lines);
            }
            return (string) shell_exec($locator . ' 2>&1');
        });
        row('"' . $locator . '"', trim((string) $found) !== '' ? trim((string) $found) : '(nothing)');
        break;
    }
}

// ── Start "<candidate> -version" with every method ───────────────────────────

head('starting "<ffmpeg> -version"');
foreach ($methods as $letter => $method) {
    out('  [' . $letter . '] = ' . $method[0]);
}
out();

$attempt = function ($candidate, $letter) use ($methods) {
    $t = microtime(true);
    list($code, $output, $warnings) = $methods[$letter][1](array($candidate, '-version'));
    $ok = $code === 0 && stripos($output, 'ffmpeg version') !== false;
    $reason = $warnings !== '' ? $warnings : ($code === null ? 'could not be started' : 'exit code ' . $code . (trim($output) !== '' ? ': ' . first_line($output) : ''));

    return array($ok, $ok ? first_line($output) : substr($reason, 0, 230), (int) round((microtime(true) - $t) * 1000));
};

// Every candidate gets one quick try per method; the full table is printed only for those that
// start with at least one of them - a server with open_basedir would otherwise fill pages.
$tryList = array_merge(array('ffmpeg'), array_keys($present), $hidden);
$works = array(); // candidate → letters of the methods that start it
$dead  = array(); // candidate → why (as seen by method A, or the first method that exists)
foreach ($tryList as $candidate) {
    $results = array();
    foreach ($methods as $letter => $method) {
        $results[$letter] = $attempt($candidate, $letter);
    }
    $okLetters = array_keys(array_filter($results, function ($r) {
        return $r[0];
    }));
    if (!$okLetters) {
        $dead[$candidate] = $results['A'][1];
        continue;
    }
    $works[$candidate] = $okLetters;
    out('  ' . $candidate);
    foreach ($results as $letter => $r) {
        out('    [' . $letter . '] ' . ($r[0] ? 'OK      ' . $r[1] . '   (' . $r[2] . ' ms)' : 'failed  ' . $r[1]));
    }
}
if ($dead) {
    out('  did not start with any method (' . count($dead) . '):');
    foreach ($dead as $candidate => $why) {
        // the two informative ones in full, the rest as names
        if ($candidate === 'ffmpeg' || $candidate === '/usr/bin/ffmpeg') {
            out('    ' . $candidate . '  -  ' . $why);
        }
    }
    $others = array_diff(array_keys($dead), array('ffmpeg', '/usr/bin/ffmpeg'));
    if ($others) {
        out('    ' . wordwrap(implode(', ', $others), 150, "\n    ", false));
    }
}
if (!$works) {
    out('  -> nothing answered to "-version"');
}

// ── Real work with the first ffmpeg that starts ──────────────────────────────

$ffmpeg = null;
$via = null;
foreach ($works as $candidate => $letters) {
    $ffmpeg = $candidate;
    $via = $letters[0];
    break;
}

if ($ffmpeg !== null) {
    $run = $methods[$via][1];

    head('encoders of ' . $ffmpeg . '   (started with method ' . $via . ')');
    list(, $encoders) = $run(array($ffmpeg, '-hide_banner', '-encoders'));
    foreach (array('libx264' => 'H.264 video - what MP4 needs', 'libopus' => 'Opus audio', 'aac' => 'AAC audio', 'libvpx-vp9' => 'VP9 video', 'libsvtav1' => 'AV1 video', 'libaom-av1' => 'AV1 video', 'png' => 'PNG frames (video posters)') as $name => $what) {
        row($name, (preg_match('/^\s*[VAS][\w.]{5}\s+' . preg_quote($name, '/') . '\s/m', (string) $encoders) ? 'yes' : 'NO') . '   ' . $what);
    }

    head('real conversions');
    if ($workDir === null) {
        out('  skipped - no writable folder');
    } else {
        $base = $workDir . '/ffcheck-' . bin2hex(random_bytes(4));
        $made = array();

        // 0.4 s of a 440 Hz tone as a 16-bit mono WAV, built here so nothing has to be uploaded
        $samples = '';
        for ($i = 0; $i < 6400; $i++) {
            $samples .= pack('v', (int) (12000 * sin(2 * M_PI * 440 * $i / 16000)) & 0xFFFF);
        }
        $wav = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($samples)) . $samples;
        file_put_contents($base . '.wav', $wav);
        $made[] = $base . '.wav';

        $jobs = array(
            'WAV -> Opus (audio upload)' => array($base . '.opus', array($ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $base . '.wav', '-vn', '-c:a', 'libopus', '-b:a', '96k', $base . '.opus')),
            'test pattern -> MP4 H.264 + Opus (video upload)' => array($base . '.mp4', array($ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=10', '-i', $base . '.wav', '-map', '0:v:0', '-map', '1:a:0', '-shortest', '-c:v', 'libx264', '-preset', 'medium', '-crf', '26', '-pix_fmt', 'yuv420p', '-c:a', 'libopus', '-b:a', '96k', '-movflags', '+faststart', '-strict', '-2', $base . '.mp4')),
            'MP4 -> PNG frame (video poster)' => array($base . '.png', array($ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $base . '.mp4', '-frames:v', '1', $base . '.png')),
        );
        foreach ($jobs as $label => $job) {
            $t = microtime(true);
            list($code, $output, $warnings) = $run($job[1]);
            $ms = (int) round((microtime(true) - $t) * 1000);
            $made[] = $job[0];
            $size = is_file($job[0]) ? filesize($job[0]) : 0;
            row($label, $code === 0 && $size > 0 ? 'OK  ' . $size . ' bytes, ' . $ms . ' ms' : 'FAILED  ' . substr(trim($warnings . ' ' . first_line($output)) !== '' ? trim($warnings . ' ' . first_line($output)) : 'exit code ' . var_export($code, true), 0, 200));
        }
        foreach ($made as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

// ── Images, because the same upload code converts them too ───────────────────

head('images');
row('GD', extension_loaded('gd') ? 'loaded, imageavif(): ' . (function_exists('imageavif') ? 'yes' : 'NO') . ', imagewebp(): ' . (function_exists('imagewebp') ? 'yes' : 'NO') : 'not loaded');
if (extension_loaded('imagick')) {
    list($avif) = watched(function () {
        return in_array('AVIF', Imagick::queryFormats('AVIF'), true);
    });
    row('Imagick', 'loaded, AVIF: ' . ($avif ? 'yes' : 'NO'));
} else {
    row('Imagick', 'not loaded');
}

// ── What it means ────────────────────────────────────────────────────────────

head('verdict');
if ($ffmpeg === null) {
    $canStart = usable('proc_open') || usable('exec') || usable('shell_exec');
    out($canStart
        ? '  No ffmpeg could be started from PHP here. Either the server has none, or it is somewhere this script does not look - ask the provider for the path.'
        : '  PHP on this server may not start programs at all (proc_open, exec and shell_exec are unavailable), so ffmpeg cannot be used even if it is installed.');
} else {
    foreach ($works as $candidate => $letters) {
        out('  ' . $candidate . ' starts with: ' . implode(', ', $letters));
    }
    $all = array();
    foreach ($works as $letters) {
        $all = array_merge($all, $letters);
    }
    out();
    out('  anotoki     (method A): ' . (in_array('A', $all, true) ? 'works' : (in_array('D', $all, true) && !usable('proc_open') ? 'works through its exec() fallback (D)' : 'FAILS here')));
    out('  Divadielko  (method B): ' . (in_array('B', $all, true) ? 'works' : (in_array('D', $all, true) && !usable('proc_open') ? 'works through its exec() fallback (D)' : 'FAILS here' . (in_array('C', $all, true) ? ' - but method C works, so the fix is in how media_run() starts the program, not on the server' : ''))));
}
out();
out('done in ' . round(microtime(true) - $started, 1) . ' s - now DELETE this file from the server.');
