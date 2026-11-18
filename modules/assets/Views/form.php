<?php
/**
 * Register a new asset.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $types @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $depts
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canView @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$fv = $old;
$ferr = $errors;
$isNew = true;
?>
<?= $ui('page-header', ['title' => $t('assets.new_title'), 'subtitle' => $t('assets.new_subtitle'), 'crumbs' => [[$t('assets.title'), $url('/assets')], [$t('assets.tab.new'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($types === []): ?>
  <?= $ui('states', ['icon' => 'settings', 'title' => $t('assets.no_types'), 'text' => $t('assets.no_types_help'), 'action' => $ui('button', ['label' => $t('assets.tab.types'), 'href' => $url('/assets/types'), 'variant' => 'primary'])]) ?>
<?php else: ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/assets/new')) ?>" class="card stack" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?php include __DIR__ . '/_fields.php'; ?>
  <div class="row"><?= $ui('button', ['label' => $t('assets.register'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
</form>
<?php endif; ?>
