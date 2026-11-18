<?php
/** New project (you become its lead). @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $errors @var array<string, mixed> $old */
?>
<?= $ui('page-header', ['title' => $t('projects.new'), 'subtitle' => $t('projects.new_subtitle'), 'crumbs' => [[$t('projects.title'), $url('/projects')], [$t('projects.new'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/projects/new')) ?>" class="card stack-sm" novalidate><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?php $fill = $old; $ferr = $errors; include __DIR__ . '/_charter.php'; ?>
  <div class="row"><?= $ui('button', ['label' => $t('projects.create'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
</form>
