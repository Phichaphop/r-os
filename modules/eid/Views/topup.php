<?php
/** One top-up batch. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v @var array<string, mixed> $tp */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$cur = (string) $tp['currency_code'];
?>
<?= $ui('page-header', ['title' => $t('eid.topup_title', ['n' => (string) $tp['topup_no']]), 'subtitle' => (string) $tp['le_code'] . ' — ' . $tp['reason'],
    'crumbs' => [[$t('eid.wallet_admin_title'), $url('/eid/wallet/admin')], [$t('eid.topup_title', ['n' => (string) $tp['topup_no']]), null]],
    'actions' => $ui('badge', ['tone' => ['approved' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'rejected' => 'danger'][$tp['status']] ?? 'neutral', 'label' => $t('eid.topup_status.' . $tp['status'])])]) ?>
<?php if ($tp['status'] === 'failed'): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('eid.topup_failed'), 'text' => $t('eid.error.' . (str_starts_with((string) $tp['error_code'], 'eid.error.') ? substr((string) $tp['error_code'], 10) : 'apply'))]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="tp-h">
  <h2 id="tp-h" class="card__title"><?= $e($t('eid.details')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('eid.field.lines')) ?></dt><dd><?= (int) $tp['line_count'] ?></dd></div>
    <div><dt><?= $e($t('eid.field.total')) ?></dt><dd><?= $e($money((string) $tp['total_amount'], $cur)) ?></dd></div>
    <div><dt><?= $e($t('eid.field.by')) ?></dt><dd><?= $e((string) ($tp['created_name'] ?? '—')) ?> · <?= $e(DateFormat::day(substr((string) $tp['created_at'], 0, 10), $loc)) ?></dd></div>
    <div><dt><?= $e($t('eid.field.approved_by')) ?></dt><dd><?= $e((string) ($tp['approved_name'] ?? '—')) ?></dd></div>
    <div><dt><?= $e($t('eid.field.posting')) ?></dt><dd><?= $e($tp['posting_request_pid'] !== null ? $t('eid.posting_sent') : '—') ?></dd></div>
  </dl>
  <?php if ($tp['instance_pid'] !== null): ?><p class="text-sm"><a href="<?= $e($url('/approvals/' . $tp['instance_pid'])) ?>"><?= $e($t('eid.approval_link')) ?></a></p><?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="ln-h">
  <h2 id="ln-h" class="card__title"><?= $e($t('eid.field.lines')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="ln-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('eid.field.employee')) ?></th><th scope="col"><?= $e($t('eid.field.amount')) ?></th><th scope="col"><?= $e($t('eid.field.applied')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($tp['lines'] as $l): ?>
        <tr><th scope="row" data-label="<?= $e($t('eid.field.employee')) ?>"><?= $e($l['employee_code'] . ' · ' . $l['name_th']) ?></th>
          <td data-label="<?= $e($t('eid.field.amount')) ?>" class="num"><?= $e($money((string) $l['amount'], $cur)) ?></td>
          <td data-label="<?= $e($t('eid.field.applied')) ?>"><?= $e($l['entry_id'] !== null ? $t('eid.applied_yes') : $t('eid.applied_no')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
