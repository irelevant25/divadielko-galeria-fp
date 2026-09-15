<?php
/**
 * Ukážkový obsah na vyskúšanie rozloženia (php setup.php --demo).
 * Obrázky kreslí GD — plagáty a „fotky" z javiska. Všetky súbory
 * začínajú na demo-, v administrácii (Súbory) sa dajú zmazať.
 * Nepoužívať na ostrej stránke.
 */

declare(strict_types=1);

function demo_font(): ?string
{
    foreach ([
        'C:/Windows/Fonts/georgiab.ttf', 'C:/Windows/Fonts/georgia.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSerif-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Georgia Bold.ttf',
    ] as $font) {
        if (is_file($font)) {
            return $font;
        }
    }

    return null;
}

/** Vertikálny prechod dvoch farieb. */
function demo_gradient($im, array $top, array $bottom): void
{
    $w = imagesx($im);
    $h = imagesy($im);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / max(1, $h - 1);
        $c = imagecolorallocate($im, (int) ($top[0] + ($bottom[0] - $top[0]) * $t), (int) ($top[1] + ($bottom[1] - $top[1]) * $t), (int) ($top[2] + ($bottom[2] - $top[2]) * $t));
        imageline($im, 0, $y, $w, $y, $c);
    }
}

/** Mäkké svetlo (reflektor). */
function demo_glow($im, int $cx, int $cy, int $r, array $rgb, int $strength = 60): void
{
    imagealphablending($im, true);
    for ($i = $r; $i > 0; $i -= max(1, (int) ($r / 40))) {
        $alpha = 127 - (int) ($strength * (1 - $i / $r) / 3);
        imagefilledellipse($im, $cx, $cy, $i * 2, $i * 2, imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], max(0, min(127, $alpha))));
    }
}

/** Jednoduchá bábka (hlava, klobúk, telo) — rovnaký motív ako na stránke. */
function demo_puppet($im, int $cx, int $cy, float $s, array $coat, array $hat): void
{
    $skin = imagecolorallocate($im, 243, 221, 196);
    $dark = imagecolorallocate($im, 58, 35, 24);
    $gold = imagecolorallocate($im, 215, 164, 74);
    $c = imagecolorallocate($im, ...$coat);
    $h = imagecolorallocate($im, ...$hat);
    $string = imagecolorallocatealpha($im, 246, 236, 224, 80);

    foreach ([-50, 50, -90, 90] as $i => $dx) {
        imageline($im, (int) ($cx + $dx * $s * 1.2), 0, (int) ($cx + $dx * $s * ($i < 2 ? 0.3 : 0.9)), (int) ($cy + ($i < 2 ? -20 : 70) * $s), $string);
    }
    imagefilledpolygon($im, [(int) ($cx - 18 * $s), (int) ($cy - 12 * $s), $cx, (int) ($cy - 75 * $s), (int) ($cx + 18 * $s), (int) ($cy - 12 * $s)], $h);
    imagefilledellipse($im, $cx, (int) ($cy - 78 * $s), (int) (12 * $s), (int) (12 * $s), $gold);
    imagefilledellipse($im, $cx, $cy, (int) (44 * $s), (int) (44 * $s), $skin);
    imagefilledellipse($im, (int) ($cx - 8 * $s), (int) ($cy - 3 * $s), (int) (5 * $s), (int) (5 * $s), $dark);
    imagefilledellipse($im, (int) ($cx + 8 * $s), (int) ($cy - 3 * $s), (int) (5 * $s), (int) (5 * $s), $dark);
    imagearc($im, $cx, (int) ($cy + 4 * $s), (int) (18 * $s), (int) (12 * $s), 20, 160, $dark);
    imagefilledpolygon($im, [(int) ($cx - 26 * $s), (int) ($cy + 30 * $s), (int) ($cx + 26 * $s), (int) ($cy + 30 * $s), (int) ($cx + 38 * $s), (int) ($cy + 120 * $s), (int) ($cx - 38 * $s), (int) ($cy + 120 * $s)], $c);
    imagefilledellipse($im, $cx, (int) ($cy + 28 * $s), (int) (60 * $s), (int) (18 * $s), $gold);
    imagesetthickness($im, max(2, (int) (8 * $s)));
    imageline($im, (int) ($cx - 26 * $s), (int) ($cy + 45 * $s), (int) ($cx - 55 * $s), (int) ($cy + 90 * $s), $skin);
    imageline($im, (int) ($cx + 26 * $s), (int) ($cy + 45 * $s), (int) ($cx + 55 * $s), (int) ($cy + 90 * $s), $skin);
    imageline($im, (int) ($cx - 14 * $s), (int) ($cy + 120 * $s), (int) ($cx - 16 * $s), (int) ($cy + 170 * $s), $skin);
    imageline($im, (int) ($cx + 14 * $s), (int) ($cy + 120 * $s), (int) ($cx + 16 * $s), (int) ($cy + 170 * $s), $skin);
    imagesetthickness($im, 1);
}

function demo_text($im, string $text, int $size, int $y, array $rgb, ?string $font): void
{
    $color = imagecolorallocate($im, ...$rgb);
    if ($font === null) {
        imagestring($im, 5, (int) ((imagesx($im) - strlen($text) * 9) / 2), $y, $text, $color);
        return;
    }
    $box = imagettfbbox($size, 0, $font, $text);
    $x = (int) ((imagesx($im) - ($box[2] - $box[0])) / 2);
    imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
}

/** Uloží GD obrázok ako AVIF do assets/ (cez rovnakú konverziu ako nahrávanie). */
function demo_save($im, string $name): string
{
    $tmp = ROOT . '/storage/uploads/' . $name . '.png';
    imagepng($im, $tmp);
    $file = $name . '.avif';
    if (!media_image_to_avif($tmp, media_dir() . '/' . $file)) {
        $file = $name . '.png';
        copy($tmp, media_dir() . '/' . $file);
    }
    @unlink($tmp);

    return $file;
}

function demo_poster(string $name, string $title, string $sub, array $bg, array $coat, array $hat): string
{
    $font = demo_font();
    $im = imagecreatetruecolor(1240, 1754);
    demo_gradient($im, $bg[0], $bg[1]);
    demo_glow($im, 620, 700, 620, [255, 220, 150], 90);
    demo_puppet($im, 620, 760, 3.2, $coat, $hat);

    $gold = imagecolorallocate($im, 215, 164, 74);
    imagesetthickness($im, 6);
    imagerectangle($im, 40, 40, 1199, 1713, $gold);
    imagesetthickness($im, 2);
    imagerectangle($im, 60, 60, 1179, 1693, $gold);

    demo_text($im, 'DIVADIELKO GALÉRIA UVÁDZA', 30, 170, [236, 208, 160], $font);
    demo_text($im, $title, 96, 1450, [246, 236, 224], $font);
    demo_text($im, $sub, 36, 1540, [215, 164, 74], $font);
    demo_text($im, 'UKÁŽKA · DEMO', 24, 1640, [195, 171, 156], $font);

    return demo_save($im, $name);
}

function demo_scene(string $name, int $w, int $h, array $bg, int $seed): string
{
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    demo_gradient($im, $bg[0], $bg[1]);
    demo_glow($im, (int) ($w * (0.3 + mt_rand(0, 40) / 100)), (int) ($h * 0.4), (int) ($w * 0.45), [255, 215, 150], 100);

    // Opona po stranách
    $curtain = imagecolorallocate($im, 125, 26, 40);
    $curtainDark = imagecolorallocate($im, 90, 16, 28);
    for ($i = 0; $i < 6; $i++) {
        $x = (int) ($i * $w * 0.025);
        imagefilledrectangle($im, $x, 0, $x + (int) ($w * 0.02), $h, $i % 2 ? $curtain : $curtainDark);
        imagefilledrectangle($im, $w - $x - (int) ($w * 0.02), 0, $w - $x, $h, $i % 2 ? $curtain : $curtainDark);
    }
    // Javisko
    imagefilledrectangle($im, 0, (int) ($h * 0.82), $w, $h, imagecolorallocate($im, 70, 44, 30));

    $palette = [[[125, 26, 40], [215, 164, 74]], [[40, 70, 110], [125, 26, 40]], [[60, 100, 60], [215, 164, 74]], [[110, 60, 120], [240, 200, 90]]];
    $count = mt_rand(1, 3);
    for ($i = 0; $i < $count; $i++) {
        [$coat, $hat] = $palette[mt_rand(0, 3)];
        demo_puppet($im, (int) ($w * ($i + 1) / ($count + 1)), (int) ($h * 0.42), $h / 520, $coat, $hat);
    }

    return demo_save($im, $name);
}

function demo_seed(): void
{
    if ((int) db_value('SELECT count(*) FROM productions') > 0) {
        say('Ukážkový obsah preskočený — v databáze už sú inscenácie.');
        return;
    }
    if (!is_dir(ROOT . '/storage/uploads')) {
        mkdir(ROOT . '/storage/uploads', 0775, true);
    }
    if (!is_dir(media_dir())) {
        mkdir(media_dir(), 0775, true);
    }

    say('Kreslím ukážkové obrázky…');
    $posters = [
        demo_poster('demo-plagat-kral-bab', 'Kráľ bábok', 'rozprávka o odvahe', [[40, 12, 20], [12, 5, 9]], [125, 26, 40], [215, 164, 74]),
        demo_poster('demo-plagat-hviezdar', 'Malý hvezdár', 'pre deti od 4 rokov', [[14, 22, 48], [6, 8, 18]], [40, 70, 110], [215, 164, 74]),
        demo_poster('demo-plagat-drak', 'Drak z podkrovia', 'bábková komédia', [[20, 38, 24], [8, 14, 9]], [60, 100, 60], [125, 26, 40]),
    ];

    // Fotky z javiska — do galérie stránky aj ako obrázky a galérie inscenácií.
    $scenes = [];
    for ($i = 1; $i <= 11; $i++) {
        $portrait = $i % 4 === 0;
        $scenes[$i] = demo_scene('demo-foto-' . $i, $portrait ? 1100 : 1600, $portrait ? 1500 : 1067, [[30 + $i * 5, 12, 20 + $i * 3], [10, 5, 9]], $i);
    }

    $pdo = db();
    $pdo->beginTransaction();

    // Repertoár: dve verejné inscenácie, tretiu už nehráme. Každá má obrázok a galériu.
    $prod = static fn (array $row) => (int) db_value(
        'INSERT INTO productions (title_sk, title_en, subtitle_sk, subtitle_en, description_sk, description_en, image, images,
                                  age_sk, age_en, duration_sk, duration_en, premiere, is_public, is_retired, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
        $row
    );
    $p1 = $prod(['Kráľ bábok', 'The Puppet King', 'Rozprávka o odvahe a priateľstve', 'A tale of courage and friendship',
        "Ukážkový text. Keď sa kráľ bábok jedného rána zobudí bez svojej koruny, vydá sa na cestu cez celé javisko — od prachu v zákulisí až po svetlá reflektorov.\n\nNa ceste stretne bábky, na ktoré sa dávno zabudlo, a zistí, že kráľom nerobí koruna, ale srdce.",
        "Sample text. When the Puppet King wakes up one morning without his crown, he sets off across the whole stage — from the dust backstage to the bright spotlights.\n\nOn the way he meets long-forgotten puppets and learns that it is the heart, not the crown, that makes a king.",
        $scenes[1], json_encode([$scenes[1], $scenes[3], $scenes[5], $scenes[8], $scenes[9]]), 'od 4 rokov', 'ages 4+', '50 minút', '50 minutes', '2026-03-14', 't', 'f', 1]);
    $p2 = $prod(['Malý hvezdár', 'The Little Astronomer', 'Pre deti od 4 rokov', 'For children aged 4+',
        'Ukážkový text. Príbeh chlapca, ktorý si z papierovej rúry postaví ďalekohľad a v noci objaví hviezdu, ktorá spadla na strechu.',
        'Sample text. A boy builds a telescope from a paper tube and at night discovers a star that has fallen onto the roof.',
        $scenes[2], json_encode([$scenes[2], $scenes[6]]), 'od 4 rokov', 'ages 4+', '40 minút', '40 minutes', '2024-11-09', 't', 'f', 2]);
    $p3 = $prod(['Drak z podkrovia', 'The Attic Dragon', 'Bábková komédia', 'A puppet comedy',
        'Ukážkový text. V podkroví starého domu býva drak, ktorý sa bojí tmy. Zachrániť ho môžu len deti v hľadisku.',
        'Sample text. An old house has a dragon in the attic who is afraid of the dark. Only the children in the audience can help.',
        $scenes[7], '[]', 'od 3 rokov', 'ages 3+', '45 minút', '45 minutes', '2023-05-20', 't', 't', 3]);

    // Práve hráme: dve položky s vlastným plagátom, vstupným, miestom a termínmi (jeden už odohraný — sivý).
    $run = static fn (int $production, string $poster, string $price, int $sort) => (int) db_value(
        'INSERT INTO runs (production_id, poster, price_sk, price_en, venue_sk, venue_en, is_public, sort)
         VALUES (?, ?, ?, ?, ?, ?, true, ?) RETURNING id',
        [$production, $poster, $price, $price, 'Divadelná sála MsKS', 'MsKS theatre hall', $sort]
    );
    $r1 = $run($p1, $posters[0], '3 €', 1);
    $r2 = $run($p2, $posters[1], '3 €', 2);

    foreach ([[$r1, -12, '16:00'], [$r1, 9, '16:00'], [$r1, 10, '10:00'], [$r1, 23, '16:00'], [$r2, 16, '10:00'], [$r2, 30, '16:00']] as $i => [$rid, $days, $time]) {
        // Jeden termín hosťuje inde — pri ňom sa zobrazí jeho miesto.
        db_exec(
            'INSERT INTO performances (run_id, starts_at, venue_sk, venue_en, note_sk, note_en) VALUES (?, ?, ?, ?, ?, ?)',
            [$rid, date('Y-m-d', strtotime("$days days")) . " $time:00",
             $i === 3 ? 'Kultúrny dom Beckov' : null, $i === 3 ? 'Beckov Culture House' : null,
             $i === 2 ? 'predstavenie pre materské školy' : null, $i === 2 ? 'performance for kindergartens' : null]
        );
    }

    $people = [
        ['Anna Kováčová', 'réžia, bábkoherečka', 'director, puppeteer', 2006],
        ['Peter Horváth', 'bábkoherec, hudba', 'puppeteer, music', 2008],
        ['Mária Šimková', 'bábkoherečka', 'puppeteer', 2012],
        ['Jakub Novák', 'scéna a svetlo', 'set and lighting', 2015],
        ['Zuzana Baláž', 'kostýmy a bábky', 'costumes and puppets', 2010],
        ['Tomáš Varga', 'bábkoherec', 'puppeteer', 2019],
    ];
    foreach ($people as $i => [$name, $roleSk, $roleEn, $since]) {
        db_exec(
            'INSERT INTO members (name, role_sk, role_en, bio_sk, bio_en, since_year, sort) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name . ' (demo)', $roleSk, $roleEn, $i < 3 ? 'Ukážkový text o členovi súboru.' : null, $i < 3 ? 'A sample text about the member.' : null, $since, $i + 1]
        );
    }
    db_exec("INSERT INTO members (name, role_sk, role_en, active, sort) VALUES ('Eva Bývalá (demo)', 'bábkoherečka', 'puppeteer', false, 99)");

    foreach ($scenes as $i => $img) {
        db_exec(
            'INSERT INTO photos (image, caption_sk, caption_en, production_id, sort) VALUES (?, ?, ?, ?, ?)',
            [$img, 'Ukážková fotografia ' . $i, 'Sample photo ' . $i, [$p1, $p2][$i % 2], $i]
        );
    }

    // Big Buck Bunny — oficiálne video Blender Foundation (voľná licencia).
    db_exec("INSERT INTO videos (title_sk, title_en, url, sort) VALUES ('Ukážkové video z YouTube', 'Sample YouTube video', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 1)");
    db_exec("INSERT INTO videos (title_sk, title_en, url, poster, sort) VALUES ('Ukážka odkazu na Instagram', 'Sample Instagram link', 'https://www.instagram.com/divadielko_galeria/p/demo/', ?, 2)", [demo_scene('demo-video-nahlad', 1280, 720, [[40, 20, 50], [10, 5, 12]], 42)]);

    // Celá história — vlastné texty.
    $history = [
        [2006, 'Prvé predstavenie v Galérii', 'First performance at the Gallery', 'Ukážkový text: prvé predstavenie sa hralo v malej sále galérie — odtiaľ aj názov divadielka.', 'Sample text: the first show was played in the small hall of the gallery — hence the name.'],
        [2010, 'Vlastná dielňa na bábky', 'Our own puppet workshop', 'Ukážkový text o dielni.', 'Sample text about the workshop.'],
        [2014, 'Prvé ocenenie na festivale', 'First festival award', 'Ukážkový text o festivale.', 'Sample text about the festival.'],
        [2018, 'Hosťovanie v zahraničí', 'Touring abroad', 'Ukážkový text o hosťovaní.', 'Sample text about touring.'],
        [2021, 'Sté predstavenie', 'The hundredth performance', 'Ukážkový text o jubilejnom predstavení.', 'Sample text about the anniversary show.'],
        [2026, 'Premiéra: Kráľ bábok', 'Premiere: The Puppet King', 'Ukážkový text o najnovšej premiére.', 'Sample text about the latest premiere.'],
    ];
    foreach ($history as [$year, $tsk, $ten, $xsk, $xen]) {
        db_exec('INSERT INTO history (year, title_sk, title_en, text_sk, text_en) VALUES (?, ?, ?, ?, ?)', [$year, $tsk, $ten, $xsk, $xen]);
    }

    // Prehľad po rokoch — staršie roky zadané ručne (novšie sa skladajú z termínov „Práve hráme").
    foreach ([
        [2023, $p3, 'Divadelná sála MsKS, Kultúrny dom Beckov', 'MsKS theatre hall, Beckov Culture House'],
        [2024, $p3, 'Divadelná sála MsKS', 'MsKS theatre hall'],
        [2024, $p2, 'Divadelná sála MsKS, festival Bábkarská Bystrica', 'MsKS theatre hall, Bábkarská Bystrica festival'],
        [2025, $p2, 'Divadelná sála MsKS, materské školy v okrese', 'MsKS theatre hall, kindergartens in the district'],
    ] as [$year, $production, $placeSk, $placeEn]) {
        db_exec('INSERT INTO history_plays (year, production_id, place_sk, place_en) VALUES (?, ?, ?, ?)', [$year, $production, $placeSk, $placeEn]);
    }

    // V médiách: jedna veľká navrchu, ostatné v karuseli.
    $press = [
        ['Bábky, ktoré rozprávajú (demo)', 'Puppets that talk (demo)', 'Mestské noviny', 'article', '-20 days', true,
         "Ukážkový text. Reportáž o tom, ako vzniká nová inscenácia — od prvých skíc bábok cez skúšky až po premiéru.\n\nDeti z hľadiska sa na konci predstavenia môžu s bábkami porozprávať.",
         "Sample text. A report on how a new production comes to life — from the first puppet sketches through rehearsals to the premiere.\n\nAfter the show the children in the audience can talk to the puppets."],
        ['Divadielko v regionálnej televízii (demo)', 'The theatre on regional TV (demo)', 'Regionálna televízia', 'tv', '-75 days', false,
         'Ukážkový text o reportáži z premiéry.', 'Sample text about a report from the premiere.'],
        ['Rozhovor o bábkach v rádiu (demo)', 'A radio interview about puppets (demo)', 'Regionálny rozhlas', 'radio', '-140 days', false,
         'Ukážkový text o rozhovore.', 'Sample text about the interview.'],
        ['Tip na víkend: rozprávka v MsKS (demo)', 'Weekend tip: a fairy tale at MsKS (demo)', 'Web mesta', 'web', '-210 days', false,
         null, null],
    ];
    foreach ($press as $i => [$tsk, $ten, $outlet, $kind, $when, $featured, $xsk, $xen]) {
        db_exec(
            'INSERT INTO press (title_sk, title_en, outlet, kind, published_on, url, image, text_sk, text_en, is_featured, is_public)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)',
            [$tsk, $ten, $outlet, $kind, date('Y-m-d', strtotime($when)), 'https://example.com/demo-' . ($i + 1),
             $i === 0 ? $scenes[4] : null, $xsk, $xen, $featured ? 't' : 'f']
        );
    }

    $pdo->commit();
    say('Ukážkový obsah je hotový (' . count(glob(media_dir() . '/demo-*') ?: []) . ' obrázkov v assets/).');
}
