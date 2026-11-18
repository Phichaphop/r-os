<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $j @var ?string $wf @var bool $canManage @var bool $canApprove @var int $le
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$jstatus = ['draft' => 'draft', 'in_review' => 'pending', 'returned' => 'returned', 'posted' => 'posted', 'reversed' => 'reversed', 'cancelled' => 'cancelled'];
$editable = in_array($j['status'], ['draft', 'returned'], true);
$base = '/finance/journals/' . $j['public_id'];
$actions = '';
if ($canManage && $editable) {
    $actions .= $ui('button', ['label' => $t('common.edit'), 'href' => $url($base . '/edit'), 'icon' => 'pencil']);
}
?>
<?= $ui('page-header', ['title' => $t('finance.journal.title') . ' ' . ($j['journal_no'] ?? ''), 'subtitle' => (string) $j['description'], 'status' => $jstatus[$j['status']] ?? 'draft',
    'crumbs' => [[$t('finance.title'), $url('/finance?le=' . $le)], [$t('finance.journals.title'), $url('/finance/journals?le=' . $le)], [$j['journal_no'] ?? $t('finance.no_number_yet'), null]], 'actions' => $actions]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php if ($j['status'] === 'returned'): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('finance.journal.returned'), 'text' => $t('finance.journal.returned_text')]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="jh">
    <h2 id="jh" class="card__title"><?= $e($t('finance.journal.header')) ?></h2>
    <dl class="dl">
      <dt><?= $e($t('finance.field.journal_no')) ?></dt><dd class="mono"><?= $e($j['journal_no'] ?? $t('finance.no_number_yet')) ?></dd>
      <dt><?= $e($t('finance.field.date')) ?></dt><dd><?= $e((string) $j['journal_date']) ?></dd>
      <dt><?= $e($t('finance.field.status')) ?></dt><dd><?= $ui('badge', ['status' => $jstatus[$j['status']] ?? 'draft', 'label' => $t('finance.jstatus.' . $j['status'])]) ?></dd>
      <dt><?= $e($t('finance.field.source')) ?></dt><dd><?= $e($j['source_module'] === 'manual' ? $t('finance.source.manual') : $j['source_module'] . ' · ' . ($j['source_ref'] ?? '')) ?>
        <?php if ($j['request'] !== null): ?> — <a href="<?= $e($url('/finance/requests/' . $j['request']['public_id'])) ?>"><?= $e($t('finance.request.title')) ?></a><?php endif; ?></dd>
      <?php if ($j['reversal_of'] !== null): ?><dt><?= $e($t('finance.reversal_of')) ?></dt><dd><a class="mono" href="<?= $e($url('/finance/journals/' . $j['reversal_of']['public_id'])) ?>"><?= $e($j['reversal_of']['journal_no']) ?></a></dd><?php endif; ?>
      <?php if ($j['reversed_by'] !== null): ?><dt><?= $e($t('finance.reversed_by')) ?></dt><dd><a class="mono" href="<?= $e($url('/finance/journals/' . $j['reversed_by']['public_id'])) ?>"><?= $e($j['reversed_by']['journal_no'] ?? '') ?></a></dd><?php endif; ?>
      <dt><?= $e($t('finance.field.prepared')) ?></dt><dd><?= $e($j['names']['prepared'] ?? $t('finance.system')) ?></dd>
      <?php if ($j['submitted_at'] !== null): ?><dt><?= $e($t('finance.field.submitted')) ?></dt><dd><?= $e(($j['names']['submitted'] ?? '') . ' · ' . $date($j['submitted_at'])) ?></dd><?php endif; ?>
      <?php if ($j['posted_at'] !== null): ?><dt><?= $e($t('finance.field.approved')) ?></dt><dd><?= $e(($j['names']['approved'] ?? '') . ' · ' . $date($j['posted_at'])) ?></dd><?php endif; ?>
      <?php if ($j['reason'] !== null && $j['reason'] !== ''): ?><dt><?= $e($t('iam.field.reason')) ?></dt><dd><?= $e($j['reason']) ?></dd><?php endif; ?>
    </dl>
    <?php if ($wf !== null): ?><p class="text-sm"><a href="<?= $e($url('/requests/' . $wf)) ?>"><?= $e($t('finance.approval_history')) ?></a></p><?php endif; ?>
  </section>
  <section class="card" aria-labelledby="ja">
    <h2 id="ja" class="card__title"><?= $e($t('finance.journal.next')) ?></h2>
    <?php if ($j['status'] === 'draft' && $canManage): ?>
      <p class="text-sm text-muted"><?= $e($t('finance.journal.submit_help')) ?></p>
      <form method="post" action="<?= $e($url($base . '/submit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('finance.journal.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
    <?php elseif ($j['status'] === 'returned' && $canManage): ?>
      <form method="post" action="<?= $e($url($base . '/resubmit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('finance.journal.resubmit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
    <?php elseif ($j['status'] === 'in_review'): ?>
      <p><?= $e($t('finance.journal.waiting_checker')) ?></p>
      <?php if ($canApprove && $wf !== null): ?><?= $ui('button', ['label' => $t('finance.journal.open_approval'), 'href' => $url('/approvals/' . $wf), 'variant' => 'primary', 'icon' => 'clipboard-check']) ?><?php endif; ?>
    <?php elseif ($j['status'] === 'posted'): ?>
      <p><?= $e($t('finance.journal.posted_note')) ?></p>
      <?php if ($canManage && $j['pending_reversal'] === null): ?>
      <form method="post" action="<?= $e($url($base . '/reverse')) ?>" class="form-grid"><?= $csrf ?>
        <?= $ui('field', ['name' => 'date', 'label' => $t('finance.reverse.date'), 'type' => 'date', 'required' => true, 'value' => substr((string) ($v['now_utc'] ?? ''), 0, 10), 'help' => $t('finance.reverse.date_help')]) ?>
        <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true]) ?>
        <div class="row field--full"><?= $ui('button', ['label' => $t('finance.reverse.prepare'), 'type' => 'submit', 'icon' => 'arrow-back-up']) ?></div>
      </form>
      <?php elseif ($j['pending_reversal'] !== null): ?>
        <p><a href="<?= $e($url('/finance/journals/' . $j['pending_reversal']['public_id'])) ?>"><?= $e($t('finance.reverse.pending')) ?></a></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted"><?= $e($t('finance.journal.no_action')) ?></p>
    <?php endif; ?>
    <?php if ($editable && $canManage): ?>
      <details class="stack-sm"><summary><?= $e($t('finance.journal.cancel')) ?></summary>
        <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="form-grid"><?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true]) ?>
          <div class="row field--full"><?= $ui('button', ['label' => $t('finance.journal.cancel'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
        </form>
      </details>
    <?php endif; ?>
  </section>
</div>
<section class="card" aria-labelledby="jl">
  <h2 id="jl" class="card__title"><?= $e($t('finance.journal.lines')) ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('finance.journal.lines')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('finance.journal.lines')) ?></caption>
      <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('finance.field.account')) ?></th><th scope="col"><?= $e($t('finance.field.description')) ?></th><th scope="col"><?= $e($t('finance.field.site')) ?></th>
        <th scope="col" class="num"><?= $e($t('finance.field.debit')) ?></th><th scope="col" class="num"><?= $e($t('finance.field.credit')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($j['lines'] as $l): $dr = Money::fromDb($l['debit']); $cr = Money::fromDb($l['credit']); ?>
        <tr>
          <td data-label="#" class="num"><?= (int) $l['line_no'] ?></td>
          <td data-label="<?= $e($t('finance.field.account')) ?>"><span class="mono"><?= $e($l['account_code']) ?></span> <?= $e($l['account_name']) ?></td>
          <td data-label="<?= $e($t('finance.field.description')) ?>"><?= $e($l['description'] ?? '') ?><?php if ($l['source_line_type'] !== null): ?> <span class="text-sm text-muted mono"><?= $e($l['source_line_type']) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('finance.field.site')) ?>"><?= $e($l['site_code'] ?? '—') ?></td>
          <td data-label="<?= $e($t('finance.field.debit')) ?>" class="num"><?= $dr > 0 ? $e(Money::format($dr)) : '' ?></td>
          <td data-label="<?= $e($t('finance.field.credit')) ?>" class="num"><?= $cr > 0 ? $e(Money::format($cr)) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th scope="row" colspan="4"><?= $e($t('finance.field.total')) ?> (<?= $e($j['currency_code']) ?>)</th>
        <td class="num"><strong><?= $e(Money::format(Money::fromDb($j['total_debit']))) ?></strong></td><td class="num"><strong><?= $e(Money::format(Money::fromDb($j['total_credit']))) ?></strong></td></tr></tfoot>
    </table>
  </div>
</section>
