<?php
/**
 * Announcement fields (new + edit). Needs $in (values), $errors, $audOptions, $fid (id prefix), $e, $t, $ui.
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $in @var array<string, string> $errors @var string $fid
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions
 */
$audIn = ['sites' => (array) ($in['sites'] ?? []), 'departments' => (array) ($in['departments'] ?? []), 'groups' => (string) ($in['groups'] ?? '')];
$audError = $errors['audience'] ?? null;
$audId = $fid;
$chk = static fn (string $name, string $label, bool $on) => '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
?>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'category', 'id' => $fid . '-cat', 'label' => $t('announcements.field.category'), 'required' => true, 'value' => (string) ($in['category'] ?? ''), 'placeholder' => $t('announcements.category_example'), 'error' => $errors['category'] ?? null]) ?>
  <?= $ui('field', ['name' => 'priority', 'id' => $fid . '-prio', 'type' => 'select', 'label' => $t('announcements.field.priority'), 'value' => (string) ($in['priority'] ?? 'normal'),
      'options' => ['normal' => $t('announcements.priority.normal'), 'important' => $t('announcements.priority.important'), 'critical' => $t('announcements.priority.critical')], 'help' => $t('announcements.priority_help')]) ?>
  <?= $ui('field', ['name' => 'popup_seconds', 'id' => $fid . '-pop', 'type' => 'select', 'label' => $t('announcements.field.popup'), 'value' => (string) ($in['popup_seconds'] ?? '0'),
      'options' => ['0' => $t('announcements.popup_off'), '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10'], 'error' => $errors['popup_seconds'] ?? null]) ?>
</div>
<?= $ui('field', ['name' => 'title', 'id' => $fid . '-title', 'label' => $t('announcements.field.title'), 'required' => true, 'value' => (string) ($in['title'] ?? ''), 'error' => $errors['title'] ?? null]) ?>
<?= $ui('field', ['name' => 'body', 'id' => $fid . '-body', 'type' => 'textarea', 'label' => $t('announcements.field.body'), 'required' => true, 'value' => (string) ($in['body'] ?? ''), 'help' => $t('announcements.body_help'), 'error' => $errors['body'] ?? null]) ?>
<details<?= ($in['title_en'] ?? '') !== '' || isset($errors['title_en']) ? ' open' : '' ?>><summary><?= $e($t('announcements.english')) ?></summary>
  <div class="stack-sm">
    <?= $ui('field', ['name' => 'title_en', 'id' => $fid . '-title-en', 'label' => $t('announcements.field.title_en'), 'value' => (string) ($in['title_en'] ?? ''), 'error' => $errors['title_en'] ?? null]) ?>
    <?= $ui('field', ['name' => 'body_en', 'id' => $fid . '-body-en', 'type' => 'textarea', 'label' => $t('announcements.field.body_en'), 'value' => (string) ($in['body_en'] ?? '')]) ?>
  </div>
</details>
<div class="grid grid--2">
  <div class="field"><label class="field__label" for="<?= $e($fid) ?>-from"><?= $e($t('announcements.field.starts_at')) ?> <span class="field__req"><?= $e($t('form.required_tag')) ?></span></label>
    <input class="input" type="datetime-local" id="<?= $e($fid) ?>-from" name="starts_at" value="<?= $e((string) ($in['starts_at'] ?? '')) ?>" required<?= isset($errors['starts_at']) ? ' aria-invalid="true"' : '' ?>>
    <?php if (isset($errors['starts_at'])): ?><p class="field__error"><?= $e($errors['starts_at']) ?></p><?php endif; ?></div>
  <div class="field"><label class="field__label" for="<?= $e($fid) ?>-to"><?= $e($t('announcements.field.ends_at')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
    <input class="input" type="datetime-local" id="<?= $e($fid) ?>-to" name="ends_at" value="<?= $e((string) ($in['ends_at'] ?? '')) ?>"<?= isset($errors['ends_at']) ? ' aria-invalid="true"' : '' ?>>
    <?php if (isset($errors['ends_at'])): ?><p class="field__error"><?= $e($errors['ends_at']) ?></p><?php endif; ?></div>
</div>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'video_url', 'id' => $fid . '-video', 'label' => $t('announcements.field.video_url'), 'value' => (string) ($in['video_url'] ?? ''), 'placeholder' => 'https://', 'help' => $t('announcements.url_help'), 'error' => $errors['video_url'] ?? null]) ?>
  <?= $ui('field', ['name' => 'link_url', 'id' => $fid . '-link', 'label' => $t('announcements.field.link_url'), 'value' => (string) ($in['link_url'] ?? ''), 'placeholder' => 'https://', 'error' => $errors['link_url'] ?? null]) ?>
</div>
<fieldset class="stack-sm"><legend class="field__label"><?= $e($t('announcements.options')) ?></legend>
  <div class="check-list">
    <?= $chk('pinned', $e($t('announcements.field.pinned')), (string) ($in['pinned'] ?? '0') === '1') ?>
    <?= $chk('requires_ack', $e($t('announcements.field.requires_ack')), (string) ($in['requires_ack'] ?? '0') === '1') ?>
    <?= $chk('send_email', $e($t('announcements.field.send_email')), (string) ($in['send_email'] ?? '0') === '1') ?>
  </div>
</fieldset>
<?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
