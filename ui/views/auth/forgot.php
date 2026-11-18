<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var bool $available */
?>
<h1><?= $e($t('auth.forgot.title')) ?></h1>
<?php if (!$available): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('auth.forgot.no_channel_title'), 'text' => $t('auth.forgot.no_channel')]) ?>
  <div class="row"><?= $ui('button', ['label' => $t('common.back'), 'href' => $url('/login'), 'icon' => 'chevron-left']) ?></div>
<?php else: ?>
  <p class="text-muted"><?= $e($t('auth.forgot.intro')) ?></p>
  <form method="post" action="<?= $e($url('/password/forgot')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'identifier', 'label' => $t('auth.login.identifier'), 'required' => true, 'autocomplete' => 'username']) ?>
    <div class="row"><?= $ui('button', ['label' => $t('auth.forgot.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?>
      <span class="spacer"></span><a href="<?= $e($url('/login')) ?>"><?= $e($t('common.back')) ?></a></div>
  </form>
<?php endif; ?>
