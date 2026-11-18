<?php
/**
 * Write or change a welfare claim: type (with its limit left), expense day, what for, amount, receipt details and the
 * receipts (photo from the phone or PDF). Saving keeps a draft; the claim page shows the checks and sends it.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $types @var array<string, array<string, mixed>> $balances @var array<string, mixed>|null $claim
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $today
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
$m = static fn (?int $u): string => $u === null ? '—' : Money::format($u);
$opts = ['' => '—'];
foreach ($types as $ty) {
    $b = $balances[(string) $ty['code']] ?? null;
    $left = $b !== null && $b['limit'] !== null ? ' · ' . $t('welfare.left_short', ['v' => $m($b['remaining'])]) : ($ty['limit_event'] !== null ? ' · ' . $t('welfare.per_claim', ['v' => $m(Money::fromDb($ty['limit_event']))]) : '');
    $opts[(string) $ty['code']] = $ty['code'] . ' · ' . $ty['name_th'] . $left;
}
$action = $claim !== null ? '/welfare/claims/' . $claim['public_id'] : '/welfare/claims';
?>
<?= $ui('page-header', ['title' => $claim !== null ? $t('welfare.edit_title') : $t('welfare.new_title'), 'subtitle' => $t('welfare.form_subtitle'),
    'crumbs' => [[$t('welfare.title'), $url('/welfare')], [$claim !== null ? (string) ($claim['claim_no'] ?? $t('welfare.status.draft')) : $t('welfare.new_title'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($claim !== null && $claim['status'] === 'need_info' && $claim['decision_note'] !== null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('welfare.need_info_title'), 'text' => (string) $claim['decision_note']]) ?>
<?php endif; ?>
<form method="post" action="<?= $e($url($action)) ?>" enctype="multipart/form-data" class="card stack" novalidate><?= $csrf ?>
  <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('welfare.field.type'), 'required' => true, 'value' => $o('type'), 'options' => $opts, 'help' => $t('welfare.type_help'), 'error' => $errors['type'] ?? null]) ?>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'expense_date', 'type' => 'date', 'label' => $t('welfare.field.expense_date'), 'required' => true, 'value' => $o('expense_date'), 'max' => $today, 'help' => $t('welfare.expense_date_help'), 'error' => $errors['expense_date'] ?? null]) ?>
    <?= $ui('field', ['name' => 'amount', 'type' => 'money', 'label' => $t('welfare.field.amount'), 'required' => true, 'value' => $o('amount'), 'help' => $t('welfare.amount_help'), 'error' => $errors['amount'] ?? null]) ?>
  </div>
  <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('welfare.field.description'), 'required' => true, 'value' => $o('description'), 'help' => $t('welfare.description_help'), 'error' => $errors['description'] ?? null]) ?>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'receipt_no', 'label' => $t('welfare.field.receipt_no'), 'value' => $o('receipt_no'), 'help' => $t('welfare.receipt_no_help'), 'error' => $errors['receipt_no'] ?? null]) ?>
    <?= $ui('field', ['name' => 'receipt_from', 'label' => $t('welfare.field.receipt_from'), 'value' => $o('receipt_from'), 'error' => $errors['receipt_from'] ?? null]) ?>
    <?= $ui('field', ['name' => 'payee_name', 'label' => $t('welfare.field.payee_name'), 'value' => $o('payee_name'), 'help' => $t('welfare.payee_help'), 'error' => $errors['payee_name'] ?? null]) ?>
  </div>
  <?= $ui('dropzone', ['name' => 'files[]', 'id' => 'f-files', 'label' => $t('welfare.field.files'), 'multiple' => true, 'accept' => '.pdf,.png,.jpg,.jpeg,.webp,image/*',
      'help' => $t('welfare.files_help', ['n' => (string) \Blue\Modules\Welfare\ClaimService::MAX_FILES]), 'error' => $errors['files'] ?? null]) ?>
  <div class="row">
    <?= $ui('button', ['label' => $t('welfare.save_check'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
    <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url($claim !== null ? '/welfare/claims/' . $claim['public_id'] : '/welfare'), 'variant' => 'ghost']) ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('welfare.privacy_note')) ?></p>
</form>
<?php if ($claim !== null && $claim['files'] !== []): ?>
<section class="card stack-sm" aria-labelledby="wf-files">
  <h2 id="wf-files" class="card__title"><?= $e($t('welfare.files_attached')) ?></h2>
  <ul class="plain-list">
    <?php foreach ($claim['files'] as $f): ?>
    <li class="row"><a href="<?= $e($url('/welfare/claims/' . $claim['public_id'] . '/files/' . $f['id'])) ?>"><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <?= $e((string) $f['filename']) ?></a>
      <form method="post" action="<?= $e($url('/welfare/claims/' . $claim['public_id'] . '/files/' . $f['id'] . '/remove')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('welfare.remove_file'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash']) ?></form></li>
    <?php endforeach; ?>
  </ul>
  <p class="text-sm text-muted"><?= $e($t('welfare.remove_help')) ?></p>
</section>
<?php endif; ?>
