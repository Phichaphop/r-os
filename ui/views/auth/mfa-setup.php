<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var string $secret @var string $uri @var array<string, string> $errors @var bool $required */
?>
<h1><?= $e($t('auth.mfa.setup_title')) ?></h1>
<?php if ($required): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('auth.mfa.required')]) ?><?php endif; ?>
<ol class="stack-sm">
  <li><?= $e($t('auth.mfa.step1')) ?></li>
  <li><?= $e($t('auth.mfa.step2')) ?>
    <div class="code-box" aria-label="<?= $e($t('auth.mfa.secret')) ?>"><?= $e(trim(chunk_split($secret, 4, ' '))) ?></div>
    <p class="text-muted text-sm"><?= $e($t('auth.mfa.manual_hint')) ?> · <a href="<?= $e($uri) ?>"><?= $e($t('auth.mfa.open_app')) ?></a></p></li>
  <li><?= $e($t('auth.mfa.step3')) ?></li>
</ol>
<form method="post" action="<?= $e($url('/mfa/setup')) ?>" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'code', 'label' => $t('auth.mfa.code'), 'required' => true, 'error' => $errors['code'] ?? null, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'autofocus' => true, 'placeholder' => '123456']) ?>
  <div class="row"><?= $ui('button', ['label' => $t('auth.mfa.confirm'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'shield-check']) ?></div>
</form>
<form method="post" action="<?= $e($url('/logout')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('button', ['label' => $t('nav.logout'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'logout']) ?></form>
