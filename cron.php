<?php
/**
 * Plánovač hostingu: popracuje na rozpracovaných konverziách videa
 * (includes/video.php), aby dobehli aj bez otvoreného prehliadača.
 *
 *   https://<web>/cron.php?key=<cron_key z includes/config.local.php>   čo najčastejšie, ideálne každú minútu
 *   php cron.php                                                        z príkazového riadku
 *
 * Kľúč je vlastný (cron_key), nie setup_key: skončí v nastaveniach plánovača
 * a v logoch prístupov, a kľúč k migráciám tam nemá čo robiť.
 *
 * Jedno volanie pracuje najviac upload.video_cron_seconds a na jednej úlohe
 * naraz pracuje len jeden (zámok) — s otvoreným oknom administrácie sa nebije.
 * Z požiadavky neberie nič okrem kľúča; vypisuje len názvy súborov a percentá.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/media.php';

if (PHP_SAPI !== 'cli') {
    private_headers();
    header('Content-Type: text/plain; charset=UTF-8');

    $key = (string) config('cron_key');
    if (strlen($key) < 16 || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("forbidden\n");
    }
}

$states = video_jobs_work((float) config('upload.video_cron_seconds'));
foreach ($states as $state) {
    echo $state['name'], ': ', $state['status'], ' ', round($state['progress'] * 100), " %\n";
}
if (!$states) {
    echo "nothing to do\n";
}
