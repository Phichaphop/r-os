<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v */
?>
<h1><?= $e($t('update.maintenance.title')) ?></h1>
<p><?= $e($t('update.maintenance.text')) ?></p>
<form method="post" action="<?= $e($url('/logout')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('button', ['label' => $t('nav.logout'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'logout']) ?></form>
