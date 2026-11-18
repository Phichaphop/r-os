<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var bool $forced @var array<string, string> $errors @var int $min */
?>
<?= $ui('page-header', ['title' => $t('profile.password.title'), 'crumbs' => [[$t('profile.title'), $url('/me')], [$t('profile.password.title'), null]]]) ?>
<?php if ($forced): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('profile.password.forced')]) ?><?php endif; ?>
<section class="card" aria-label="<?= $e($t('profile.password.title')) ?>">
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('auth.pw.rules', ['min' => $min])]) ?>
  <form method="post" action="<?= $e($url('/me/password')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'current_password', 'label' => $t('profile.password.current'), 'type' => 'password', 'required' => true, 'error' => $errors['current_password'] ?? null, 'autocomplete' => 'current-password']) ?>
    <?= $ui('field', ['name' => 'password', 'label' => $t('auth.pw.new'), 'type' => 'password', 'required' => true, 'error' => $errors['password'] ?? null, 'autocomplete' => 'new-password']) ?>
    <?= $ui('field', ['name' => 'password_confirm', 'label' => $t('auth.pw.confirm'), 'type' => 'password', 'required' => true, 'error' => $errors['password_confirm'] ?? null, 'autocomplete' => 'new-password']) ?>
    <div class="row"><?= $ui('button', ['label' => $t('auth.pw.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'lock']) ?></div>
  </form>
</section>
