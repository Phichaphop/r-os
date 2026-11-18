<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $result */
?>
<h1><?= $e($t('backup.restore.title')) ?></h1>
<?php if ($result['ok']): ?>
  <?= $ui('alert', ['tone' => 'success', 'title' => $t('backup.restored.ok'), 'text' => $t('backup.restored.ok_text', ['tables' => (int) ($result['tables'] ?? 0), 'rows' => number_format((int) ($result['rows'] ?? 0)), 'files' => (int) ($result['files'] ?? 0)])]) ?>
<?php else: ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('backup.restored.failed'), 'text' => $t('backup.restored.failed_text') . ' ' . (string) ($result['error'] ?? '')]) ?>
<?php endif; ?>
<p><?= $e($t('backup.restored.signin')) ?></p>
<p><?= $ui('button', ['label' => $t('auth.login.submit'), 'href' => $url('/login'), 'variant' => 'primary']) ?></p>
