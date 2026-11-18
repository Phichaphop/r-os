<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $errors @var array<string, string> $old @var array<int, string> $companies
 */
?>
<?= $ui('page-header', ['title' => $t('template.new.title'), 'crumbs' => [[$t('template.title'), $url('/templates')], [$t('template.new.title'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/templates/new')) ?>" class="card form-grid" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'code', 'label' => $t('template.field.code'), 'required' => true, 'value' => $old['code'], 'error' => $errors['code'] ?? null, 'help' => $t('template.help.code')]) ?>
  <?= $ui('field', ['name' => 'name', 'label' => $t('template.field.name'), 'required' => true, 'value' => $old['name'], 'error' => $errors['name'] ?? null]) ?>
  <?= $ui('field', ['name' => 'document_class', 'label' => $t('document.field.class'), 'type' => 'select', 'required' => true, 'value' => $old['document_class'], 'error' => $errors['document_class'] ?? null,
      'options' => ['core.letter' => $t('document.class.core.letter'), 'core.confidential' => $t('document.class.core.confidential'), 'core.general' => $t('document.class.core.general')]]) ?>
  <?= $ui('field', ['name' => 'locale', 'label' => $t('template.field.locale'), 'type' => 'select', 'value' => $old['locale'], 'options' => ['th' => 'ไทย', 'en' => 'English']]) ?>
  <?= $ui('field', ['name' => 'legal_entity', 'label' => $t('template.field.company'), 'type' => 'select', 'value' => $old['legal_entity'], 'options' => ['' => $t('template.all_companies')] + $companies]) ?>
  <?= $ui('field', ['name' => 'number_pattern', 'label' => $t('template.field.number_pattern'), 'value' => $old['number_pattern'], 'error' => $errors['number_pattern'] ?? null, 'help' => $t('template.help.number_pattern')]) ?>
  <div class="row field--full"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?>
    <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url('/templates'), 'variant' => 'ghost']) ?></div>
</form>
