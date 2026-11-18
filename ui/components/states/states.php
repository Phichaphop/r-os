<?php
/**
 * Empty / error / no-permission state: says WHY + WHAT NEXT (P0-05 §3.1).
 * Props: kind (empty|error|forbidden), icon, title, text, reference (correlation id), action (trusted HTML)
 *
 * @var array<string, mixed> $p
 * @var callable $e
 * @var callable $t
 * @var callable $ui
 */
$kind = $p['kind'] ?? 'empty';
$icon = $p['icon'] ?? ['empty' => 'inbox', 'error' => 'alert-triangle', 'forbidden' => 'lock'][$kind] ?? 'inbox';
?>
<div class="state<?= $kind === 'error' ? ' state--error' : '' ?>"<?= $kind === 'error' ? ' role="alert"' : '' ?>>
  <div class="state__icon"><?= $ui('icon', ['name' => $icon]) ?></div>
  <div class="state__title"><?= $e($p['title'] ?? '') ?></div>
  <?php if (!empty($p['text'])): ?><p class="state__text"><?= $e($p['text']) ?></p><?php endif; ?>
  <?php if (!empty($p['reference'])): ?><div class="state__ref"><?= $e($t('error.reference')) ?>: <?= $e($p['reference']) ?></div><?php endif; ?>
  <?= $p['action'] ?? '' ?>
</div>
