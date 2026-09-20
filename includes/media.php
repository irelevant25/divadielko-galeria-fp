<?php
/**
 * Súbory: nahrávanie po kúskoch, konverzia a zoznam.
 *
 *   assets_original/  pôvodný súbor tak, ako prišiel (verejne nedostupný)
 *   assets/           verzia pre web:
 *                       obrázky → AVIF (zmenšené na image_max_edge)
 *                       zvuk    → Opus
 *                       video   → WebM (AV1 + Opus) + náhľad .avif — po častiach,
 *                                 ako úloha, v ktorej sa dá pokračovať (video.php)
 *
 * Postup prevzatý z anotoki (php/api/media_convert.php): Imagick, kde je,
 * inak GD; zvuk a video cez ffmpeg. Keď sa konverzia nedá, na web ide pôvodný
 * súbor — nahrávanie nikdy nezlyhá len preto, že server nevie kódovať.
 */

declare(strict_types=1);

require_once __DIR__ . '/video.php';

const MEDIA_TYPES = [
    'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image', 'avif' => 'image', 'gif' => 'image',
    'mp4' => 'video', 'mov' => 'video', 'm4v' => 'video', 'webm' => 'video', 'mkv' => 'video', 'avi' => 'video',
    'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'oga' => 'audio', 'opus' => 'audio', 'm4a' => 'audio',
    'aac' => 'audio', 'flac' => 'audio',
];

/** Prípony, ktoré prehliadač zobrazí bez konverzie (záloha, keď konverzia nejde). */
const MEDIA_WEB_SAFE = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'mp4', 'webm', 'm4v', 'mp3', 'ogg', 'oga', 'opus', 'm4a', 'wav'];

function media_dir(): string
{
    return ROOT . '/assets';
}

function originals_dir(): string
{
    return ROOT . '/assets_original';
}

function media_type(string $file): ?string
{
    return MEDIA_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
}

// ── Názvy ────────────────────────────────────────────────────────────────────

/** „Plagát – Šípková Ruženka.JPG" → „plagat-sipkova-ruzenka". */
function media_slug(string $name): string
{
    $name = pathinfo($name, PATHINFO_FILENAME);
    $map  = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l',
        'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
        'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z', 'ß' => 'ss',
    ];
    $name = strtr(mb_strtolower($name), $map);
    if (function_exists('iconv')) {
        $name = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    }
    $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

    return substr($name !== '' ? $name : 'subor', 0, 80);
}

/** Základ názvu, ktorý ešte nie je v assets/ ani v assets_original/ (s akoukoľvek príponou). */
function media_unique_base(string $slug): string
{
    $taken = static function (string $base): bool {
        return (bool) glob(media_dir() . '/' . $base . '.*') || (bool) glob(originals_dir() . '/' . $base . '.*');
    };

    $base = $slug;
    for ($i = 2; $taken($base); $i++) {
        $base = $slug . '-' . $i;
    }

    return $base;
}

/** Bezpečný názov súboru z požiadavky: len meno bez ciest, povolená prípona. */
function media_safe_name(string $name): ?string
{
    $name = basename(str_replace('\\', '/', $name));

    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/', $name) && media_type($name) !== null ? $name : null;
}

// ── Nahrávanie po kúskoch ────────────────────────────────────────────────────

/** Veľkosť kúska: z configu, ale nikdy nad limity PHP na serveri. */
function upload_chunk_size(): int
{
    $limit = min(ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size')));
    $want  = (int) config('upload.chunk_size');

    return max(256 * 1024, min($want, $limit - 64 * 1024));
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    $num   = (int) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
    }

    return $num > 0 ? $num : PHP_INT_MAX;
}

/**
 * Prijme jeden kúsok. Po poslednom súbor spracuje a vráti jeho údaje.
 *
 * @return array{done: bool, received?: int, file?: array}
 */
function upload_chunk(array $post, array $files, int $userId): array
{
    $id     = (string) ($post['upload_id'] ?? '');
    $offset = (int) ($post['offset'] ?? -1);
    $size   = (int) ($post['size'] ?? -1);
    $name   = (string) ($post['name'] ?? '');

    if (!preg_match('/^[a-f0-9]{32}$/', $id) || $offset < 0 || $size <= 0) {
        throw new InvalidArgumentException(t('up_err_request'));
    }
    if ($size > (int) config('upload.max_size')) {
        throw new InvalidArgumentException(t('up_err_too_big', format_bytes((int) config('upload.max_size'))));
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(MEDIA_TYPES[$ext])) {
        throw new InvalidArgumentException(t('up_err_type', $ext !== '' ? '.' . $ext : '?'));
    }

    $chunk = $files['chunk'] ?? null;
    if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(t('up_err_request'));
    }

    $tmpDir = ROOT . '/storage/uploads';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }
    upload_cleanup($tmpDir);

    $part    = $tmpDir . '/' . $userId . '-' . $id . '.part';
    $current = is_file($part) ? filesize($part) : 0;
    if ($offset === 0 && $current > 0) {
        @unlink($part);
        $current = 0;
    }
    if ($offset !== $current) {
        // Klient a server sa rozišli (napr. opakovaný kúsok) — povieme mu, kde sme.
        return ['done' => false, 'received' => $current];
    }

    $in  = fopen($chunk['tmp_name'], 'rb');
    $out = fopen($part, 'ab');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    clearstatcache(true, $part);

    $received = filesize($part);
    if ($received > $size) {
        @unlink($part);
        throw new InvalidArgumentException(t('up_err_request'));
    }
    if ($received < $size) {
        return ['done' => false, 'received' => $received];
    }

    try {
        $file = media_ingest($part, $name, !empty($post['delete_original']), $userId);
    } finally {
        if (is_file($part)) {
            @unlink($part);
        }
    }

    // Dlhé video sa v tejto požiadavke skonvertovať nestihne — klient dostane úlohu a pokračuje po krokoch.
    return isset($file['job']) ? ['done' => true, 'job' => $file['job']] : ['done' => true, 'file' => $file];
}

/** Zmaže nedokončené nahrávania a pracovné súbory konverzie (media_run) staršie ako deň, aj staré úlohy. */
function upload_cleanup(string $dir): void
{
    video_jobs_cleanup();
    foreach (array_merge(glob($dir . '/*.part') ?: [], glob($dir . '/dgf*') ?: []) as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
}

/**
 * Zaradí hotový súbor: overí obsah, uloží originál, vytvorí webovú verziu.
 */
function media_ingest(string $path, string $clientName, bool $deleteOriginal, int $userId = 0): array
{
    $ext  = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
    $type = MEDIA_TYPES[$ext] ?? null;

    // Príponu určuje zoznam povolených, nie klient; a obsah musí zodpovedať typu.
    $mime = function_exists('finfo_open') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
    $mimeOk = $mime === '' || $mime === 'application/octet-stream'
        || strpos($mime, $type . '/') === 0
        || ($type === 'audio' && in_array($mime, ['application/ogg', 'video/ogg', 'video/webm', 'video/mp4'], true))
        || ($type === 'video' && in_array($mime, ['application/x-matroska', 'application/mp4'], true));
    if ($type === null || !$mimeOk || ($type === 'image' && !media_is_image($path, $ext))) {
        throw new InvalidArgumentException(t('up_err_content'));
    }

    $base     = media_unique_base(media_slug($clientName));
    $original = originals_dir() . '/' . $base . '.' . $ext;
    if (!is_dir(originals_dir())) {
        mkdir(originals_dir(), 0775, true);
    }
    if (!@rename($path, $original) && !(@copy($path, $original) && @unlink($path))) {
        throw new RuntimeException(t('up_err_save'));
    }

    $result = media_convert($original, $base, ['delete_original' => $deleteOriginal, 'user' => $userId]);
    if ($result === null) {
        @unlink($original);
        throw new InvalidArgumentException(t('up_err_convert'));
    }
    if (isset($result['job'])) {
        return ['job' => $result['job']]; // originál úloha ešte potrebuje; zmaže ho sama, keď skončí
    }
    if (isset($result['cancelled'])) {
        throw new InvalidArgumentException(t('job_err_gone')); // originál ostáva, ako zrušenie sľubuje
    }

    if ($deleteOriginal) {
        @unlink($original);
    }

    return media_info($result['file']) + ['converted' => $result['converted']];
}

/** AVIF getimagesize na starších PHP nepozná, preto aj záloha cez hlavičku súboru. */
function media_is_image(string $path, string $ext): bool
{
    if (@getimagesize($path) !== false) {
        return true;
    }
    $head = (string) @file_get_contents($path, false, null, 0, 32);

    return $ext === 'avif' && strpos($head, 'ftypavi') !== false;
}

/**
 * Z originálu vyrobí webovú verziu v assets/. Vracia názov súboru a či
 * prebehla konverzia, alebo null, keď sa súbor nedá použiť vôbec. Pri videu,
 * ktoré sa nestihne za upload.video_inline_seconds, vracia rozpracovanú úlohu
 * ('job') — v tej sa pokračuje po krokoch (video.php). $opts: delete_original, user.
 *
 * @return array{file: string, converted: bool}|array{job: array}|null
 */
function media_convert(string $original, string $base, array $opts = []): ?array
{
    $ext  = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $type = MEDIA_TYPES[$ext] ?? null;
    if (!is_dir(media_dir())) {
        mkdir(media_dir(), 0775, true);
    }

    @set_time_limit(0);
    ignore_user_abort(true);

    $target = null;
    // Animovaný GIF by konverzia zmenila na statický obrázok — ten ide bez zmeny.
    if ($type === 'image' && $ext !== 'gif') {
        $target = $base . '.avif';
        $ok = media_image_to_avif($original, media_dir() . '/' . $target);
    } elseif ($type === 'audio') {
        $target = $base . '.opus';
        // -map_metadata -1: názov nahrávky z mobilu býva adresa, kde vznikla — na web nepatrí
        $ok = media_ffmpeg(['-i', $original, '-vn', '-map_metadata', '-1', '-map_chapters', '-1', '-c:a', 'libopus', '-b:a', (string) config('upload.opus_bitrate'), media_dir() . '/' . $target]);
    } elseif ($type === 'video') {
        $target = $base . '.webm';
        $job = video_job_create($original, media_dir() . '/' . $target, ['base' => $base] + $opts);
        if ($job !== null) {
            // Dlhé video sa v tejto požiadavke ani nezačne: už len zvuk hodinového záznamu trvá desiatky
            // sekúnd a požiadavka, v ktorej sa práve donahrával súbor, nesmie naraziť na časový limit brány.
            $long = $job['probe']['duration'] <= 0 || $job['probe']['duration'] > 300;
            $job  = $long ? $job : video_job_run($job['id'], (float) config('upload.video_inline_seconds'));
            if ($job === null) {
                return ['cancelled' => true]; // zrušili ju v administrácii, kým bežala — nič nezverejňovať
            }
        }
        if ($job !== null && $job['status'] === 'running') {
            return ['job' => video_job_public($job)];
        }
        // hotovo (náhľad aj zmazanie originálu už spravila úloha) alebo zlyhanie hneď na začiatku
        $ok = $job !== null && $job['status'] === 'done';
        if ($job !== null) {
            video_job_remove($job['id']);
        }
    } else {
        $ok = false;
    }

    if (!$ok) {
        // Konverzia nejde → na web pôvodný súbor, ak ho prehliadače zobrazia.
        if (!in_array($ext, MEDIA_WEB_SAFE, true)) {
            return null;
        }
        $target = $base . '.' . $ext;
        if (!@copy($original, media_dir() . '/' . $target)) {
            return null;
        }
        if ($type === 'video') {
            media_video_poster($original, $base);
        }
    }

    return ['file' => $target, 'converted' => $ok];
}

// ── Obrázky ──────────────────────────────────────────────────────────────────

function media_can_avif(): bool
{
    return function_exists('imageavif') || (extension_loaded('imagick') && in_array('AVIF', Imagick::queryFormats('AVIF'), true));
}

function media_image_to_avif(string $source, string $target): bool
{
    $maxEdge = (int) config('upload.image_max_edge');
    $quality = (int) config('upload.avif_quality');
    @ini_set('memory_limit', '1024M');

    if (extension_loaded('imagick')) {
        try {
            $im = new Imagick($source);
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            if (max($im->getImageWidth(), $im->getImageHeight()) > $maxEdge) {
                $im->thumbnailImage($maxEdge, $maxEdge, true);
            }
            $im->stripImage();
            $im->setImageFormat('avif');
            $im->setImageCompressionQuality($quality);
            $ok = $im->writeImage($target);
            $im->clear();
            if ($ok && is_file($target) && filesize($target) > 0) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('[media] Imagick AVIF: ' . $e->getMessage() . ' — skúšam GD');
        }
    }

    if (!function_exists('imageavif')) {
        error_log('[media] AVIF nevie zapísať ani Imagick, ani GD — obrázky ostanú v pôvodnom formáte.');
        return false;
    }

    $image = @imagecreatefromstring((string) file_get_contents($source));
    if (!$image) {
        return false;
    }

    $image = media_gd_orient($image, $source);

    $w = imagesx($image);
    $h = imagesy($image);
    if (max($w, $h) > $maxEdge) {
        $scale = $maxEdge / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        unset($image);
        $image = $resized;
    }

    imagepalettetotruecolor($image);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $ok = @imageavif($image, $target, $quality, 6);
    unset($image);

    return $ok && is_file($target) && filesize($target) > 0;
}

/** Fotky z mobilu majú otočenie len v EXIF — GD ho sám neaplikuje. */
function media_gd_orient($image, string $source)
{
    if (!function_exists('exif_read_data')) {
        return $image;
    }
    $exif = @exif_read_data($source);
    $orientation = (int) ($exif['Orientation'] ?? 1);

    $rotations = [3 => 180, 6 => -90, 8 => 90];
    if (isset($rotations[$orientation])) {
        $rotated = imagerotate($image, $rotations[$orientation], 0);
        if ($rotated) {
            unset($image);
            $image = $rotated;
        }
    }

    return $image;
}

// ── Zvuk a video (ffmpeg) ────────────────────────────────────────────────────

/** Existuje funkcia a nie je vypnutá v disable_functions? */
function media_can_call(string $fn): bool
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    return function_exists($fn) && !in_array($fn, $disabled, true);
}

/** Ako tento server dovoľuje spustiť program: proc_open, exec, alebo vôbec. */
function media_runner(): ?string
{
    foreach (['proc_open', 'exec'] as $fn) {
        if (media_can_call($fn)) {
            return $fn;
        }
    }

    return null;
}

/**
 * Pracovný súbor na výstup spusteného programu. V storage/uploads, lebo ten je
 * vnútri webu aj na hostingu s open_basedir; systémový temp je len záloha.
 */
function media_run_log(): ?string
{
    $dir = ROOT . '/storage/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    foreach ([$dir, sys_get_temp_dir()] as $where) {
        $file = @is_writable($where) ? @tempnam($where, 'dgff') : false;
        if (is_string($file) && $file !== '') {
            return $file;
        }
    }

    return null;
}

/**
 * Spustí program. proc_open dostane pole argumentov — escapeshellarg() na
 * Windows nahrádza „!" a „%" medzerou, čo by rozbilo niektoré názvy súborov.
 * Z výstupu sa vracia koniec (chyba býva na konci), najviac $keep znakov.
 *
 * @return array{code: int, output: string}
 */
function media_run(array $command, int $keep = 4000): array
{
    $runner = media_runner();

    if ($runner === 'proc_open' && ($log = media_run_log()) !== null) {
        // Výstup ide do súboru, nie do rúry: pri dlhom videu by sa rúra naplnila
        // a ffmpeg by sa zasekol (a stream_select na rúrach na Windows nejde).
        //
        // Vstup je rúra, ktorú hneď zatvoríme — NIE súbor /dev/null. Súbory z tohto
        // poľa otvára samo PHP, takže pre ne platí open_basedir; na Websupporte
        // /dev/null povolený nie je, proc_open preto zlyhal pri každom programe
        // a vyzeralo to, akoby ffmpeg na serveri chýbal (je v /usr/bin).
        error_clear_last();
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($process)) {
            $why = (string) (error_get_last()['message'] ?? '');
            @unlink($log);

            return ['code' => -1, 'output' => 'nedá sa spustiť ' . $command[0] . ($why !== '' ? ' — ' . $why : '')];
        }
        fclose($pipes[0]); // koniec vstupu hneď: program nesmie čakať na kláves
        $code   = proc_close($process);
        $output = (string) @file_get_contents($log);
        @unlink($log);

        return ['code' => $code, 'output' => trim(substr($output, -$keep))];
    }
    if ($runner === 'proc_open') {
        $runner = media_can_call('exec') ? 'exec' : null; // nie je kam zapisovať výstup
    }

    if ($runner === 'exec') {
        $lines = [];
        $code  = -1;
        @exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $code);

        return ['code' => $code, 'output' => trim(substr(implode("\n", $lines), -$keep))];
    }

    return ['code' => -1, 'output' => 'proc_open() aj exec() sú vypnuté'];
}

function media_ffmpeg_binary(): ?string
{
    static $resolved = false;
    static $binary = null;

    if ($resolved) {
        return $binary;
    }
    $resolved = true;

    if (media_runner() === null) {
        error_log('[media] proc_open() aj exec() sú vypnuté — zvuk a video ostanú v pôvodnom formáte.');
        return null;
    }

    $exe = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
    $candidates = array_filter([
        (string) config('ffmpeg'),
        ROOT . '/tools/ffmpeg' . $exe,
        'ffmpeg',
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
    ]);

    // Prečo sa ktorý nespustil, ide do logu: „nenašiel sa" a „server ho nedovolil
    // spustiť" (open_basedir, vypnuté funkcie) vyzerajú zvonka rovnako.
    $why = [];
    foreach ($candidates as $candidate) {
        $result = media_run([$candidate, '-version']);
        if ($result['code'] === 0) {
            return $binary = $candidate;
        }
        $why[] = $candidate . ': ' . mb_substr(trim((string) preg_replace('/\s+/u', ' ', $result['output'])), 0, 160);
    }

    error_log('[media] ffmpeg sa nepodarilo spustiť — zvuk a video ostanú v pôvodnom formáte. Nastavte "ffmpeg" v config.local.php alebo ho dajte do tools/. Pokusy: '
        . implode(' | ', array_slice($why, 0, 4)));

    return null;
}

/** Spustí ffmpeg; posledný argument je výstupný súbor. Pri chybe polovičatý výstup zmaže. */
function media_ffmpeg(array $args): bool
{
    $ffmpeg = media_ffmpeg_binary();
    if ($ffmpeg === null) {
        return false;
    }

    $target = (string) end($args);
    $result = media_run(array_merge([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y'], $args));

    if ($result['code'] !== 0 || !is_file($target) || filesize($target) === 0) {
        if (is_file($target)) {
            @unlink($target);
        }
        error_log('[media] ffmpeg zlyhal na ' . basename($target) . ': ' . substr($result['output'], -500));
        return false;
    }

    return true;
}

// ── Náhľady videí z YouTube ──────────────────────────────────────────────────

/**
 * Stiahne náhľad YouTube videa k nám (AVIF v assets/). Návštevník tak z Google
 * nenačíta nič, kým sám nespustí video. Vracia názov súboru alebo null.
 */
function media_youtube_poster(string $id): ?string
{
    $base = 'youtube-' . media_slug($id) . '-' . substr(md5($id), 0, 4);
    foreach (glob(media_dir() . '/' . $base . '.*') ?: [] as $existing) {
        return basename($existing);
    }
    if (!is_dir(originals_dir())) {
        mkdir(originals_dir(), 0775, true);
    }

    // maxres existuje len pri HD videách, ostatné veľkosti vždy.
    foreach (['maxresdefault', 'sddefault', 'hqdefault'] as $size) {
        $data = http_get('https://i.ytimg.com/vi/' . rawurlencode($id) . '/' . $size . '.jpg');
        if ($data === null || @getimagesizefromstring($data) === false) {
            continue;
        }
        $original = originals_dir() . '/' . $base . '.jpg';
        file_put_contents($original, $data);
        $result = media_convert($original, $base);
        @unlink($original);

        return $result['file'] ?? null;
    }

    return null;
}

/** GET s krátkym časovým limitom; null pri chybe alebo inom stave než 200. */
function http_get(string $url, int $timeout = 8): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return is_string($body) && $code === 200 ? $body : null;
    }

    if (ini_get('allow_url_fopen')) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));

        return is_string($body) ? $body : null;
    }

    return null;
}

// ── Zoznam súborov ───────────────────────────────────────────────────────────

/**
 * Originály k súboru v assets/: rovnaký základ názvu a rovnaký druh. Druh sa
 * kontroluje, lebo náhľad videa (klip.avif) zdieľa základ s videom (klip.mov).
 */
function media_originals_of(string $file): array
{
    $type = media_type($file);
    $base = pathinfo($file, PATHINFO_FILENAME);

    return array_values(array_filter(
        glob(originals_dir() . '/' . $base . '.*') ?: [],
        static fn ($o) => media_type($o) === $type
    ));
}

function media_info(string $file): array
{
    $path = media_dir() . '/' . $file;
    $originals = media_originals_of($file);

    $info = [
        'name'     => $file,
        'url'      => media_url($file),
        'type'     => media_type($file) ?? 'other',
        'size'     => is_file($path) ? filesize($path) : 0,
        'modified' => is_file($path) ? filemtime($path) : 0,
        'original' => $originals ? basename($originals[0]) : null,
        'original_size' => $originals ? filesize($originals[0]) : null,
    ];

    if ($info['type'] === 'image' && ($dim = @getimagesize($path))) {
        $info['width']  = $dim[0];
        $info['height'] = $dim[1];
    }

    return $info;
}

/** Súbory v assets/, najnovšie prvé. $type = image|video|audio|'' (všetko). */
function media_list(string $type = ''): array
{
    $out = [];
    foreach (scandir(media_dir()) ?: [] as $name) {
        if ($name[0] === '.' || !is_file(media_dir() . '/' . $name) || media_type($name) === null) {
            continue;
        }
        if ($type !== '' && media_type($name) !== $type) {
            continue;
        }
        $out[] = media_info($name);
    }

    usort($out, static fn ($a, $b) => $b['modified'] <=> $a['modified']);

    return $out;
}

/** Originály bez webovej verzie (napr. nahraté cez FTP) — dajú sa skonvertovať v administrácii. */
function media_unconverted(): array
{
    // video, na ktorom pracuje úloha, tiež ešte nemá webovú verziu — to však nie je „neskonvertované"
    $inJobs = array_map(static fn (array $job): string => basename((string) $job['source']), video_jobs());

    $out = [];
    foreach (scandir(originals_dir()) ?: [] as $name) {
        if ($name[0] === '.' || !is_file(originals_dir() . '/' . $name) || media_type($name) === null || in_array($name, $inJobs, true)) {
            continue;
        }
        if (!glob(media_dir() . '/' . pathinfo($name, PATHINFO_FILENAME) . '.*')) {
            $out[] = ['name' => $name, 'type' => media_type($name), 'size' => filesize(originals_dir() . '/' . $name)];
        }
    }

    return $out;
}

/**
 * Kde všade sú súbory použité (aby sa nezmazalo niečo, čo je na stránke).
 *
 * @return array<string, array<string, int>> súbor → [entita → počet]
 */
function media_usage_map(): array
{
    $map = [];
    foreach (entities()['entities'] as $entity => $def) {
        foreach ($def['fields'] as $field => $f) {
            if ($f['type'] === 'file') {
                $sql = "SELECT $field AS file, count(*) AS n FROM $entity WHERE $field IS NOT NULL GROUP BY $field";
            } elseif ($f['type'] === 'files') {
                // zoznam v jsonb — každý názov zvlášť
                $sql = "SELECT item AS file, count(*) AS n FROM $entity, jsonb_array_elements_text($field) AS item GROUP BY item";
            } else {
                continue;
            }
            foreach (db_all($sql) as $row) {
                $map[$row['file']][$entity] = ($map[$row['file']][$entity] ?? 0) + (int) $row['n'];
            }
        }
    }

    return $map;
}

function media_delete(string $file): void
{
    $safe = media_safe_name($file);
    if ($safe === null) {
        throw new InvalidArgumentException(t('up_err_request'));
    }

    foreach (media_originals_of($safe) as $original) {
        @unlink($original);
    }
    @unlink(media_dir() . '/' . $safe);
}

function format_bytes(int $bytes): string
{
    $units = ['B', 'kB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return ($i === 0 ? (string) $bytes : number_format($value, $value < 10 ? 1 : 0, ',', ' ')) . ' ' . $units[$i];
}
