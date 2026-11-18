<?php
/**
 * New request / edit a returned request. The server check (/leave/preview) shows working days and the balance before
 * sending; the same check runs again on submit.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $me @var list<array<string, mixed>> $types @var array<string, array{half: bool, policy: bool, doc: bool}> $rules
 * @var array<string, string> $errors @var array<string, string> $old @var array<string, mixed>|null $req
 */
$loc = (string) ($v['locale'] ?? 'th');
$f = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$options = ['' => '—'];
foreach ($types as $ty) {
    $name = $loc === 'en' && ($ty['name_en'] ?? '') !== '' ? (string) $ty['name_en'] : (string) $ty['name_th'];
    $options[(string) $ty['public_id']] = $name . (($rules[(string) $ty['public_id']]['policy'] ?? false) ? '' : ' — ' . $t('leave.no_policy_short'));
}
$action = $req !== null ? '/leave/r/' . $req['public_id'] : '/leave';
$part = $f('part', 'full');
?>
<?= $ui('page-header', ['title' => (string) $title, 'subtitle' => $t('leave.form_subtitle'),
    'actions' => $ui('button', ['label' => $t('leave.title'), 'href' => $url($req !== null ? '/leave/r/' . $req['public_id'] : '/leave'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<?php if (isset($errors['_form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['_form']]) ?><?php endif; ?>
<?php if ($req !== null): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('leave.returned_title'), 'text' => $t('leave.returned_text')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url($action)) ?>" enctype="multipart/form-data" class="card stack" novalidate
      data-leave-form data-preview="<?= $e($url('/leave/preview')) ?>" data-csrf="<?= $e($v['csrf'] ?? '') ?>" data-request="<?= $e($req['public_id'] ?? '') ?>"
      data-rules="<?= $e((string) json_encode($rules)) ?>">
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('leave.field.type'), 'required' => true, 'value' => $f('type'), 'options' => $options, 'error' => $errors['type'] ?? null]) ?>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'start_date', 'type' => 'date', 'label' => $t('leave.field.start'), 'required' => true, 'value' => $f('start_date'), 'error' => $errors['start_date'] ?? null]) ?>
    <?= $ui('field', ['name' => 'end_date', 'type' => 'date', 'label' => $t('leave.field.end'), 'value' => $f('end_date'), 'error' => $errors['end_date'] ?? null, 'help' => $t('leave.end_help')]) ?>
  </div>
  <fieldset class="field" data-leave-part>
    <legend class="field__label"><?= $e($t('leave.field.part')) ?></legend>
    <div class="segmented">
      <?php foreach (['full', 'am', 'pm'] as $p): ?>
        <input type="radio" id="part-<?= $p ?>" name="part" value="<?= $p ?>"<?= $part === $p ? ' checked' : '' ?>><label for="part-<?= $p ?>"><?= $e($t('leave.part.' . $p)) ?></label>
      <?php endforeach; ?>
    </div>
    <?php if (isset($errors['part'])): ?><p class="field__error"><?= $e($errors['part']) ?></p><?php endif; ?>
    <p class="field__help"><?= $e($t('leave.part_help')) ?></p>
  </fieldset>
  <?= $ui('field', ['name' => 'reason', 'type' => 'textarea', 'label' => $t('leave.field.reason'), 'value' => $f('reason'), 'help' => $t('leave.reason_help')]) ?>
  <div data-leave-doc>
    <?= $ui('field', ['name' => 'document', 'type' => 'file', 'label' => $t('leave.field.document'), 'error' => $errors['document'] ?? null,
        'help' => $t($req !== null && $req['document_pid'] !== null ? 'leave.document_keep' : 'leave.document_help'), 'accept' => '.pdf,.png,.jpg,.jpeg,.webp']) ?>
  </div>
  <div class="leave-preview" data-leave-preview aria-live="polite" hidden>
    <p class="leave-preview__title"><?= $e($t('leave.preview_title')) ?></p>
    <p class="leave-preview__units" data-out="units"></p>
    <p class="text-sm" data-out="days"></p>
    <p class="text-sm" data-out="balance"></p>
    <p class="text-sm leave-preview__warn" data-out="notes"></p>
  </div>
  <div class="row"><?= $ui('button', ['label' => $t($req !== null ? 'leave.resend' : 'leave.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
</form>
<script src="<?= $e($asset('ui/js/leave.js')) ?>" defer></script>
