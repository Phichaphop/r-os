<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, array<string, mixed>> $classes @var array{legal_entity: array<int,string>, site: array<int,string>} $options
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$classOpts = [];
foreach ($classes as $code => $c) {
    $classOpts[$code] = $t('document.class.' . $code) . ' — ' . $t('document.classification.' . $c['classification']);
}
$types = [];
foreach ($classes as $c) {
    $types = array_merge($types, $c['types']);
}
$accept = implode(',', array_map(static fn (string $x): string => '.' . ($x === 'jpeg' ? 'jpg,.jpeg' : $x), array_unique($types)));
?>
<?= $ui('page-header', ['title' => $t('document.new.title'), 'subtitle' => $t('document.new.subtitle'),
    'crumbs' => [[$t('document.title'), $url('/documents')], [$t('document.new.title'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/documents/new')) ?>" enctype="multipart/form-data" class="card form-grid" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'class', 'label' => $t('document.field.class'), 'type' => 'select', 'required' => true, 'value' => (string) ($old['class'] ?? ''), 'options' => $classOpts, 'error' => $errors['class'] ?? null, 'help' => $t('document.help.class')]) ?>
  <?= $ui('field', ['name' => 'legal_entity', 'label' => $t('document.field.company'), 'type' => 'select', 'required' => true, 'value' => (string) ($old['legal_entity_id'] ?? ''), 'options' => $options['legal_entity'], 'error' => $errors['legal_entity'] ?? null]) ?>
  <?= $ui('field', ['name' => 'site', 'label' => $t('document.field.site'), 'type' => 'select', 'value' => (string) ($old['site_id'] ?? ''), 'options' => ['' => '—'] + $options['site']]) ?>
  <?= $ui('field', ['name' => 'document_number', 'label' => $t('document.field.number'), 'value' => (string) ($old['document_number'] ?? ''), 'help' => $t('document.help.number')]) ?>
  <?= $ui('field', ['name' => 'title', 'label' => $t('document.field.title'), 'required' => true, 'full' => true, 'value' => (string) ($old['title'] ?? ''), 'error' => $errors['title'] ?? null]) ?>
  <?= $ui('field', ['name' => 'description', 'label' => $t('document.field.description'), 'type' => 'textarea', 'full' => true, 'value' => (string) ($old['description'] ?? '')]) ?>
  <?= $ui('field', ['name' => 'file', 'label' => $t('document.field.file'), 'type' => 'file', 'required' => true, 'full' => true, 'accept' => $accept, 'error' => $errors['file'] ?? null, 'help' => $t('document.help.file')]) ?>
  <div class="row field--full">
    <?= $ui('button', ['label' => $t('document.upload'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload']) ?>
    <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url('/documents'), 'variant' => 'ghost']) ?>
  </div>
  <p class="text-sm text-muted field--full"><?= $e($t('document.help.draft')) ?></p>
</form>
