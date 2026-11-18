<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var string $purpose @var string $token @var array<string, mixed>|null $user @var array<string, string> $errors @var int $min */
$action = ($purpose === 'activation' ? '/activate/' : '/password/link/') . $token;
?>
<h1><?= $e($t($purpose === 'activation' ? 'auth.activate.title' : 'auth.link_reset.title')) ?></h1>
<?php if ($user === null || !empty($errors['token'])): ?>
  <?= $ui('states', ['kind' => 'error', 'icon' => 'clock', 'title' => $t('auth.error.link_invalid'), 'text' => $t('auth.link.ask_admin'),
      'action' => $ui('button', ['label' => $t('auth.login.title'), 'href' => $url('/login'), 'variant' => 'primary'])]) ?>
<?php else: ?>
  <p><?= $e($t('auth.activate.hello', ['name' => (string) $user['display_name'], 'username' => (string) $user['username']])) ?></p>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('auth.pw.rules', ['min' => $min])]) ?>
  <form method="post" action="<?= $e($url($action)) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="text" name="username" value="<?= $e($user['username']) ?>" autocomplete="username" hidden>
    <?= $ui('field', ['name' => 'password', 'label' => $t('auth.pw.new'), 'type' => 'password', 'required' => true, 'error' => $errors['password'] ?? null, 'autocomplete' => 'new-password']) ?>
    <?= $ui('field', ['name' => 'password_confirm', 'label' => $t('auth.pw.confirm'), 'type' => 'password', 'required' => true, 'error' => $errors['password_confirm'] ?? null, 'autocomplete' => 'new-password']) ?>
    <div class="row"><?= $ui('button', ['label' => $t('auth.pw.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'lock']) ?></div>
  </form>
<?php endif; ?>
