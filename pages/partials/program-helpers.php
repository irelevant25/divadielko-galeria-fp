<?php
/**
 * Pomocníci na vykreslenie „Práve hráme" — rovnaké na ostrej stránke (pages/site.php)
 * aj na dočasných stránkach (pages/placeholder.php).
 *
 * Očakáva $editor (bool): prihlásený vidí aj ovládanie úprav.
 */

declare(strict_types=1);

/** @var bool $editor */

// Odohraný termín ostáva v zozname, len sivý — bez slov (čítačke obrazovky to povie skrytý text).
$renderDate = static function (array $pf): void {
    $ts = strtotime((string) $pf['starts_at']);
    ?>
      <li class="date<?= $pf['is_past'] ? ' is-past' : '' ?> cms-item">
        <time class="date__when" datetime="<?= e(date('Y-m-d\TH:i', $ts)) ?>">
          <span class="date__day"><?= e(date('j', $ts)) ?></span>
          <span class="date__month"><?= e(explode(',', t('months_short'))[(int) date('n', $ts) - 1]) ?></span>
<?php if ($pf['is_past']): ?>
          <span class="visually-hidden"><?= e(t('date_past')) ?></span>
<?php endif; ?>
        </time>
        <div class="date__info">
          <p class="date__meta"><span class="date__weekday"><?= e(format_date($pf['starts_at'], 'weekday')) ?></span> <?= e(format_time($pf['starts_at'])) ?></p>
<?php if (tr($pf, 'venue') !== ''): ?>
          <p class="date__venue"><?= e(tr($pf, 'venue')) ?></p>
<?php endif; ?>
<?php if (tr($pf, 'note') !== ''): ?>
          <p class="date__note"><?= e(tr($pf, 'note')) ?></p>
<?php endif; ?>
        </div>
        <?= cms_controls('performances', (int) $pf['id']) ?>
      </li>
<?php
};

// Vstupenky sa predávajú na mieste — pod všetkými termínmi je o tom jedna veta
// (dá sa upraviť aj skryť). Návštevník ju vidí, len keď sú nejaké termíny.
$renderTicketsNote = static function (bool $hasDates) use ($editor): void {
    $note = setting_tr('tickets_note');
    if (!$editor && ($note === '' || !$hasDates)) {
        return;
    }
    ?>
    <div class="tickets-note cms-zone<?= $note === '' ? ' is-hidden-note' : '' ?>">
<?php if ($note !== ''): ?>
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16a1 1 0 0 1 1 1v3a2 2 0 0 0 0 4v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a2 2 0 0 0 0-4V7a1 1 0 0 1 1-1z"/><path d="M15 7.5v2M15 11v2M15 14.5v2"/></svg>
      <p><?= e($note) ?></p>
<?php else: ?>
      <p><?= e(t('tickets_note_hidden')) ?></p>
<?php endif; ?>
      <?= cms_settings('program') ?>
    </div>
<?php
};

// Vstupné sa zobrazuje ako prvý (zvýraznený) štítok, ostatné údaje za ním.
$price = static fn (array $p): string => tr($p, 'price');

// Tlačidlá „Ukážka" (video v okne) a „Galéria" (obrázky s šípkami a počítadlom 3 / 9)
// — zobrazia sa, len keď má inscenácia čo ukázať. V okne s podrobnosťami je galéria
// rovno ako náhľady, takže tam je len „Ukážka".
$renderPlayActions = static function (array $p, bool $withGallery = true): void {
    $trailer = play_trailer($p);
    $images  = $withGallery ? play_images($p) : [];
    if (!$trailer && !$images) {
        return;
    }
    $title = tr($p, 'title');
    ?>
        <div class="play-actions">
<?php if ($trailer): ?>
          <button type="button" class="btn btn--ghost btn--compact" data-player="<?= e(json_encode($trailer, JSON_UNESCAPED_SLASHES)) ?>" data-title="<?= e($title) ?>">
            <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5.5v13l11-6.5z"/></svg>
            <span><?= e(t('play_trailer')) ?></span>
          </button>
<?php endif; ?>
<?php if ($images): ?>
          <button type="button" class="btn btn--ghost btn--compact" data-lightbox-set="<?= e(json_encode(array_map(static fn ($img) => ['src' => media_url($img), 'caption' => $title], $images), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
            <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 16l5-5 4 4 3-3 6 6"/></g><circle fill="currentColor" cx="15.5" cy="9.5" r="1.5"/></svg>
            <span><?= e(t('play_gallery')) ?></span>
            <span class="btn__count"><?= count($images) ?></span>
          </button>
<?php endif; ?>
        </div>
<?php
};

$facts = static function (array $p): array {
    return array_filter([
        tr($p, 'age'),
        tr($p, 'duration'),
        $p['premiere'] ? t('program_premiere', format_date($p['premiere'])) : '',
    ]);
};
