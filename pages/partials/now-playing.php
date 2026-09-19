<?php
/**
 * „Práve hráme" — plagát, údaje inscenácie a termíny. Rovnaký blok je na ostrej
 * stránke (Domov) aj na dočasných stránkach (pripravujeme / údržba).
 *
 * Očakáva $runs, $datesOf, $upcoming (content.php → now_playing), $editor
 * a pomocníkov z partials/program-helpers.php.
 */

declare(strict_types=1);

/**
 * @var array $runs
 * @var array $datesOf
 * @var array $upcoming
 * @var bool  $editor
 */
?>
<?php if ($runs): ?>
    <!-- Práve hráme: položky s inscenáciou z repertoáru a vlastnými termínmi; plagát sa strieda vľavo / vpravo -->
<?php foreach ($runs as $i => $play): $rid = (int) $play['run_id']; $pid = (int) $play['id']; $dates = $datesOf[$rid] ?? []; ?>
<?php if ($i > 0): ?>
    <div class="rule rule--between" aria-hidden="true"><span></span>&#10022;<span></span></div>
<?php endif; ?>
    <article class="feature<?= $i % 2 ? ' feature--flip' : '' ?><?= $play['run_public'] ? '' : ' is-hidden-item' ?> cms-item" aria-labelledby="feature-title-<?= $rid ?>">
      <?= cms_controls('runs', $rid, 'cms-bar--corner') ?>
      <figure class="poster">
<?php if ($banner = $play['poster'] ?: $play['image']): // vlastný plagát, inak obrázok z repertoáru ?>
        <button type="button" class="poster__frame" data-lightbox-single="<?= e(media_url($banner)) ?>" data-caption="<?= e(tr($play, 'title')) ?>" aria-label="<?= e(t('program_poster_open')) ?>">
          <img src="<?= e(media_url($banner)) ?>" alt="<?= e(t('program_poster_alt', tr($play, 'title'))) ?>"<?= $i === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>>
        </button>
<?php else: ?>
        <div class="poster__frame poster__frame--empty" aria-hidden="true">
          <span><?= e(tr($play, 'title')) ?></span>
        </div>
<?php endif; ?>
      </figure>

      <div class="feature__body">
        <?= cms_flags(['hidden' => !$play['run_public']]) ?>
        <p class="eyebrow"><?= e(t('program_now')) ?></p>
        <h2 class="feature__title" id="feature-title-<?= $rid ?>"><?= e(tr($play, 'title')) ?></h2>
        <?= cms_edit_link('productions', $pid, 'cms_edit_production') ?>
<?php if (tr($play, 'subtitle') !== ''): ?>
        <p class="feature__subtitle"><?= e(tr($play, 'subtitle')) ?></p>
<?php endif; ?>
<?php if (($f = $facts($play)) || $price($play) !== ''): ?>
        <ul class="facts">
<?php if ($price($play) !== ''): ?>
          <li><?= e($price($play)) ?></li>
<?php endif; ?>
<?php foreach ($f as $fact): ?>
          <li><?= e($fact) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <div class="prose"><?= paragraphs(tr($play, 'description')) ?></div>
<?php $renderPlayActions($play); ?>

        <h3 class="subhead"><?= e(t('program_this')) ?></h3>
<?php if (tr($play, 'venue') !== ''): // kde sa hrá — pri termíne sa zobrazí len iné miesto; odkaz a mapa len keď sú zadané ?>
        <p class="run-venue">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21s-6.5-6.1-6.5-11A6.5 6.5 0 0 1 18.5 10c0 4.9-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/></svg>
          <span>
            <span class="visually-hidden"><?= e(t('where_label')) ?>: </span><?php if (!empty($play['venue_url'])): ?><a class="run-venue__link" href="<?= e($play['venue_url']) ?>" target="_blank" rel="noopener"><?= e(tr($play, 'venue')) ?></a><?php else: ?><?= e(tr($play, 'venue')) ?><?php endif; ?>

<?php if (!empty($play['venue_map_url'])): ?>
            <br><a class="run-venue__map" href="<?= e($play['venue_map_url']) ?>" target="_blank" rel="noopener"><?= e(t('map_link')) ?></a>
<?php endif; ?>
          </span>
        </p>
<?php endif; ?>
<?php if ($dates): ?>
        <ul class="dates">
<?php foreach ($dates as $pf) { $renderDate($pf); } ?>
        </ul>
<?php else: ?>
        <p class="muted"><?= e(t('program_no_dates')) ?></p>
<?php endif; ?>
        <?= cms_add('performances', 'cms_add_performance', ['run_id' => $rid]) ?>
      </div>
    </article>
<?php endforeach; ?>
    <?= cms_add('runs', 'cms_add_now_playing') ?>
<?php else: ?>
    <div class="feature feature--empty cms-zone">
      <div class="prose prose--center"><?= paragraphs(setting_tr('program_empty')) ?></div>
      <?= cms_settings('program') ?>
    </div>
    <?= cms_add('runs', 'cms_add_now_playing') ?>
<?php endif; ?>

<?php $renderTicketsNote($upcoming !== []); ?>
