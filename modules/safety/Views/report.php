<?php
/**
 * Report an incident (works on a phone). Anonymous when the company turns it on.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $sites
 * @var array{notes: array<int, string>, anonymous_on: bool} $set @var array<string, string> $errors @var array<string, mixed> $old @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$fv = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
$levels = ['' => $t('safety.pick')];
foreach ([1, 2, 3, 4] as $l) {
    $levels[(string) $l] = $t('safety.level.' . $l) . ($set['notes'][$l] !== '' ? ' — ' . $set['notes'][$l] : '');
}
?>
<?= $ui('page-header', ['title' => $t('safety.report_title'), 'subtitle' => $t('safety.report_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?= $ui('alert', ['tone' => 'warning', 'title' => $t('safety.emergency_first')]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/safety/report')) ?>" enctype="multipart/form-data" class="card stack-sm" novalidate><?= $csrf ?>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('safety.field.kind'), 'required' => true, 'value' => $fv('kind'), 'options' => ['' => $t('safety.pick'), 'injury' => $t('safety.kind.injury'), 'damage' => $t('safety.kind.damage'), 'near_miss' => $t('safety.kind.near_miss'), 'hazard' => $t('safety.kind.hazard')], 'help' => $t('safety.kind_help'), 'error' => $errors['kind'] ?? null]) ?>
    <?= $ui('field', ['name' => 'severity', 'type' => 'select', 'label' => $t('safety.field.severity'), 'required' => true, 'value' => $fv('severity'), 'options' => $levels, 'help' => $t('safety.severity_help'), 'error' => $errors['severity'] ?? null]) ?>
  </div>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'occurred_at', 'type' => 'datetime-local', 'label' => $t('safety.field.occurred_at'), 'required' => true, 'value' => $fv('occurred_at'), 'error' => $errors['occurred_at'] ?? null]) ?>
    <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('safety.field.site'), 'value' => $fv('site'), 'options' => ['' => $t('safety.no_site')] + $sites, 'error' => $errors['site'] ?? null]) ?>
  </div>
  <?= $ui('field', ['name' => 'location', 'label' => $t('safety.field.location'), 'required' => true, 'value' => $fv('location'), 'help' => $t('safety.location_help'), 'error' => $errors['location'] ?? null]) ?>
  <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('safety.field.description'), 'required' => true, 'value' => $fv('description'), 'help' => $t('safety.description_help'), 'error' => $errors['description'] ?? null]) ?>
  <?= $ui('field', ['name' => 'immediate_action', 'type' => 'textarea', 'label' => $t('safety.field.immediate_action'), 'value' => $fv('immediate_action'), 'error' => $errors['immediate_action'] ?? null]) ?>
  <?= $ui('field', ['name' => 'people', 'type' => 'textarea', 'label' => $t('safety.field.people'), 'value' => $fv('people'), 'help' => $t('safety.people_help'), 'error' => $errors['people'] ?? null]) ?>
  <div class="field">
    <label class="field__label" for="f-files"><?= $e($t('safety.field.photos')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
    <input class="input" type="file" id="f-files" name="files[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/*" aria-describedby="f-files-help<?= isset($errors['files']) ? ' f-files-err' : '' ?>">
    <p class="field__help" id="f-files-help"><?= $e($t('safety.photos_help')) ?></p>
    <?php if (isset($errors['files'])): ?><p class="field__error" id="f-files-err"><?= $e($errors['files']) ?></p><?php endif; ?>
  </div>
  <?php if ($set['anonymous_on']): ?>
  <fieldset class="stack-sm sf-anon">
    <legend><?= $e($t('safety.anonymous')) ?></legend>
    <label class="check"><input type="checkbox" name="anonymous" value="1"<?= $fv('anonymous') === '1' ? ' checked' : '' ?>> <?= $e($t('safety.field.anonymous')) ?></label>
    <p class="text-sm text-muted"><?= $e($t('safety.anonymous_help')) ?></p>
    <?php if (isset($errors['anonymous'])): ?><p class="field__error"><?= $e($errors['anonymous']) ?></p><?php endif; ?>
  </fieldset>
  <?php endif; ?>
  <div class="row"><?= $ui('button', ['label' => $t('safety.send_report'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
</form>
