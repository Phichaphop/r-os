<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var string $next @var bool $expired @var bool $rightsChanged @var bool $resetAvailable @var array<string, string> $errors @var array<string, string> $old */
?>
<div class="stack-sm">
  <h1><?= $e($t('auth.login.title')) ?></h1>
  <p class="text-muted"><?= $e($t('auth.login.intro')) ?></p>
</div>
<?php if ($expired): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t(!empty($rightsChanged) ? 'auth.login.rights_changed' : 'auth.login.expired')]) ?><?php endif; ?>
<?php if (!empty($errors['_form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['_form']]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/login')) ?>" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <input type="hidden" name="next" value="<?= $e($next) ?>">
  <?= $ui('field', ['name' => 'identifier', 'label' => $t('auth.login.identifier'), 'required' => true, 'value' => $old['identifier'] ?? '', 'autocomplete' => 'username', 'autofocus' => empty($old['identifier'])]) ?>
  <?= $ui('field', ['name' => 'password', 'label' => $t('auth.login.password'), 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
  <div class="row">
    <?= $ui('button', ['label' => $t('auth.login.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'key']) ?>
    <span class="spacer"></span>
    <a href="<?= $e($url('/password/forgot')) ?>"><?= $e($t('auth.login.forgot')) ?></a>
  </div>
</form>
