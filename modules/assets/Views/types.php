<?php
/**
 * Asset types: class (fixed asset, IT, tool, machine, facility, vehicle, low-value tracked item) and code prefix.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $types @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $here @var bool $canView @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$classes = [];
foreach (['fixed', 'it', 'tool', 'machine', 'facility', 'vehicle', 'low_value'] as $c) {
    $classes[$c] = $t('assets.class.' . $c);
}
?>
<?= $ui('page-header', ['title' => $t('assets.types_title'), 'subtitle' => $t('assets.types_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="at-h">
  <h2 id="at-h" class="card__title"><?= $e($t('assets.types')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('assets.types_help')) ?></p>
  <?php foreach ($types as $ty): $tp = (string) $ty['public_id']; $mine = $form === $tp; $tv = $mine ? $old : $ty; ?>
  <form method="post" action="<?= $e($url('/assets/types/' . $tp)) ?>" class="row as-filter" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'name', 'id' => 'tn-' . $tp, 'label' => $t('assets.field.type_name'), 'value' => (string) ($tv['name'] ?? ''), 'error' => $mine ? ($errors['type_name'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'asset_class', 'id' => 'tc-' . $tp, 'type' => 'select', 'label' => $t('assets.field.class'), 'value' => (string) ($tv['asset_class'] ?? ''), 'options' => $classes]) ?>
    <?= $ui('field', ['name' => 'code_prefix', 'id' => 'tp-' . $tp, 'label' => $t('assets.field.code_prefix'), 'value' => (string) ($tv['code_prefix'] ?? ''), 'error' => $mine ? ($errors['code_prefix'] ?? null) : null]) ?>
    <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $ty['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('assets.active')) ?></label>
    <span class="text-sm text-muted"><?= $e($t('assets.n_assets', ['n' => (string) $ty['asset_count']])) ?></span>
    <?= $ui('button', ['label' => $t('assets.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'attrs' => ['aria-label' => $t('assets.save_type', ['name' => (string) $ty['name']])]]) ?>
  </form>
  <?php endforeach; ?>
  <h3 class="text-md"><?= $e($t('assets.add_type')) ?></h3>
  <form method="post" action="<?= $e($url('/assets/types')) ?>" class="row as-filter" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'name', 'id' => 'tn-new', 'label' => $t('assets.field.type_name'), 'value' => $form === 'new' ? (string) ($old['name'] ?? '') : '', 'error' => $form === 'new' ? ($errors['type_name'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'asset_class', 'id' => 'tc-new', 'type' => 'select', 'label' => $t('assets.field.class'), 'value' => $form === 'new' ? (string) ($old['asset_class'] ?? '') : '', 'options' => ['' => $t('assets.pick')] + $classes, 'error' => $form === 'new' ? ($errors['asset_class'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'code_prefix', 'id' => 'tp-new', 'label' => $t('assets.field.code_prefix'), 'value' => $form === 'new' ? (string) ($old['code_prefix'] ?? '') : '', 'help' => $t('assets.prefix_help'), 'error' => $form === 'new' ? ($errors['code_prefix'] ?? null) : null]) ?>
    <?= $ui('button', ['label' => $t('assets.add'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?>
  </form>
</section>
