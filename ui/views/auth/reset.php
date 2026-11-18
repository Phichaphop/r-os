<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var string $identifier @var array<string, string> $errors @var int $min */
?>
<h1><?= $e($t('auth.reset.title')) ?></h1>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('auth.pw.rules', ['min' => $min])]) ?>
<form method="post" action="<?= $e($url('/password/reset')) ?>" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'identifier', 'label' => $t('auth.login.identifier'), 'required' => true, 'value' => $identifier, 'autocomplete' => 'username']) ?>
  <?= $ui('field', ['name' => 'otp', 'label' => $t('auth.reset.otp'), 'required' => true, 'error' => $errors['otp'] ?? null, 'autocomplete' => 'one-time-code', 'help' => $t('auth.reset.otp_help')]) ?>
  <?= $ui('field', ['name' => 'password', 'label' => $t('auth.pw.new'), 'type' => 'password', 'required' => true, 'error' => $errors['password'] ?? null, 'autocomplete' => 'new-password']) ?>
  <?= $ui('field', ['name' => 'password_confirm', 'label' => $t('auth.pw.confirm'), 'type' => 'password', 'required' => true, 'error' => $errors['password_confirm'] ?? null, 'autocomplete' => 'new-password']) ?>
  <div class="row"><?= $ui('button', ['label' => $t('auth.pw.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'lock']) ?></div>
</form>
