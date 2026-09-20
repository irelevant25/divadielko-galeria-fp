<?php
/**
 * Video → WebM (AV1 + Opus), po častiach a s možnosťou pokračovať.
 *
 * Prečo po častiach: hodinové predstavenie vo Full HD kóduje zdieľaný hosting aj
 * vyše hodiny, no jedna požiadavka tam žije nanajvýš okolo 100 s (Cloudflare) a
 * procesy na pozadí hosting nezaručuje. Konverzia je preto ÚLOHA v priečinku
 * storage/uploads/job-<id>/ a každý krok je krátky:
 *
 *   zvuk     celý naraz (hodina zvuku = desiatky sekúnd)
 *   obraz    časť po časti, každá ~12 s práce; časti majú presný počet snímok
 *            na pevnej mriežke (filter fps), takže sa na spojoch nič nezdvojí ani nestratí
 *   spojenie bez prekódovania (concat + -c copy), až potom súbor pribudne v assets/
 *
 * Kroky spúšťa prehliadač (api.php → convert_step), administrácia → Súbory, alebo
 * plánovač hostingu (cron.php). Keď niekto zavrie okno, úloha počká a pokračuje
 * sa presne tam, kde skončila. Krátke video sa stihne celé ešte počas nahrávania.
 *
 * Súbor, ktorý už je WebM s AV1 (+ Opus), sa neprekóduje — len sa z neho odstránia
 * údaje z mobilu. Predstavenie sa tak dá skonvertovať aj na vlastnom počítači.
 */

declare(strict_types=1);

// ── Čo ffmpeg vie ────────────────────────────────────────────────────────────

/**
 * Kodéry AV1, ktoré tento ffmpeg má, lepší prvý: SVT-AV1 je rýchly aj úsporný,
 * libaom je všade, kde je AV1 (Websupport má len ten).
 *
 * @return list<string>
 */
function media_av1_encoders(): array
{
    static $found = null;

    if ($found !== null) {
        return $found;
    }
    $found  = [];
    $ffmpeg = media_ffmpeg_binary();
    if ($ffmpeg === null) {
        return $found;
    }

    // Celý zoznam kodérov má vyše 10 kB — preto vlastný limit výstupu.
    $list = media_run([$ffmpeg, '-hide_banner', '-encoders'], 200000)['output'];
    foreach (['libsvtav1', 'libaom-av1'] as $encoder) {
        if (preg_match('/^\s*V\S*\s+' . preg_quote($encoder, '/') . '\s/m', $list)) {
            $found[] = $encoder;
        }
    }
    if (!$found) {
        error_log('[media] tento ffmpeg nemá kodér AV1 (libsvtav1 ani libaom-av1) — videá sa neskonvertujú.');
    }

    return $found;
}

/**
 * Voľby kodéra. Rýchle nastavenia: SVT-AV1 preset 8; libaom v režime realtime
 * (v režime good kóduje na hostingu ~5 snímok/s, realtime ~45 pri 720p).
 *
 * @return list<string>
 */
function video_encoder_args(string $encoder): array
{
    $crf = (string) config('upload.video_crf');

    return [
        // -qp popri -crf: ffmpeg pred verziou 5.1 pri SVT-AV1 voľbu -crf nepozná (len na ňu upozorní
        // a kódoval by predvolenou, nízkou kvalitou); novší berie -crf a -qp si nevšíma.
        'libsvtav1'  => ['-c:v', 'libsvtav1', '-preset', '8', '-crf', $crf, '-qp', $crf],
        'libaom-av1' => ['-c:v', 'libaom-av1', '-usage', 'realtime', '-cpu-used', '8', '-row-mt', '1', '-crf', $crf, '-b:v', '0'],
    ][$encoder];
}

/** Filter ffmpeg: dlhšia strana najviac video_max_edge, rozmery párne (yuv420p), aj keď sa nezmenšuje. */
function media_video_scale(): string
{
    $edge = (int) config('upload.video_max_edge');

    return "scale='if(gt(iw,ih),2*trunc(min($edge,iw)/2),-2)':'if(gt(iw,ih),-2,2*trunc(min($edge,ih)/2))'";
}

/**
 * Čo je v súbore — z výpisu „ffmpeg -i" (ffprobe na hostingu byť nemusí).
 *
 * @return array{duration: float, fps: float, width: int, height: int, video: string, audio: string, format: string}|null
 */
function video_probe(string $file): ?array
{
    $ffmpeg = media_ffmpeg_binary();
    if ($ffmpeg === null) {
        return null;
    }
    $out = media_run([$ffmpeg, '-hide_banner', '-i', $file], 20000)['output'];
    if (!preg_match('/Stream #\S+ Video: (\w+)[^\n]*?, (\d{2,5})x(\d{2,5})/', $out, $v)) {
        return null;
    }
    $duration = preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $out, $d) ? $d[1] * 3600 + $d[2] * 60 + (float) $d[3] : 0.0;
    $line = (string) strtok(substr($out, (int) strpos($out, $v[0])), "\n");
    // „fps" je priemer; pri premenlivej frekvencii (mobil) ho niekedy niet — vtedy „tbr"
    $fps = preg_match('/, ([\d.]+) fps/', $line, $f) ? (float) $f[1] : (preg_match('/, ([\d.]+) tbr/', $line, $f) ? (float) $f[1] : 0.0);

    return [
        'duration' => $duration,
        'fps'      => $fps,
        'width'    => (int) $v[2],
        'height'   => (int) $v[3],
        'video'    => strtolower($v[1]),
        'audio'    => preg_match('/Stream #\S+ Audio: (\w+)/', $out, $a) ? strtolower($a[1]) : '',
        'format'   => preg_match('/^Input #0, ([\w,]+), from/m', $out, $i) ? $i[1] : '',
    ];
}

/**
 * Snímková frekvencia výstupu: najbližšia bežná, najviac video_max_fps
 * (50 → 25, 59,94 → 29,97 — polovica snímok, polovica času kódovania).
 *
 * @return array{0: int, 1: int} čitateľ, menovateľ
 */
function video_rate(float $fps): array
{
    $cap = (float) config('upload.video_max_fps');
    if ($fps < 1 || $fps > 240) {
        $fps = 25.0; // neznáma alebo nezmyselná (napr. „1k tbr" pri zázname obrazovky)
    }
    while ($cap > 0 && $fps > $cap + 0.5) {
        $fps /= 2;
    }
    $best = [25, 1];
    // aj pomalé (webkamera 15, animácia 12): inak by sa zo 15 stalo 23,976 — o 60 % snímok viac a trhaný pohyb
    foreach ([[10, 1], [12, 1], [15, 1], [20, 1], [24000, 1001], [24, 1], [25, 1], [30000, 1001], [30, 1], [50, 1], [60000, 1001], [60, 1]] as $rate) {
        if (abs($rate[0] / $rate[1] - $fps) < abs($best[0] / $best[1] - $fps)) {
            $best = $rate;
        }
    }

    return $best;
}

/**
 * Počet snímok časti musí byť násobkom tohto čísla: časť potom trvá celý počet
 * milisekúnd (WebM ukladá čas v ms) a pri spájaní sa nenazbiera posun zvuku.
 */
function video_segment_multiple(int $num, int $den): int
{
    $gcd = static function (int $a, int $b) use (&$gcd): int {
        return $b === 0 ? $a : $gcd($b, $a % $b);
    };

    return intdiv($num, $gcd($num, 1000 * $den));
}

// ── Úlohy ────────────────────────────────────────────────────────────────────

/** Koľkokrát sa ten istý krok smie nedokončiť (server ho ukončil), kým úlohu vyhlásime za zlyhanú. */
const VIDEO_STEP_ATTEMPTS = 5;

function video_jobs_root(): string
{
    return ROOT . '/storage/uploads';
}

function video_job_dir(string $id): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        throw new InvalidArgumentException(t('up_err_request'));
    }

    return video_jobs_root() . '/job-' . $id;
}

function video_job_load(string $id): ?array
{
    $dir = video_job_dir($id);
    $job = is_file($dir . '/cancel') ? null : json_decode((string) @file_get_contents($dir . '/job.json'), true); // zrušená = akoby nebola

    return is_array($job) && ($job['id'] ?? '') === $id ? $job : null;
}

/** Uloží stav. Keď sa zápis nepodarí (plný disk), starý stav ostáva — polovičný súbor ho nikdy nenahradí. */
function video_job_save(array $job): bool
{
    $job['updated'] = time();
    $file = video_job_dir($job['id']) . '/job.json';
    $disk = json_decode((string) @file_get_contents($file), true);
    if (($disk['status'] ?? '') === 'done' && $job['status'] !== 'done') {
        return true; // hotovú úlohu už nikto neprepíše starším stavom (dvaja naraz bez zámkov)
    }
    $json = (string) json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp  = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
    if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $file)) {
        @unlink($tmp);
        error_log('[video] úloha ' . $job['id'] . ': stav sa nedal uložiť (plný disk?)');

        return false;
    }

    return true;
}

/**
 * Založí úlohu. $opts: base (základ názvu pre náhľad v assets/), user, delete_original,
 * encoders (len testy). Vracia null, keď sa video konvertovať nedá (niet ffmpeg/AV1, nečitateľný súbor).
 */
function video_job_create(string $source, string $target, array $opts = []): ?array
{
    // Na tom istom origináli pracuje najviac jedna úloha — druhá by zapisovala ten istý výsledok.
    $same = static fn (string $path): string => realpath($path) ?: $path; // tá istá cesta býva zapísaná rôzne (…/includes/../…)
    foreach (video_jobs() as $existing) {
        if ($existing['status'] !== 'done' && $same((string) $existing['source']) === $same($source)) {
            return $existing['status'] === 'failed' ? video_job_retry($existing['id']) : $existing;
        }
    }

    $encoders = $opts['encoders'] ?? media_av1_encoders();
    $probe    = $encoders ? video_probe($source) : null;
    if ($probe === null) {
        return null;
    }

    $id  = bin2hex(random_bytes(16));
    $dir = video_job_dir($id);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }

    $rate = video_rate($probe['fps']);
    $edge = (int) config('upload.video_max_edge');
    // Už je to WebM s AV1 (+ Opus) a nie je väčšie než limit → neprekódovať, len očistiť.
    $passthrough = $probe['video'] === 'av1' && in_array($probe['audio'], ['', 'opus'], true)
        && strpos($probe['format'], 'webm') !== false && max($probe['width'], $probe['height']) <= $edge;

    $job = [
        'id'              => $id,
        'status'          => 'running',
        'error_key'       => '',
        'error_args'      => [],
        'source'          => $source,
        'target'          => $target,
        'base'            => isset($opts['base']) ? (string) $opts['base'] : null,
        'user'            => (int) ($opts['user'] ?? 0),
        'delete_original' => !empty($opts['delete_original']),
        'created'         => time(),
        'probe'           => $probe,
        'passthrough'     => $passthrough,
        'rate'            => $rate,
        'total'           => (int) ceil($probe['duration'] * $rate[0] / $rate[1]), // odhad — koniec určí až ffmpeg
        'next'            => 0,
        'segments'        => [],   // hotové časti v poradí: ['file' => …, 'frames' => …]
        'audio'           => $probe['audio'] === '' ? 'none' : 'pending',
        'encoders'        => array_values($encoders),
        'speed'           => 0.0,  // snímok za sekundu práce (kĺzavý priemer)
        'spent'           => 0.0,  // sekúnd práce doteraz
        'last_step'       => 0.0,
        'attempts'        => 0,    // koľkokrát sa začal krok, ktorý sa nedokončil
    ];

    return video_job_save($job) ? $job : null;
}

/** To, čo smie vidieť prehliadač (žiadne cesty na serveri). */
function video_job_public(array $job): array
{
    $total = max(1, (int) $job['total']);
    $done  = $job['status'] === 'done' ? 1.0 : min(0.99, $job['next'] / $total * 0.98 + ($job['audio'] !== 'pending' ? 0.01 : 0));

    return [
        'id'       => $job['id'],
        'status'   => $job['status'],
        'name'     => basename((string) $job['target']),
        'original' => basename((string) $job['source']),
        'progress' => round($done, 4),
        'eta'      => $job['status'] === 'running' && $job['speed'] > 0 ? (int) ceil(max(0, $total - $job['next']) / $job['speed']) + 5 : null,
        'error'    => $job['status'] === 'failed' ? t((string) $job['error_key'], ...array_map('strval', (array) $job['error_args'])) : '',
        'busy'     => !empty($job['busy']),
    ];
}

/**
 * Pracuje na úlohe, kým nie je hotová alebo kým by ďalší krok presiahol $budget
 * sekúnd (vždy spraví aspoň jeden krok). Naraz na úlohe pracuje len jeden —
 * druhý volajúci dostane stav s 'busy'. Vracia null, keď úloha nie je (zrušili ju).
 */
function video_job_run(string $id, float $budget, ?float $segmentSeconds = null): ?array
{
    $dir = video_job_dir($id);
    if (is_file($dir . '/cancel')) {
        video_job_remove($id); // zrušená, kým na nej niekto pracoval — teraz sa už dá upratať

        return null;
    }
    $job = video_job_load($id);
    if ($job === null || $job['status'] !== 'running') {
        return $job;
    }

    @set_time_limit(0);
    ignore_user_abort(true);

    $wouldBlock = 0;
    $lock = @fopen($dir . '/lock', 'c');
    $locked = $lock && flock($lock, LOCK_EX | LOCK_NB, $wouldBlock);
    if ($lock && !$locked && $wouldBlock) {
        fclose($lock);

        return $job + ['busy' => true];
    }
    // Súborový systém bez zámkov: pokračujeme. Časti nesú v názve, čo obsahujú (začiatok a počet
    // snímok), a stav odkazuje len na vlastné súbory — dvaja naraz si prácu zdvoja, ale nepomiešajú.

    $cancelled = false;
    try {
        $started = microtime(true);
        $segment = $segmentSeconds ?? (float) config('upload.video_segment_seconds');
        while ($job['status'] === 'running') {
            if (is_file($dir . '/cancel')) {
                $cancelled = true;
                break;
            }
            // Stav z disku pred každým krokom: bez zámkov ho mohol posunúť niekto iný — jeho prácu
            // neopakujeme. (Nečitateľný stav nie je zrušenie; vtedy platí ten, čo máme v pamäti.)
            $disk = video_job_load($id);
            if ($disk !== null && $disk['status'] !== 'running') {
                $job = $disk;
                break;
            }
            if ($disk !== null && ($disk['next'] > $job['next'] || (!empty($disk['ended']) && empty($job['ended'])) || ($disk['audio'] === 'done' && $job['audio'] === 'pending'))) {
                $job = $disk;
            }

            // Krok, ktorý server zakaždým ukončí skôr, než dobehne (dlhý zvuk, spájanie veľkého videa),
            // by sa inak skúšal donekonečna a percentá by stáli. Pokus sa zapíše PRED krokom.
            if ((int) $job['attempts'] >= VIDEO_STEP_ATTEMPTS) {
                $job = video_job_fail($job, 'job_err_killed', (string) VIDEO_STEP_ATTEMPTS);
                video_job_save($job);
                break;
            }
            $job['attempts'] = (int) $job['attempts'] + 1;
            video_job_save($job);

            $t   = microtime(true);
            $job = video_job_step($job, $segment);
            $job['last_step'] = microtime(true) - $t;
            $job['spent']    += $job['last_step'];
            $job['attempts']  = 0;
            if (is_file($dir . '/cancel')) { // zrušili ju, kým krok bežal → nič neukladať, upratať
                $cancelled = true;
                break;
            }
            video_job_save($job);
            if (microtime(true) - $started + $job['last_step'] > $budget) {
                break;
            }
        }
    } finally {
        if ($lock) {
            $locked && flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    if ($cancelled) {
        video_job_remove($id); // až po uvoľnení zámku — otvorený súbor sa na Windows zmazať nedá

        return null;
    }

    return $job;
}

/** Jeden krok: zvuk → ďalšia časť obrazu → spojenie. */
function video_job_step(array $job, float $segmentSeconds): array
{
    $dir = video_job_dir($job['id']);
    if (!is_file($job['source'])) {
        return video_job_fail($job, 'job_err_source_gone', basename((string) $job['source']));
    }
    // zvyšky po kroku, ktorý server zabil v polovici
    foreach (glob($dir . '/*.part.*') ?: [] as $stale) {
        if (filemtime($stale) < time() - 600) {
            @unlink($stale);
        }
    }
    // Z originálu sa na web nesmie dostať nič osobné: poloha (GPS), typ mobilu, čas nakrútenia, názvy kapitol.
    $clean = ['-map_metadata', '-1', '-map_metadata:s', '-1', '-map_chapters', '-1'];

    if ($job['passthrough']) {
        $out = $dir . '/out.' . bin2hex(random_bytes(3)) . '.part.webm';
        if (!media_ffmpeg(array_merge(['-i', $job['source'], '-map', '0:v:0', '-map', '0:a:0?'], $clean, ['-c', 'copy', $out]))) {
            return video_job_fail($job, 'job_err_remux');
        }

        return video_job_finish($job, $out);
    }

    if ($job['audio'] === 'pending') {
        // aresample first_pts=0: zvuk začne presne na začiatku súboru (doplní ticho alebo oreže) —
        // obraz je ukotvený rovnako (fps start_time), takže voči sebe sedia aj po spojení.
        // aformat: Opus nevie každé rozloženie kanálov (5.1 z kamery) — na web stačí stereo.
        $tmp = $dir . '/audio.' . bin2hex(random_bytes(3)) . '.part.mka';
        $ok  = media_ffmpeg(array_merge(['-i', $job['source'], '-vn', '-map', '0:a:0'], $clean, [
            '-af', 'aresample=async=1:first_pts=0,aformat=channel_layouts=stereo|mono',
            '-c:a', 'libopus', '-b:a', (string) config('upload.opus_bitrate'), $tmp]));
        if (!$ok || !@rename($tmp, $dir . '/audio.mka')) {
            @unlink($tmp);

            return video_job_fail($job, 'job_err_audio');
        }
        $job['audio'] = 'done';

        return $job;
    }

    if (empty($job['ended'])) {
        return video_job_segment($job, $segmentSeconds, $clean);
    }

    // ── spojenie ──
    $list = '';
    foreach ($job['segments'] as $segment) {
        // dĺžku časti udávame sami (presne na mikrosekundy) — na hlavičky súborov sa pri sčítaní nespoliehame
        $list .= sprintf("file '%s'\nduration %.6F\n", $segment['file'], $segment['frames'] * $job['rate'][1] / $job['rate'][0]);
    }
    $listFile = $dir . '/list.' . bin2hex(random_bytes(3)) . '.part.txt';
    if (@file_put_contents($listFile, $list) !== strlen($list)) {
        return video_job_fail($job, 'job_err_save');
    }
    $out   = $dir . '/out.' . bin2hex(random_bytes(3)) . '.part.webm';
    $audio = $job['audio'] === 'done';
    $ok = media_ffmpeg(array_merge(
        ['-f', 'concat', '-safe', '0', '-i', $listFile],
        $audio ? ['-i', $dir . '/audio.mka'] : [],
        ['-map', '0:v:0'],
        $audio ? ['-map', '1:a:0'] : [],
        ['-c', 'copy', '-map_metadata', '-1', '-map_chapters', '-1', $out]
    ));
    @unlink($listFile);
    if (!$ok) {
        return video_job_fail($job, 'job_err_join');
    }

    return video_job_finish($job, $out);
}

/**
 * Zakóduje ďalšiu časť obrazu: snímky [next, next + n) na mriežke výstupnej frekvencie.
 *
 * Koniec videa určuje ffmpeg, nie dĺžka z hlavičky (tá býva nepresná alebo chýba):
 * dobehol bez chyby a dal menej snímok, než sme pýtali → vstup sa skončil. Hocijaké
 * zlyhanie ffmpeg je zlyhanie, nech je kdekoľvek — kus videa za celé vydávať nebudeme.
 */
function video_job_segment(array $job, float $segmentSeconds, array $clean): array
{
    $ffmpeg = media_ffmpeg_binary();
    if ($ffmpeg === null) {
        return video_job_fail($job, 'job_err_encode_start');
    }
    $dir = video_job_dir($job['id']);
    [$num, $den] = $job['rate'];
    $fps      = $num / $den;
    $multiple = video_segment_multiple($num, $den);

    // Koľko snímok: toľko, aby časť trvala ~$segmentSeconds práce. Kým rýchlosť nepoznáme,
    // krátka časť na zmeranie (odhad podľa plochy obrazu; SVT-AV1 je niekoľkonásobne rýchlejší).
    $speed = $job['speed'] > 0
        ? $job['speed']
        : 40.0 * 921600 / max(1, $job['probe']['width'] * $job['probe']['height']) * (($job['encoders'][0] ?? '') === 'libsvtav1' ? 3 : 1) / 2;
    $n = (int) (floor($speed * $segmentSeconds / $multiple) * $multiple);
    // najmenej 2 s a najviac 2 min videa v jednej časti
    $n = max($multiple, (int) ceil($fps * 2 / $multiple) * $multiple, min($n, (int) (floor($fps * 120 / $multiple) * $multiple)));

    $start  = (int) $job['next'];
    $margin = min($start, (int) ceil($fps / 2)); // o pol sekundy skôr: presný rez spraví až filter fps
    $k      = count($job['segments']);
    $tmp    = sprintf('%s/seg-%05d.%s.part.webm', $dir, $k, bin2hex(random_bytes(3)));
    $progress = $tmp . '.txt';

    $run = ['code' => -1, 'output' => ''];
    $elapsed = 0.0;
    foreach ($job['encoders'] as $encoder) {
        @unlink($tmp);
        $t   = microtime(true);
        $run = media_run(array_merge(
            [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y'],
            // -noaccurate_seek: snímky pred rezom nezahadzuje ffmpeg, ale až filter fps — ten si z nich nechá
            // poslednú, a časť sa tak správne začne aj uprostred dlho stojaceho obrazu (prezentácia, záznam obrazovky)
            $start > 0 ? ['-noaccurate_seek', '-ss', sprintf('%.6F', ($start - $margin) * $den / $num)] : [],
            ['-i', $job['source'], '-map', '0:v:0', '-an'],
            $clean,
            // fps: pevná mriežka ukotvená na snímke $start (start_time), -frames:v: presne n snímok
            ['-vf', sprintf('fps=%d/%d:start_time=%.6F:round=near,%s', $num, $den, $margin * $den / $num, media_video_scale())],
            ['-frames:v', (string) $n],
            video_encoder_args($encoder),
            // kľúčová snímka aspoň každých 240 snímok — libaom by inak dal jedinú a posúvanie vo videu by viazlo
            ['-g', '240', '-pix_fmt', 'yuv420p', '-progress', $progress, $tmp]
        ));
        $elapsed = microtime(true) - $t;
        if ($run['code'] === 0) {
            $job['encoders'] = [$encoder]; // osvedčil sa — do konca videa už len on (spojené časti musia mať rovnakú hlavičku)
            break;
        }
        if ($start > 0) {
            break; // uprostred videa sa kodér nemení
        }
    }

    $got = $run['code'] === 0 && preg_match_all('/^frame=\s*(\d+)/m', (string) @file_get_contents($progress), $m) ? (int) end($m[1]) : 0;
    @unlink($progress);

    if ($run['code'] !== 0 || ($got === 0 && $start === 0)) {
        @unlink($tmp);
        error_log('[video] ffmpeg zlyhal na časti ' . $k . ' (' . basename((string) $job['source']) . '): ' . substr($run['output'], -500));

        return $start === 0
            ? video_job_fail($job, 'job_err_encode_start')
            : video_job_fail($job, 'job_err_encode_at', gmdate('H:i:s', (int) ($start / $fps)));
    }

    if ($got > 0) {
        $name = sprintf('seg-%05d-%d-%d.webm', $k, $start, $got); // názov hovorí, čo v časti je
        if (!@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);

            return video_job_fail($job, 'job_err_save');
        }
        $job['segments'][] = ['file' => $name, 'frames' => $got];
        $job['next']       = $start + $got;
        $job['speed']      = $job['speed'] > 0 ? 0.6 * $job['speed'] + 0.4 * $got / max(0.1, $elapsed) : $got / max(0.1, $elapsed);
    } else {
        @unlink($tmp); // ffmpeg dobehol čisto a nedal nič: predošlá časť bola presne posledná
    }
    if ($got < $n) {
        if ($job['total'] > 0 && $job['next'] < 0.9 * $job['total']) {
            error_log(sprintf('[video] úloha %s: vstup sa skončil na snímke %d, hlavička sľubovala asi %d', $job['id'], $job['next'], $job['total']));
        }
        $job['ended'] = true;
        $job['total'] = $job['next'];
    } else {
        $job['total'] = max((int) $job['total'], $job['next'] + 1); // hlavička podhodnotila dĺžku — percentá nech nepreskočia 100
    }

    return $job;
}

/** Hotový súbor presunie na miesto, spraví náhľad, uprace. */
function video_job_finish(array $job, string $out): array
{
    $dir = video_job_dir($job['id']);
    if (is_file($dir . '/cancel')) { // zrušili ju počas posledného kroku — nič nezverejniť, originál nechať
        @unlink($out);

        return $job;
    }

    $target = (string) $job['target'];
    if (!@rename($out, $target)) {
        // iný disk: skopírovať vedľa a premenovať naraz, nech v assets/ nikdy neleží polovičný súbor
        $beside = $target . '.' . bin2hex(random_bytes(3)) . '.part';
        if (!(@copy($out, $beside) && @rename($beside, $target))) {
            @unlink($beside);

            return video_job_fail($job, 'job_err_publish');
        }
        @unlink($out);
    }
    if ($job['base'] !== null) {
        media_video_poster($job['source'], $job['base']);
    }
    if ($job['delete_original']) {
        @unlink($job['source']);
    }
    $job['status'] = 'done';
    $job['file']   = basename($target);
    video_job_sweep($job['id']);

    return $job;
}

/** $key = kľúč z lang.php (job_err_…); text sa skladá až pri zobrazení. */
function video_job_fail(array $job, string $key, string ...$args): array
{
    error_log('[video] úloha ' . $job['id'] . ' (' . basename((string) $job['source']) . ') zlyhala: ' . $key . ($args ? ' ' . implode(' ', $args) : ''));
    $job['status']     = 'failed';
    $job['error_key']  = $key;
    $job['error_args'] = $args;

    return $job;
}

/** Zmaže pracovné súbory úlohy; stav (job.json), zámok a značka zrušenia ostávajú. */
function video_job_sweep(string $id): void
{
    foreach (glob(video_job_dir($id) . '/*') ?: [] as $file) {
        if (!in_array(basename($file), ['job.json', 'lock', 'cancel'], true)) {
            @unlink($file);
        }
    }
}

/**
 * Zruší úlohu aj s rozpracovanými časťami. Originál ostáva (dá sa skonvertovať znova).
 *
 * Keď na úlohe práve beží krok (ffmpeg zapisuje časť), nedá sa zmazať spod neho —
 * ostane značka „cancel": bežiaci krok po sebe uprace sám a nič neuloží ani nezverejní;
 * dovtedy sa úloha nikde neukazuje a nedá sa na nej pokračovať.
 */
function video_job_remove(string $id): void
{
    $dir = video_job_dir($id);
    if (!is_dir($dir)) {
        return;
    }
    @touch($dir . '/cancel');

    $wouldBlock = 0;
    $lock = @fopen($dir . '/lock', 'c');
    $busy = $lock && !flock($lock, LOCK_EX | LOCK_NB, $wouldBlock) && $wouldBlock;
    if ($lock) {
        $busy || flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$busy) {
        video_job_wipe($id);
    }
}

function video_job_wipe(string $id): void
{
    $dir = video_job_dir($id);
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

/** Zlyhanú úlohu vráti do hry: hotové časti ostávajú, pokračuje sa od prvej chýbajúcej. */
function video_job_retry(string $id): ?array
{
    $job = video_job_load($id);
    if ($job !== null && $job['status'] === 'failed') {
        $job['status']     = 'running';
        $job['error_key']  = '';
        $job['error_args'] = [];
        $job['attempts']   = 0;
        if (!$job['encoders']) {
            $job['encoders'] = media_av1_encoders();
        }
        video_job_save($job);
    }

    return $job;
}

/**
 * Všetky úlohy, najnovšie prvé (zrušené nie). Hotové sa ukazujú len krátko,
 * staré upratuje upload_cleanup().
 *
 * @return list<array>
 */
function video_jobs(): array
{
    $jobs = [];
    foreach (glob(video_jobs_root() . '/job-*/job.json') ?: [] as $file) {
        $job = is_file(dirname($file) . '/cancel') ? null : json_decode((string) @file_get_contents($file), true);
        if (is_array($job) && isset($job['id'], $job['status'])) {
            $jobs[] = $job;
        }
    }
    usort($jobs, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);

    return $jobs;
}

/** Popracuje na rozpracovaných úlohách, najstaršia prvá — pre cron.php. Vracia stavy úloh, na ktorých robil. */
function video_jobs_work(float $budget): array
{
    $started = microtime(true);
    $touched = [];
    foreach (array_reverse(video_jobs()) as $job) {
        if ($job['status'] !== 'running') {
            continue;
        }
        $left = $budget - (microtime(true) - $started);
        if ($touched && $left <= 1) {
            break; // čas vypršal — ale aspoň na jednej úlohe sa popracuje vždy
        }
        $state = video_job_run($job['id'], max(0.0, $left));
        if ($state !== null) {
            $touched[] = video_job_public($state);
        }
    }

    return $touched;
}

/**
 * Upratovanie: hotové úlohy po dni; rozpracované a zlyhané až po dvoch týždňoch —
 * zlyhaná na 90 % má hotové časti, o ktoré by bola škoda prísť cez noc.
 */
function video_jobs_cleanup(): void
{
    foreach (glob(video_jobs_root() . '/job-*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_file($dir . '/cancel')) {
            video_job_remove(substr(basename($dir), 4)); // zrušená počas behu kroku; ak už nebeží, zmizne
            continue;
        }
        $job  = json_decode((string) @file_get_contents($dir . '/job.json'), true);
        $age  = time() - (int) @filemtime(is_file($dir . '/job.json') ? $dir . '/job.json' : $dir);
        $keep = is_array($job) && in_array($job['status'] ?? '', ['running', 'failed'], true) ? 14 * 86400 : 86400;
        if ($age > $keep) {
            video_job_wipe(substr(basename($dir), 4));
        }
    }
}

// ── Celé video naraz (testy, diagnostika) ────────────────────────────────────

/**
 * Skonvertuje $source do $target a počká na koniec — tá istá cesta ako pri
 * nahrávaní, len bez časového limitu. $encoders vynúti konkrétny kodér (testy).
 */
function media_video_to_webm(string $source, string $target, ?array $encoders = null, ?float $segmentSeconds = null): bool
{
    $job = video_job_create($source, $target, $encoders !== null ? ['encoders' => $encoders] : []);
    if ($job === null) {
        return false;
    }
    while ($job !== null && $job['status'] === 'running') {
        $job = video_job_run($job['id'], 3600.0, $segmentSeconds);
        if (!empty($job['busy'])) {
            usleep(300000); // na úlohe práve pracuje niekto iný
        }
    }
    $ok = $job !== null && $job['status'] === 'done';
    if ($job !== null) {
        video_job_remove($job['id']);
    }

    return $ok && is_file($target) && filesize($target) > 0;
}

/**
 * Náhľad videa: snímka z 1. sekundy → <základ>.avif v assets/, veľká ako webová verzia.
 *
 * Číta sa z originálu, nie z hotového WebM: originál ffmpeg práve dokázal
 * dekódovať, kým na WebM by potreboval dekodér AV1, ktorý mať nemusí.
 */
function media_video_poster(string $video, string $base): void
{
    $png   = ROOT . '/storage/uploads/' . $base . '-poster.png';
    $frame = ['-map', '0:v:0', '-vf', media_video_scale(), '-frames:v', '1', '-pix_fmt', 'rgb24', $png];
    if (media_ffmpeg(array_merge(['-ss', '1', '-i', $video], $frame))
        || media_ffmpeg(array_merge(['-i', $video], $frame))) {
        media_image_to_avif($png, media_dir() . '/' . $base . '.avif');
        @unlink($png);
    }
}
