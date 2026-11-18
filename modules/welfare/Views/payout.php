<?php
/**
 * Payout of approved claims (welfare.pay): claims ready to pay with payroll / by bank transfer → a batch; batches and
 * where they stand (payroll run · payment batch). Claims I reviewed, approved or wrote cannot be put in my batch.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array{payroll: list<array<string, mixed>>, payment: list<array<string, mixed>>} $ready
 * @var list<array<string, mixed>> $batches @var string $today @var array<string, string> $errors @var bool $canPayment @var array<string, bool> $tabs @var string $here
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$hidden = '<input type="hidden" name="le" value="' . $e($lePid) . '">';
$md = static fn (mixed $x): string => $x === null ? '—' : Money::format(Money::fromDb($x));
$btones = ['ready' => 'info', 'sent' => 'info', 'paid' => 'success', 'closed' => 'neutral'];
?>
<?= $ui('page-header', ['title' => $t('welfare.payout_title'), 'subtitle' => $t('welfare.payout_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php foreach (['payroll', 'payment'] as $method): $list = $ready[$method]; ?>
<section class="card stack-sm" aria-labelledby="po-<?= $e($method) ?>">
  <h2 id="po-<?= $e($method) ?>" class="card__title"><?= $e($t('welfare.ready_' . $method)) ?> (<?= count($list) ?>)</h2>
  <p class="text-sm text-muted"><?= $e($t('welfare.ready_' . $method . '_help')) ?></p>
  <?php if ($list === []): ?><p class="text-sm text-muted"><?= $e($t('welfare.ready_none')) ?></p><?php else: ?>
  <form method="post" action="<?= $e($url('/welfare/payout/batches')) ?>" class="stack-sm" novalidate><?= $csrf . $hidden ?>
    <input type="hidden" name="method" value="<?= $e($method) ?>">
    <div class="table-wrap" role="region" aria-labelledby="po-<?= $e($method) ?>" tabindex="0">
      <table class="table table--stack">
        <thead><tr><th scope="col"><?= $e($t('welfare.pick')) ?></th><th scope="col"><?= $e($t('welfare.field.claim_no')) ?></th><th scope="col"><?= $e($t('welfare.field.employee')) ?></th>
          <th scope="col"><?= $e($t('welfare.field.type')) ?></th><th scope="col" class="num"><?= $e($t('welfare.field.approved')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($list as $c): $cid = 'pc-' . $c['public_id']; ?>
          <tr><td data-label="<?= $e($t('welfare.pick')) ?>"><?php if ($c['blocked']): ?><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('welfare.not_by_you')]) ?><?php else: ?><input type="checkbox" id="<?= $e($cid) ?>" name="claims[]" value="<?= $e((string) $c['public_id']) ?>" checked><?php endif; ?></td>
            <th scope="row" data-label="<?= $e($t('welfare.field.claim_no')) ?>"><label for="<?= $e($cid) ?>"><?= $e((string) $c['claim_no']) ?></label></th>
            <td data-label="<?= $e($t('welfare.field.employee')) ?>"><?= $e($c['employee_code'] . ' · ' . $c['employee_name']) ?></td>
            <td data-label="<?= $e($t('welfare.field.type')) ?>"><?= $e($c['type_code'] . ($c['component_code'] !== null ? ' → ' . $c['component_code'] : '')) ?></td>
            <td data-label="<?= $e($t('welfare.field.approved')) ?>" class="num"><?= $e($md($c['approved_amount'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="grid grid--2">
      <?php if ($method === 'payment'): ?><?= $ui('field', ['name' => 'pay_date', 'id' => 'f-pay-date', 'type' => 'date', 'label' => $t('welfare.field.pay_date'), 'required' => true, 'min' => $today, 'value' => $today]) ?><?php endif; ?>
      <?= $ui('field', ['name' => 'note', 'id' => 'f-note-' . $method, 'label' => $t('welfare.field.batch_note')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('welfare.make_batch_' . $method), 'type' => 'submit', 'variant' => 'primary', 'icon' => $method === 'payroll' ? 'report-money' : 'cash',
        'disabled' => $method === 'payment' && !$canPayment, 'disabledReason' => $t('welfare.error.no_payment_right')]) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<section class="card card--flush" aria-labelledby="batches">
  <div class="card__head"><h2 id="batches" class="card__title"><?= $e($t('welfare.batches')) ?></h2></div>
  <?php if ($batches === []): ?><div class="card__pad"><p class="text-sm text-muted"><?= $e($t('welfare.batches_none')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="batches" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('welfare.col.batch')) ?></th><th scope="col"><?= $e($t('welfare.field.payout')) ?></th><th scope="col" class="num"><?= $e($t('welfare.col.claims')) ?></th>
        <th scope="col" class="num"><?= $e($t('welfare.col.total')) ?></th><th scope="col"><?= $e($t('welfare.field.status')) ?></th><th scope="col"><?= $e($t('welfare.col.where')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b): ?>
        <tr><th scope="row" data-label="<?= $e($t('welfare.col.batch')) ?>"><?= $e((string) $b['batch_no']) ?><br><span class="text-sm text-muted"><?= $e($date((string) $b['created_at'])) ?> · <?= $e((string) ($b['creator'] ?? '')) ?></span></th>
          <td data-label="<?= $e($t('welfare.field.payout')) ?>"><?= $e($t('welfare.payout.' . $b['method'])) ?></td>
          <td data-label="<?= $e($t('welfare.col.claims')) ?>" class="num"><?= (int) $b['claim_count'] ?></td>
          <td data-label="<?= $e($t('welfare.col.total')) ?>" class="num"><?= $e($md($b['total_amount'])) ?></td>
          <td data-label="<?= $e($t('welfare.field.status')) ?>"><?= $ui('badge', ['tone' => $btones[$b['status']] ?? 'neutral', 'label' => $t('welfare.bstatus.' . $b['status'])]) ?></td>
          <td data-label="<?= $e($t('welfare.col.where')) ?>">
            <?php if ($b['method'] === 'payroll'): ?>
              <?php if ($b['run'] !== null): ?><a href="<?= $e($url('/payroll/runs/' . $b['run']['public_id'])) ?>"><?= $e((string) $b['run']['label']) ?></a><?php else: ?><span class="text-sm text-muted"><?= $e($t('welfare.not_in_run')) ?></span><?php endif; ?>
              <?php if ($b['status'] === 'ready' && $b['run'] === null): ?>
              <details><summary><?= $e($t('welfare.cancel_batch')) ?></summary>
                <form method="post" action="<?= $e($url('/welfare/payout/batches/' . $b['public_id'] . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf . $hidden ?>
                  <?= $ui('field', ['name' => 'reason', 'id' => 'f-bc-' . $b['public_id'], 'label' => $t('welfare.field.reason')]) ?>
                  <?= $ui('button', ['label' => $t('welfare.cancel_batch'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></form>
              </details>
              <?php endif; ?>
            <?php elseif ($b['payment_batch_pid'] !== null): ?>
              <a href="<?= $e($url('/payments/' . $b['payment_batch_pid'])) ?>"><?= $e($t('welfare.open_payment')) ?></a>
            <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
