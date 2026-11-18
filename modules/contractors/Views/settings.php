<?php
/**
 * Required document types (company or worker; required; expires) and the alert days before expiry — the company's own,
 * no defaults.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $types @var array<string, mixed> $settings @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 * @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$le = '<input type="hidden" name="le" value="' . $e($lePid) . '">';
$applies = ['company' => $t('contractors.applies.company'), 'worker' => $t('contractors.applies.worker')];
?>
<?= $ui('page-header', ['title' => $t('contractors.settings_title'), 'subtitle' => $t('contractors.settings_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="cs-t">
  <h2 id="cs-t" class="card__title"><?= $e($t('contractors.doc_types')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('contractors.doc_types_help')) ?></p>
  <?php foreach ($types as $ty): $tp = (string) $ty['public_id']; $mine = $form === $tp; ?>
  <form method="post" action="<?= $e($url('/contractors/settings/type/' . $tp)) ?>" class="row ct-add" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'name', 'id' => 'tn-' . $tp, 'label' => $t('contractors.field.type_name'), 'value' => $mine ? (string) ($old['name'] ?? '') : (string) $ty['name'], 'error' => $mine ? ($errors['type_name'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'applies_to', 'id' => 'ta-' . $tp, 'type' => 'select', 'label' => $t('contractors.field.applies_to'), 'value' => (string) $ty['applies_to'], 'options' => $applies]) ?>
    <label class="check"><input type="checkbox" name="required" value="1"<?= (int) $ty['required'] === 1 ? ' checked' : '' ?>> <?= $e($t('contractors.field.required')) ?></label>
    <label class="check"><input type="checkbox" name="needs_expiry" value="1"<?= (int) $ty['needs_expiry'] === 1 ? ' checked' : '' ?>> <?= $e($t('contractors.field.needs_expiry')) ?></label>
    <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $ty['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('contractors.active')) ?></label>
    <?= $ui('button', ['label' => $t('contractors.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'attrs' => ['aria-label' => $t('contractors.save_type', ['name' => (string) $ty['name']])]]) ?>
  </form>
  <?php endforeach; ?>
  <h3 class="text-md"><?= $e($t('contractors.add_type')) ?></h3>
  <form method="post" action="<?= $e($url('/contractors/settings/type')) ?>" class="row ct-add" novalidate><?= $csrf . $le ?>
    <?= $ui('field', ['name' => 'name', 'id' => 'tn-new', 'label' => $t('contractors.field.type_name'), 'value' => $form === 'type' ? (string) ($old['name'] ?? '') : '', 'placeholder' => $t('contractors.type_example'), 'error' => $form === 'type' ? ($errors['type_name'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'applies_to', 'id' => 'ta-new', 'type' => 'select', 'label' => $t('contractors.field.applies_to'), 'value' => $form === 'type' ? (string) ($old['applies_to'] ?? '') : '', 'options' => ['' => $t('contractors.pick')] + $applies, 'error' => $form === 'type' ? ($errors['applies_to'] ?? null) : null]) ?>
    <label class="check"><input type="checkbox" name="required" value="1" checked> <?= $e($t('contractors.field.required')) ?></label>
    <label class="check"><input type="checkbox" name="needs_expiry" value="1" checked> <?= $e($t('contractors.field.needs_expiry')) ?></label>
    <?= $ui('button', ['label' => $t('contractors.add'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="cs-a">
  <h2 id="cs-a" class="card__title"><?= $e($t('contractors.alerts')) ?></h2>
  <form method="post" action="<?= $e($url('/contractors/settings/alert')) ?>" class="row ct-add" novalidate><?= $csrf . $le ?>
    <?= $ui('field', ['name' => 'alert_days', 'type' => 'number', 'label' => $t('contractors.field.alert_days'), 'value' => $form === 'alert' ? (string) ($old['alert_days'] ?? '') : (string) ($settings['alert_days'] ?? ''), 'help' => $t('contractors.alert_days_help'), 'error' => $form === 'alert' ? ($errors['alert_days'] ?? null) : null]) ?>
    <?= $ui('button', ['label' => $t('contractors.save'), 'type' => 'submit', 'variant' => 'secondary']) ?>
  </form>
</section>
