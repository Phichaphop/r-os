<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $b @var array{rows: list<array<string, mixed>>, total: int} $items @var string $status @var int $page @var int $per
 * @var array<string, bool> $can @var ?string $wf @var list<array<string, mixed>> $formats
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/payments/' . $b['public_id'];
$back = '<input type="hidden" name="_back" value="' . $e($base) . '">';
$st = (string) $b['status'];
$imported = $b['source_type'] === 'import';
$money = static fn ($x): string => Money::format(Money::fromDb($x));
$active = null;
foreach ($b['files'] as $f) {
    if ($f['status'] === 'active') {
        $active = $f;
    }
}
$istatus = ['pending' => ['neutral', 'hourglass'], 'paid' => ['success', 'cash'], 'failed' => ['danger', 'alert-triangle'], 'returned' => ['warning', 'arrow-back-up']];
?>
<?= $ui('page-header', ['title' => $t('payment.batch') . ' ' . $b['batch_no'], 'subtitle' => $t('payment.type.' . $b['payment_type']) . ($b['period_label'] !== null ? ' · ' . $b['period_label'] : ''),
    'crumbs' => [[$t('payment.title'), $url('/payments')], [(string) $b['batch_no'], null]]]) ?>
<?php if ((int) $b['is_demo'] === 1): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('payment.demo_note')]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="bh">
    <h2 id="bh" class="card__title"><?= $e($t('payment.summary')) ?></h2>
    <dl class="dl">
      <dt><?= $e($t('payment.field.status')) ?></dt><dd><?php include __DIR__ . '/_status.php'; ?></dd>
      <dt><?= $e($t('payment.field.pay_date')) ?></dt><dd><?= $e((string) $b['pay_date']) ?></dd>
      <dt><?= $e($t('payment.field.items')) ?></dt><dd><?= (int) $b['item_count'] ?></dd>
      <dt><?= $e($t('payment.field.total')) ?></dt><dd class="display-num"><?= $e($money($b['total_amount'])) ?> <?= $e($b['currency_code']) ?></dd>
      <?php if ($b['expected_total'] !== null): ?><dt><?= $e($t('payment.field.expected_total')) ?></dt><dd><?= $e($money($b['expected_total'])) ?></dd><?php endif; ?>
      <?php if (in_array($st, ['sent', 'reconciled'], true)): ?>
        <dt><?= $e($t('payment.field.paid')) ?></dt><dd><?= $e($money($b['paid_amount'])) ?> · <?= (int) ($b['counts']['paid'] ?? 0) ?> / <?= (int) $b['item_count'] ?></dd>
        <?php if ((int) ($b['counts']['failed'] ?? 0) > 0): ?><dt><?= $e($t('payment.field.failed')) ?></dt><dd class="text-danger"><?= (int) $b['counts']['failed'] ?></dd><?php endif; ?>
      <?php endif; ?>
      <dt><?= $e($t('payment.field.source')) ?></dt><dd><?= $e($t('payment.source.' . $b['source_type'])) ?><?= $b['source_id'] !== null ? ' · ' . $e($b['source_id']) : '' ?></dd>
      <?php if ($b['parent'] !== null): ?><dt><?= $e($t('payment.reissue_of')) ?></dt><dd><a class="mono" href="<?= $e($url('/payments/' . $b['parent']['public_id'])) ?>"><?= $e($b['parent']['batch_no']) ?></a></dd><?php endif; ?>
      <?php foreach ($b['children'] as $c): ?><dt><?= $e($t('payment.reissued_in')) ?></dt><dd><a class="mono" href="<?= $e($url('/payments/' . $c['public_id'])) ?>"><?= $e($c['batch_no']) ?></a> · <?= $e($t('payment.status.' . $c['status'])) ?></dd><?php endforeach; ?>
      <dt><?= $e($t('payment.field.prepared')) ?></dt><dd><?= $e($b['names']['prepared'] ?? '—') ?></dd>
      <?php if ($b['approved_by'] !== null): ?><dt><?= $e($t('payment.field.approved')) ?></dt><dd><?= $e(($b['names']['approved'] ?? '') . ' · ' . $date($b['approved_at'])) ?></dd><?php endif; ?>
      <?php if ($b['sent_by'] !== null): ?><dt><?= $e($t('payment.field.sent')) ?></dt><dd><?= $e(($b['names']['sent'] ?? '') . ' · ' . $date($b['sent_at'])) ?></dd><?php endif; ?>
      <?php if ($b['posting_status'] !== 'none'): ?><dt><?= $e($t('payment.field.posting')) ?></dt><dd><?= $e($t('payment.posting.' . $b['posting_status'])) ?>
        <?php if ($can['finance'] && $b['posting'] !== null): ?> — <a href="<?= $e($url($b['posting']['journal_pid'] !== null ? '/finance/journals/' . $b['posting']['journal_pid'] : '/finance/requests/' . $b['posting']['public_id'])) ?>"><?= $e($b['posting']['journal_no'] ?? $t('finance.request.title')) ?></a><?php endif; ?></dd><?php endif; ?>
      <?php if ($b['note'] !== null): ?><dt><?= $e($t('payment.field.note')) ?></dt><dd><?= $e($b['note']) ?></dd><?php endif; ?>
    </dl>
    <?php if ($wf !== null): ?><p class="text-sm"><a href="<?= $e($url('/requests/' . $wf)) ?>"><?= $e($t('finance.approval_history')) ?></a></p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="ba">
    <h2 id="ba" class="card__title"><?= $e($t('finance.journal.next')) ?></h2>
    <ol class="stepper stepper--compact" aria-label="<?= $e($t('payment.steps')) ?>">
      <?php $order = ['draft' => 1, 'returned' => 1, 'in_review' => 2, 'approved' => 3, 'file_generated' => 4, 'sent' => 5, 'reconciled' => 6]; $now = $order[$st] ?? 0; ?>
      <?php foreach (['prepare', 'approve', 'file', 'upload', 'results'] as $i => $s): $n = $i + 1; ?>
        <li class="stepper__step<?= $n < $now ? ' stepper__step--done' : ($n === $now ? ' stepper__step--current' : '') ?>"><span class="stepper__num"><?= $n ?></span><?= $e($t('payment.step.' . $s)) ?></li>
      <?php endforeach; ?>
    </ol>
    <?php if (in_array($st, ['draft', 'returned'], true) && $can['prepare']): ?>
      <?php if ($imported): ?>
        <p class="text-sm text-muted"><?= $e($t('payment.items_help')) ?></p>
        <div class="row">
          <?= $ui('button', ['label' => $t('payment.import_items'), 'href' => $url('/import/payment.items?batch=' . $b['public_id']), 'icon' => 'upload']) ?>
          <?php if ((int) $b['item_count'] > 0): ?><form method="post" action="<?= $e($url($base . '/clear')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payment.clear_items'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($st === 'draft'): ?>
        <form method="post" action="<?= $e($url($base . '/submit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payment.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'disabled' => (int) $b['item_count'] === 0]) ?></form>
      <?php else: ?>
        <form method="post" action="<?= $e($url($base . '/resubmit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payment.resubmit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
      <?php endif; ?>
    <?php elseif ($st === 'in_review'): ?>
      <p><?= $e($t('payment.waiting_checker')) ?></p>
      <?php if ($can['approve'] && $wf !== null): ?><?= $ui('button', ['label' => $t('finance.journal.open_approval'), 'href' => $url('/approvals/' . $wf), 'variant' => 'primary', 'icon' => 'clipboard-check']) ?><?php endif; ?>
    <?php elseif ($st === 'approved'): ?>
      <?php if ($can['download']): ?>
        <p class="text-sm text-muted"><?= $e($t('payment.generate_help')) ?></p>
        <form method="post" action="<?= $e($url($base . '/generate')) ?>" class="stack-sm"><?= $csrf ?><?= $back ?>
          <?php $opts = ['' => $t('payment.format.standard')]; foreach ($formats as $fm) { $opts[(string) $fm['public_id']] = $fm['code'] . ' v' . $fm['version'] . ' · ' . $fm['name'] . ' (.' . $fm['extension'] . ')'; } ?>
          <?= $ui('field', ['name' => 'format', 'id' => 'gen-format', 'type' => 'select', 'label' => $t('payment.format.pick'), 'options' => $opts, 'value' => '',
              'help' => $formats === [] ? $t('payment.format.none_active') : $t('payment.format.pick_help')]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('payment.generate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'file-text']) ?></div></form>
      <?php else: ?><p><?= $e($t('payment.waiting_file')) ?></p><?php endif; ?>
    <?php elseif ($st === 'file_generated'): ?>
      <?php if ($can['download'] && $active !== null): ?>
        <p class="text-sm text-muted"><?= $e($t('payment.download_help')) ?></p>
        <div class="row">
          <?= $ui('button', ['label' => $t('payment.download'), 'href' => $url($base . '/file'), 'variant' => 'primary', 'icon' => 'download']) ?>
          <form method="post" action="<?= $e($url($base . '/sent')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payment.mark_sent'), 'type' => 'submit', 'icon' => 'send', 'disabled' => (int) $active['download_count'] === 0]) ?></form>
        </div>
        <details class="stack-sm"><summary><?= $e($t('payment.supersede')) ?></summary>
          <form method="post" action="<?= $e($url($base . '/supersede')) ?>" class="form-grid"><?= $csrf ?><?= $back ?>
            <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true, 'help' => $t('payment.supersede_help')]) ?>
            <div class="row field--full"><?= $ui('button', ['label' => $t('payment.supersede'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'refresh']) ?></div>
          </form></details>
      <?php else: ?><p><?= $e($t('payment.waiting_upload')) ?></p><?php endif; ?>
    <?php elseif ($st === 'sent'): ?>
      <p class="text-sm text-muted"><?= $e($t('payment.results_help')) ?></p>
      <?php if ($can['reconcile']): ?><?= $ui('button', ['label' => $t('payment.import_results'), 'href' => $url('/import/payment.results?batch=' . $b['public_id']), 'variant' => 'primary', 'icon' => 'upload']) ?><?php endif; ?>
    <?php elseif ($st === 'reconciled'): ?>
      <p><?= $e($t('payment.reconciled_note')) ?></p>
      <?php if ($can['prepare'] && (int) ($b['counts']['failed_open'] ?? 0) > 0): ?>
        <form method="post" action="<?= $e($url($base . '/reissue')) ?>" class="form-grid"><?= $csrf ?>
          <?= $ui('field', ['name' => 'pay_date', 'label' => $t('payment.reissue_date'), 'type' => 'date', 'required' => true, 'help' => $t('payment.reissue_help', ['n' => (int) $b['counts']['failed_open']])]) ?>
          <div class="row field--full"><?= $ui('button', ['label' => $t('payment.reissue'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'refresh']) ?></div>
        </form>
      <?php endif; ?>
    <?php else: ?><p class="text-muted"><?= $e($t('finance.journal.no_action')) ?></p><?php endif; ?>
    <?php if (in_array($st, ['draft', 'returned', 'approved'], true) && $can['prepare']): ?>
      <details class="stack-sm"><summary><?= $e($t('payment.cancel')) ?></summary>
        <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="form-grid"><?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true]) ?>
          <div class="row field--full"><?= $ui('button', ['label' => $t('payment.cancel'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
        </form></details>
    <?php endif; ?>
  </section>
</div>
<?php if ($b['files'] !== []): ?>
<section class="card" aria-labelledby="bf">
  <h2 id="bf" class="card__title"><?= $e($t('payment.files')) ?></h2>
  <ul class="stack-sm">
  <?php foreach ($b['files'] as $f): ?>
    <li><span class="mono"><?= $e($f['format_code']) ?></span> · <?= $e($date($f['generated_at'])) ?> · <?= $e($f['generated_name'] ?? '') ?> · <?= (int) $f['item_count'] ?> <?= $e($t('payment.field.items')) ?> · <?= $e($money($f['total_amount'])) ?>
      · <?= $e($t('payment.file_status.' . $f['status'])) ?> · <?= $e($t('payment.downloads', ['n' => (int) $f['download_count']])) ?>
      <div class="text-sm text-muted mono break-all">SHA-256 <?= $e($f['sha256']) ?></div>
      <?php if ($f['supersede_reason'] !== null): ?><div class="text-sm"><?= $e($t('iam.field.reason')) ?>: <?= $e($f['supersede_reason']) ?></div><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<section class="card" aria-labelledby="bi">
  <h2 id="bi" class="card__title"><?= $e($t('payment.items')) ?></h2>
  <form method="get" action="<?= $e($url($base)) ?>" class="filter-bar">
    <?= $ui('field', ['name' => 'status', 'label' => $t('payment.field.status'), 'type' => 'select', 'value' => $status,
        'options' => ['' => $t('common.all')] + array_combine(array_keys($istatus), array_map(static fn ($s) => $t('payment.item_status.' . $s), array_keys($istatus)))]) ?>
    <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
  </form>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('payment.items')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('payment.items')) ?></caption>
      <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('payment.field.payee')) ?></th><th scope="col"><?= $e($t('payment.field.site')) ?></th><th scope="col"><?= $e($t('payment.field.bank')) ?></th>
        <th scope="col"><?= $e($t('payment.field.account_no')) ?></th><th scope="col" class="num"><?= $e($t('payment.field.amount')) ?></th><th scope="col"><?= $e($t('payment.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($items['rows'] as $it): [$tone, $icon] = $istatus[$it['status']] ?? ['neutral', 'info-circle']; ?>
        <tr>
          <td data-label="#" class="num"><?= (int) $it['seq'] ?></td>
          <td data-label="<?= $e($t('payment.field.payee')) ?>"><span class="mono"><?= $e($it['payee_code']) ?></span> · <?= $e($it['payee_name']) ?> <span class="text-sm text-muted">(<?= $e($t('payment.payee.' . $it['payee_type'])) ?>)</span></td>
          <td data-label="<?= $e($t('payment.field.site')) ?>"><?= $e($it['site_code'] ?? '—') ?></td>
          <td data-label="<?= $e($t('payment.field.bank')) ?>"><?= $e($it['bank_code']) ?></td>
          <td data-label="<?= $e($t('payment.field.account_no')) ?>"><span class="masked" aria-label="<?= $e($t('payment.masked', ['last4' => (string) $it['account_last4']])) ?>">•••• <?= $e($it['account_last4']) ?></span></td>
          <td data-label="<?= $e($t('payment.field.amount')) ?>" class="num"><?= $e($money($it['amount'])) ?></td>
          <td data-label="<?= $e($t('payment.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('payment.item_status.' . $it['status'])]) ?>
            <?php if ($it['failure_reason'] !== null): ?><div class="text-sm text-danger"><?= $e($it['failure_reason']) ?></div><?php endif; ?>
            <?php if ($it['reissued_pid'] !== null): ?><div class="text-sm"><a class="mono" href="<?= $e($url('/payments/' . $it['reissued_pid'])) ?>">→ <?= $e($it['reissued_no']) ?></a></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($items['rows'] === []): ?><tr><td colspan="7"><?= $ui('states', ['icon' => 'inbox', 'title' => $t('payment.items_empty'), 'text' => $imported ? $t('payment.items_empty_text') : '']) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $ui('pagination', ['path' => $base, 'query' => array_filter(['status' => $status]), 'page' => $page, 'per' => $per, 'total' => $items['total']]) ?>
</section>
