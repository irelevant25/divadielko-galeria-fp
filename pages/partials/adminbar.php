<?php
/**
 * Lišta pre prihlásených + skript na úpravy (static/js/cms.js).
 */

declare(strict_types=1);

require_once ROOT . '/includes/cms.php';

$user = current_user();
$mode = site_mode();
?>
<div class="adminbar" role="region" aria-label="<?= e(t('adm_title')) ?>">
  <span class="adminbar__user"><?= e(t('ab_editing', $user['username'])) ?></span>
  <a class="adminbar__mode adminbar__mode--<?= e($mode) ?>" href="/admin.php?tab=settings" title="<?= e(t('mode_' . $mode . '_desc')) ?>"><?= e(t('ab_mode', t('mode_' . $mode))) ?></a>
<?php if ($mode !== 'live'): ?>
  <a class="adminbar__btn" href="/?preview=<?= e($mode) ?>" target="_blank" rel="noopener"><?= e(t('ab_preview')) ?></a>
<?php endif; ?>
  <button type="button" class="adminbar__btn" data-cms-action="toggle-pens" data-hide="<?= e(t('ab_pens_hide')) ?>" data-show="<?= e(t('ab_pens_show')) ?>"><?= e(t('ab_pens_hide')) ?></button>
  <a class="adminbar__btn" href="/admin.php"><?= e(t('ab_admin')) ?></a>
  <form method="post" action="/login.php?action=logout" class="adminbar__form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button type="submit" class="adminbar__btn"><?= e(t('ab_logout')) ?></button>
  </form>
</div>
<?= cms_client_config() ?>
