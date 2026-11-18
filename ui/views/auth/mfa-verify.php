<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $errors */
?>
<h1><?= $e($t('auth.mfa.verify_title')) ?></h1>
<p class="text-muted"><?= $e($t('auth.mfa.verify_intro')) ?></p>
<form method="post" action="<?= $e($url('/mfa/verify')) ?>" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'code', 'label' => $t('auth.mfa.code'), 'required' => true, 'error' => $errors['code'] ?? null, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'autofocus' => true, 'placeholder' => '123456']) ?>
  <div class="row"><?= $ui('button', ['label' => $t('auth.mfa.verify'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'shield-check']) ?></div>
</form>
<p class="text-muted text-sm"><?= $e($t('auth.mfa.lost')) ?></p>
<form method="post" action="<?= $e($url('/logout')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('button', ['label' => $t('nav.logout'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'logout']) ?></form>
