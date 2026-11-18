<?php
/**
 * @var callable $e @var callable $t @var callable $partial @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $popular @var list<string> $categories
 * @var string $q @var string $cat @var bool $canManage
 */
$card = static fn (array $l, bool $compact = false): string => $partial('@links/_card', ['l' => $l, 'compact' => $compact]);
?>
<?= $ui('page-header', ['title' => $t('links.title'), 'subtitle' => $t('links.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('links.manage'), 'href' => $url('/admin/links'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<form method="get" action="<?= $e($url('/links')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => $q, 'placeholder' => $t('links.search_hint')]) ?>
  <?= $ui('field', ['name' => 'cat', 'label' => $t('links.field.category'), 'type' => 'select', 'value' => $cat,
      'options' => ['' => $t('common.all')] + array_combine($categories, array_map(static fn ($c) => $t('links.cat.' . $c), $categories))]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<?php if ($popular !== []): ?>
<section aria-labelledby="pop-h" class="stack-sm">
  <h2 id="pop-h" class="section-title"><?= $e($t('links.popular')) ?></h2>
  <div class="link-grid link-grid--compact"><?php foreach ($popular as $l) { echo $card($l, true); } ?></div>
</section>
<?php endif; ?>
<?php if ($rows === []): ?>
  <?= $ui('states', ['kind' => 'empty', 'icon' => 'external-link', 'title' => $t($q !== '' || $cat !== '' ? 'links.none_found' : 'links.none'),
      'text' => $t($canManage ? 'links.none_admin' : 'links.none_user')]) ?>
<?php else: ?>
  <?php $groups = []; foreach ($rows as $l) { $groups[$l['category']][] = $l; } ?>
  <?php foreach ($groups as $c => $items): ?>
  <section aria-labelledby="cat-<?= $e($c) ?>" class="stack-sm">
    <h2 id="cat-<?= $e($c) ?>" class="section-title"><?= $e($t('links.cat.' . $c)) ?> <span class="text-muted text-sm">(<?= count($items) ?>)</span></h2>
    <div class="link-grid"><?php foreach ($items as $l) { echo $card($l); } ?></div>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
<p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('links.privacy_note')) ?></p>
