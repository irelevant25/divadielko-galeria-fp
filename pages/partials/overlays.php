<?php
/**
 * Prehliadač fotografií a okno s videom (ovláda static/js/site.js) — na ostrej
 * stránke aj na dočasných stránkach (plagát, ukážka a galéria v „Práve hráme").
 */

declare(strict_types=1);
?>
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="<?= e(t('lb_label')) ?>" hidden>
  <figure class="lightbox__figure">
    <img class="lightbox__img" alt="">
    <figcaption class="lightbox__caption"></figcaption>
    <p class="lightbox__count" aria-live="polite"></p>
  </figure>
  <button type="button" class="lightbox__btn lightbox__close" data-lb="close" aria-label="<?= e(t('lb_close')) ?>">&times;</button>
  <button type="button" class="lightbox__btn lightbox__prev" data-lb="prev" aria-label="<?= e(t('lb_prev')) ?>">&#8249;</button>
  <button type="button" class="lightbox__btn lightbox__next" data-lb="next" aria-label="<?= e(t('lb_next')) ?>">&#8250;</button>
</div>

<!-- Video vo väčšom okne (galéria, ukážka z inscenácie, reportáž): vloží sa až po kliknutí,
     pri zatvorení alebo prepnutí sa zastaví; vo videách z galérie sa dá listovať šípkami -->
<div class="lightbox player" id="player" role="dialog" aria-modal="true" aria-label="<?= e(t('player_label')) ?>" data-error="<?= e(t('player_error')) ?>" hidden>
  <div class="player__frame"></div>
  <p class="lightbox__caption player__title"></p>
  <p class="lightbox__count player__count" aria-live="polite" hidden></p>
  <button type="button" class="lightbox__btn lightbox__close" data-player-close aria-label="<?= e(t('lb_close')) ?>">&times;</button>
  <button type="button" class="lightbox__btn lightbox__prev" data-player-prev aria-label="<?= e(t('player_prev')) ?>">&#8249;</button>
  <button type="button" class="lightbox__btn lightbox__next" data-player-next aria-label="<?= e(t('player_next')) ?>">&#8250;</button>
</div>
