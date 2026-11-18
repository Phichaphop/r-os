<?php
/**
 * Alert — persistent message on the page (errors that need action use role=alert, not toast — P0-05 §3.1).
 * Props: tone (info|success|warning|danger), title, text, html (trusted, pre-escaped), live (bool → role=alert)
 *
 * @var array<string, mixed> $p
 * @var callable $e
 * @var callable $ui
 */
$tone = $p['tone'] ?? 'info';
$icons = ['info' => 'info-circle', 'success' => 'circle-check', 'warning' => 'alert-triangle', 'danger' => 'alert-circle'];
$role = !empty($p['live']) || $tone === 'danger' ? ' role="alert"' : '';
?>
<div class="alert alert--<?= $e($tone) ?>"<?= $role ?>>
  <?= $ui('icon', ['name' => $icons[$tone] ?? 'info-circle']) ?>
  <div class="alert__body">
    <?php if (!empty($p['title'])): ?><div class="alert__title"><?= $e($p['title']) ?></div><?php endif; ?>
    <?php if (!empty($p['text'])): ?><div><?= $e($p['text']) ?></div><?php endif; ?>
    <?= $p['html'] ?? '' ?>
  </div>
</div>
