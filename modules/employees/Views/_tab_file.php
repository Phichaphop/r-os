<?php
/**
 * Personnel file tab (categories the viewer may see): required documents vs company rules, files with verification,
 * expiry, replace / verify / archive, upload. Uses the variables of show.php + $files.
 */
use Blue\Modules\Employees\FileRules;

$types = [];
foreach (FileRules::DOC_TYPES as $dt => $cat) {
    if ($cat === '*' || in_array($cat, $acc['categories'], true)) {
        $types[$dt] = ($cat === '*' ? '' : $cat . ' · ') . $t('employees.doc.' . $dt);
    }
}
$cats = [];
foreach ($acc['categories'] as $c) {
    $cats[$c] = $c . ' · ' . $t('employees.category.' . $c);
}
$methods = ['original' => $t('employees.method.original'), 'certified_copy' => $t('employees.method.certified_copy'), 'issuer_check' => $t('employees.method.issuer_check'),
    'digital' => $t('employees.method.digital')];
$up = $form === 'upload' ? $old : [];
$soon = (int) $noticeDays > 0 ? \Blue\Modules\Employees\EmployeeService::ymd($today)->modify('+' . (int) $noticeDays . ' days')->format('Y-m-d') : '';
?>
<?php if ($completeness !== null): ?>
<section class="card stack-sm" aria-labelledby="ef-req">
  <h2 id="ef-req" class="card__title"><?= $e($t('employees.section.required')) ?></h2>
  <?php if (!$completeness['configured']): ?>
    <?= $ui('alert', ['tone' => 'info', 'title' => $t('employees.completeness_no_rules'), 'text' => $t('employees.completeness_no_rules_help')]) ?>
  <?php elseif ($completeness['required'] === []): ?>
    <p class="text-sm"><?= $e($t('employees.required_none_for_type')) ?></p>
  <?php else: ?>
    <ul class="checklist">
      <?php foreach ($completeness['required'] as $q): ?>
        <li class="<?= $q['state'] === 'ok' ? 'is-ok' : 'is-missing' ?>"><?= $ui('icon', ['name' => $q['state'] === 'ok' ? 'circle-check' : ($q['state'] === 'pending' ? 'hourglass' : 'alert-triangle'), 'size' => 'sm']) ?>
          <?= $e($t('employees.doc.' . $q['type'])) ?> — <?= $e($t('employees.req_state.' . $q['state'])) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card card--flush" aria-labelledby="ef-list">
  <div class="card__head"><h2 id="ef-list" class="card__title"><?= $e($t('employees.section.files')) ?></h2></div>
  <p class="card__pad text-sm text-muted"><?= $e($t('employees.files_help')) ?></p>
  <div class="table-wrap" role="region" aria-labelledby="ef-list" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.category')) ?></th><th scope="col"><?= $e($t('employees.field.doc_type')) ?></th><th scope="col"><?= $e($t('employees.field.expiry_date')) ?></th>
        <th scope="col"><?= $e($t('employees.field.verification')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('employees.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($files as $fi): ?>
        <?php $cur = $fi['status'] === 'current'; ?>
        <tr<?= $cur ? '' : ' class="is-muted"' ?>>
          <td data-label="<?= $e($t('employees.field.category')) ?>"><?= $e($fi['category'] . ' · ' . $t('employees.category.' . $fi['category'])) ?></td>
          <td data-label="<?= $e($t('employees.field.doc_type')) ?>"><a href="<?= $e($url($base . '/files/' . $fi['public_id'])) ?>"><?= $e($t('employees.doc.' . $fi['doc_type'])) ?></a>
            <?php if ($fi['title'] !== null): ?><div class="text-sm text-muted"><?= $e((string) $fi['title']) ?></div><?php endif; ?>
            <?php if (!$cur): ?><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('employees.file_status.' . $fi['status'])]) ?><?php endif; ?>
            <?php if ((int) $fi['employee_visible'] === 1): ?><?= $ui('badge', ['tone' => 'info', 'icon' => 'eye', 'label' => $t('employees.visible_to_employee')]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('employees.field.expiry_date')) ?>"><?= $e($day($fi['expiry_date'] !== null ? (string) $fi['expiry_date'] : null)) ?>
            <?php if ($cur && $fi['expiry_date'] !== null && (string) $fi['expiry_date'] < $today): ?><?= $ui('badge', ['tone' => 'danger', 'label' => $t('employees.expired')]) ?>
            <?php elseif ($cur && $soon !== '' && $fi['expiry_date'] !== null && (string) $fi['expiry_date'] <= $soon): ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('employees.expiring')]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('employees.field.verification')) ?>"><?= $verifyBadge((string) $fi['verify_status']) ?>
            <?php if ($fi['verified_name'] !== null): ?><div class="text-sm text-muted"><?= $e((string) $fi['verified_name']) ?> · <?= $e($t('employees.method.' . $fi['verify_method'])) ?></div><?php endif; ?>
            <?php if ($fi['verify_note'] !== null): ?><div class="text-sm">“<?= $e((string) $fi['verify_note']) ?>”</div><?php endif; ?></td>
          <td>
            <?php if ($cur && $acc['manage']): ?>
            <details>
              <summary class="text-sm"><?= $e($t('employees.file_actions')) ?></summary>
              <form method="post" action="<?= $e($url($base . '/files/' . $fi['public_id'] . '/verify')) ?>" class="stack-sm" novalidate>
                <?= $csrf ?>
                <?= $ui('field', ['name' => 'result', 'id' => 'vr-' . $fi['public_id'], 'type' => 'select', 'label' => $t('employees.verify_result'),
                    'options' => ['verified' => $t('employees.verify.verified'), 'rejected' => $t('employees.verify.rejected')],
                    'error' => ($old['item'] ?? '') === $fi['public_id'] ? ($errors['result'] ?? null) : null]) ?>
                <?= $ui('field', ['name' => 'method', 'id' => 'vm-' . $fi['public_id'], 'type' => 'select', 'label' => $t('employees.verify_method'), 'options' => $methods]) ?>
                <?= $ui('field', ['name' => 'note', 'id' => 'vn-' . $fi['public_id'], 'label' => $t('employees.verify_note'),
                    'error' => ($old['item'] ?? '') === $fi['public_id'] ? ($errors['note'] ?? null) : null]) ?>
                <?= $ui('button', ['label' => $t('employees.save_verification'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'circle-check']) ?>
              </form>
              <form method="post" action="<?= $e($url($base . '/files/' . $fi['public_id'] . '/archive')) ?>" class="stack-sm" novalidate>
                <?= $csrf ?>
                <?= $ui('field', ['name' => 'reason', 'id' => 'ar-' . $fi['public_id'], 'label' => $t('employees.archive_reason'),
                    'error' => ($old['item'] ?? '') === $fi['public_id'] ? ($errors['reason'] ?? null) : null]) ?>
                <?= $ui('button', ['label' => $t('employees.archive'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash']) ?>
              </form>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($files === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'file-text', 'title' => $t('employees.files_none')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($acc['manage'] || $acc['categories'] !== []): ?>
<section class="card stack" aria-labelledby="ef-up">
  <h2 id="ef-up" class="card__title"><?= $e($t('employees.upload_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('employees.upload_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/files')) ?>" enctype="multipart/form-data" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'doc_type', 'type' => 'select', 'label' => $t('employees.field.doc_type'), 'required' => true, 'value' => (string) ($up['doc_type'] ?? ''), 'options' => ['' => '—'] + $types,
          'error' => $errors['doc_type'] ?? null]) ?>
      <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('employees.field.category_other'), 'value' => (string) ($up['category'] ?? ''), 'options' => ['' => '—'] + $cats,
          'error' => $errors['category'] ?? null, 'help' => $t('employees.category_other_help')]) ?>
      <?= $ui('field', ['name' => 'title', 'label' => $t('employees.field.file_title'), 'value' => (string) ($up['title'] ?? ''), 'error' => $errors['title'] ?? null]) ?>
      <?= $ui('field', ['name' => 'expiry_date', 'type' => 'date', 'label' => $t('employees.field.expiry_date'), 'value' => (string) ($up['expiry_date'] ?? ''), 'error' => $errors['expiry_date'] ?? null,
          'help' => $t('employees.expiry_help')]) ?>
      <?= $ui('field', ['name' => 'replaces', 'type' => 'select', 'label' => $t('employees.field.replaces'), 'value' => (string) ($up['replaces'] ?? ''),
          'options' => ['' => $t('employees.replaces_none')] + array_column(array_map(static fn (array $x): array => ['k' => $x['public_id'], 'v' => $t('employees.doc.' . $x['doc_type']) . ($x['title'] !== null ? ' · ' . $x['title'] : '')],
              array_values(array_filter($files, static fn (array $x): bool => $x['status'] === 'current'))), 'v', 'k')]) ?>
    </div>
    <label class="check"><input type="checkbox" name="employee_visible" value="1"<?= ($up['employee_visible'] ?? '') === '1' ? ' checked' : '' ?>> <?= $e($t('employees.employee_visible')) ?></label>
    <?= $ui('dropzone', ['name' => 'file', 'label' => $t('employees.field.file'), 'required' => true, 'accept' => '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx', 'error' => $errors['file'] ?? null,
        'help' => $t('employees.file_types_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('employees.upload'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload']) ?></div>
  </form>
</section>
<?php endif; ?>
